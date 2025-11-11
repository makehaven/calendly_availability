<?php

namespace Drupal\calendly_availability\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Builds utilization and availability stats from the Calendly API.
 */
class CalendlyStatsCollector implements CalendlyStatsCollectorInterface {

  use CalendlyTokenTrait;

  protected ClientInterface $httpClient;
  protected LoggerInterface $logger;
  protected DateFormatterInterface $dateFormatter;
  protected TimeInterface $time;
  protected ConfigFactoryInterface $configFactory;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected \DateTimeZone $timezone;
  protected ?array $blockCategoryOverrides = NULL;

  /**
   * CalendlyStatsCollector constructor.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerInterface $logger,
    DateFormatterInterface $date_formatter,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * {@inheritdoc}
   */
  public function collect(array $options = []): array {
    $config = $this->configFactory->get('calendly_availability.settings');
    $defaultDays = max(1, (int) ($config->get('stats_default_days') ?? 30));
    $availabilityWindow = max(1, (int) ($config->get('stats_availability_window_days') ?? 14));
    $availabilityWindow = (int) ($options['availability_window_days'] ?? $availabilityWindow);
    $suppressAvailability = !empty($options['suppress_availability']);
    if (!empty($options['range_days'])) {
      $defaultDays = max(1, (int) $options['range_days']);
    }

    $this->blockCategoryOverrides = NULL;

    $now = $this->now();
    [$periodStart, $periodEnd] = $this->determinePeriodWindow($now, $options, $defaultDays);
    if ($periodStart > $periodEnd) {
      [$periodStart, $periodEnd] = [$periodEnd, $periodStart];
    }

    $periodDays = max(1, (int) ceil(max(1, $periodEnd->getTimestamp() - $periodStart->getTimestamp()) / 86400));

    $token = $this->getValidAccessToken();
    if (empty($token)) {
      return $this->buildErrorResult('Missing Calendly token. Re-authorize the integration.');
    }

    $headers = [
      'Authorization' => 'Bearer ' . $token,
      'Content-Type' => 'application/json',
    ];

    $currentUser = $this->fetchCurrentUser($headers);
    if (!$currentUser) {
      return $this->buildErrorResult('Unable to load Calendly user profile.');
    }

    $resource = $currentUser['resource'] ?? [];
    $organizationUri = $resource['current_organization'] ?? NULL;
    $userUri = $resource['uri'] ?? NULL;

    $keywordMap = [
      'tour' => $this->buildKeywordList($config->get('stats_tour_keywords') ?? 'tour'),
      'orientation' => $this->buildKeywordList($config->get('stats_orientation_keywords') ?? 'orientation,orient'),
    ];

    $categoryOverrides = $this->buildBlockCategoryOverrides();

    $baseEventTypes = $this->loadEventTypes($headers, $organizationUri, $userUri, $keywordMap);
    $eventTypeCatalog = $baseEventTypes;
    $events = $this->loadScheduledEvents($headers, $organizationUri, $userUri, $periodStart, $periodEnd);

    $totals = [
      'events' => 0,
      'tours' => 0,
      'orientations' => 0,
      'other' => 0,
      'cancellations' => 0,
    ];
    $staff = [];
    $eventTypeStats = [];
    $categoryTotals = [];
    $hourly = array_fill(0, 24, 0);
    $weekday = array_fill_keys(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 0);
    $buckets = array_fill_keys(['morning', 'midday', 'afternoon', 'evening', 'late'], 0);

    foreach ($events as $event) {
      $status = strtolower($event['status'] ?? '');
      if ($status === 'canceled') {
        $totals['cancellations']++;
        continue;
      }

      $start = $this->convertToSiteTimezone($event['start_time'] ?? NULL);
      $end = $this->convertToSiteTimezone($event['end_time'] ?? NULL);
      $durationMinutes = $this->calculateDurationMinutes($start, $end, $event['event_duration'] ?? NULL);
      $eventHour = $start ? (int) $start->format('G') : NULL;

      $eventTypeUri = $event['event_type'] ?? '';
      $eventTypeInfo = $eventTypeCatalog[$eventTypeUri] ?? [];
      $eventName = $event['name'] ?? ($eventTypeInfo['name'] ?? 'Scheduled Event');
      [$categoryKey, $categoryLabel, $canonical] = $this->classifyEvent($eventTypeUri, $eventTypeInfo, $categoryOverrides, $keywordMap);

      $categoryTotals[$categoryKey]['label'] = $categoryLabel;
      $categoryTotals[$categoryKey]['count'] = ($categoryTotals[$categoryKey]['count'] ?? 0) + 1;
      $categoryTotals[$categoryKey]['canonical'] = $canonical;
      $categoryTotals[$categoryKey]['key'] = $categoryKey;

      $totalsKey = match ($canonical) {
        'tour' => 'tours',
        'orientation' => 'orientations',
        default => 'other',
      };
      $totals[$totalsKey] = ($totals[$totalsKey] ?? 0) + 1;
      $totals['events']++;

      if ($eventHour !== NULL) {
        $hourly[$eventHour]++;
        $weekdayKey = $start->format('D');
        if (!isset($weekday[$weekdayKey])) {
          $weekday[$weekdayKey] = 0;
        }
        $weekday[$weekdayKey]++;
        $bucketKey = $this->bucketHour($eventHour);
        $buckets[$bucketKey]++;
      }

      if (isset($eventTypeCatalog[$eventTypeUri])) {
        $eventTypeCatalog[$eventTypeUri]['category'] = $categoryLabel;
      }

      if (!isset($eventTypeStats[$eventTypeUri])) {
        $eventTypeStats[$eventTypeUri] = [
          'uri' => $eventTypeUri,
          'name' => $eventTypeInfo['name'] ?? $eventName,
          'category' => $categoryLabel,
          'count' => 0,
        ];
      }
      $eventTypeStats[$eventTypeUri]['category'] = $categoryLabel;
      $eventTypeStats[$eventTypeUri]['name'] = $eventTypeInfo['name'] ?? $eventName;
      $eventTypeStats[$eventTypeUri]['count']++;

      $staffInfo = $this->extractStaffFromEvent($event, $eventTypeInfo);
      $staffKey = $staffInfo['key'];

      if (!isset($staff[$staffKey])) {
        $staff[$staffKey] = [
          'name' => $staffInfo['name'],
          'email' => $staffInfo['email'],
          'uri' => $staffInfo['uri'],
          'events' => ['tours' => 0, 'orientations' => 0, 'other' => 0],
          'total' => 0,
          'hourly' => array_fill(0, 24, 0),
        ];
      }

      $fieldKey = match ($canonical) {
        'tour' => 'tours',
        'orientation' => 'orientations',
        default => 'other',
      };

      $staff[$staffKey]['events'][$fieldKey]++;
      $staff[$staffKey]['total']++;
      if ($eventHour !== NULL) {
        $staff[$staffKey]['hourly'][$eventHour]++;
      }
    }

    $availabilitySummary = $suppressAvailability
      ? ['enabled' => FALSE]
      : $this->buildAvailabilitySummary($eventTypeCatalog, $headers, $availabilityWindow);

    $availabilityIndex = $this->buildAvailabilityIndex($availabilitySummary);

    foreach ($staff as &$row) {
      $row['popular_hour'] = $this->formatHourLabel($this->determineTopHour($row['hourly']));
      $row['events_per_day'] = round($row['total'] / $periodDays, 2);
      $lookupKey = $row['uri'] ?? ($row['name'] ?? NULL);
      if ($lookupKey && isset($availabilityIndex[$lookupKey])) {
        $row['available_slots'] = $availabilityIndex[$lookupKey]['slots'];
      }
      else {
        $row['available_slots'] = NULL;
      }
      unset($row['hourly']);
    }
    unset($row);

    usort($staff, static function (array $a, array $b) {
      return ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
    });

    usort($eventTypeStats, static function (array $a, array $b) {
      return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
    });

    $topHours = $this->extractTopHours($hourly);

    $leaders = [
      'most_tours' => $this->detectLeader($staff, fn(array $row) => $row['events']['tours'] ?? 0),
      'most_orientations' => $this->detectLeader($staff, fn(array $row) => $row['events']['orientations'] ?? 0),
      'most_available_slots' => $availabilitySummary['leaders']['most_available_slots'] ?? NULL,
    ];

    $categories = array_values(array_map(static function (array $row, string $key) {
      $row['key'] = $key;
      return $row;
    }, $categoryTotals, array_keys($categoryTotals)));
    usort($categories, static function (array $a, array $b) {
      return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
    });
    $categoriesByKey = [];
    foreach ($categories as $row) {
      $categoriesByKey[$row['key']] = $row;
    }

    $stats = [
      'status' => 'ok',
      'generated' => $this->time->getRequestTime(),
      'period' => [
        'label' => $this->dateFormatter->format($periodStart->getTimestamp(), 'custom', 'M j, Y') . ' – ' . $this->dateFormatter->format($periodEnd->getTimestamp(), 'custom', 'M j, Y'),
        'start' => $periodStart->getTimestamp(),
        'end' => $periodEnd->getTimestamp(),
        'days' => $periodDays,
      ],
      'totals' => $totals + ['avg_daily_events' => round($totals['events'] / $periodDays, 2)],
      'staff' => $staff,
      'event_types' => $eventTypeStats,
      'time_distribution' => [
        'hourly' => $hourly,
        'weekday' => $weekday,
        'buckets' => $buckets,
        'top_hours' => $topHours,
      ],
      'availability_window' => $availabilitySummary,
      'leaders' => $leaders,
      'categories' => $categories,
      'categories_by_key' => $categoriesByKey,
      'meta' => [
        'events_considered' => count($events),
        'event_types_evaluated' => count($eventTypeCatalog),
      ],
    ];

    $stats['snapshot'] = $this->buildSnapshotChunk($stats);

    if ($comparisonWindow = $this->determineComparisonWindow($periodStart, $periodEnd)) {
      $stats['comparison'] = $this->buildComparisonSummary(
        $comparisonWindow['start'],
        $comparisonWindow['end'],
        $headers,
        $organizationUri,
        $userUri,
        $baseEventTypes,
        $categoryOverrides,
        $keywordMap
      );
    }

    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSnapshotPayload(?array $stats = NULL): array {
    $stats = $stats ?? $this->collect(['suppress_availability' => TRUE]);
    if (($stats['status'] ?? 'error') !== 'ok') {
      return [];
    }
    return $this->buildSnapshotChunk($stats);
  }

  /**
   * Formats an error payload for consumers.
   */
  protected function buildErrorResult(string $message): array {
    return [
      'status' => 'error',
      'message' => $message,
      'generated' => $this->time->getRequestTime(),
    ];
  }

  /**
   * Normalizes many date formats to DateTimeImmutable.
   */
  protected function normalizeDate($value): ?\DateTimeImmutable {
    if ($value instanceof \DateTimeImmutable) {
      return $value->setTimezone($this->timezone);
    }
    if ($value instanceof \DateTimeInterface) {
      return \DateTimeImmutable::createFromInterface($value)->setTimezone($this->timezone);
    }
    if (is_numeric($value)) {
      return (new \DateTimeImmutable('@' . $value))->setTimezone($this->timezone);
    }
    if (is_string($value) && $value !== '') {
      try {
        return (new \DateTimeImmutable($value, $this->timezone))->setTimezone($this->timezone);
      }
      catch (\Exception $e) {
        $this->logger->warning('Unable to parse date "@value" for Calendly stats: @error', ['@value' => $value, '@error' => $e->getMessage()]);
      }
    }
    return NULL;
  }

  /**
   * Returns the current time as DateTimeImmutable.
   */
  protected function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable())->setTimestamp($this->time->getRequestTime())->setTimezone($this->timezone);
  }

  /**
   * Pulls the current user profile to learn org + owner info.
   */
  protected function fetchCurrentUser(array $headers): ?array {
    try {
      $resp = $this->httpClient->get('https://api.calendly.com/users/me', ['headers' => $headers]);
      return json_decode($resp->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      $this->logger->error('Failed to load Calendly user profile: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Fetches event types for the organization/user and categorizes them.
   */
  protected function loadEventTypes(array $headers, ?string $organizationUri, ?string $userUri, array $keywordMap): array {
    $catalog = [];
    $baseUrl = 'https://api.calendly.com/event_types';
    $query = [
      'active' => 'true',
      'count' => 100,
      'sort' => 'name:asc',
    ];
    if ($organizationUri) {
      $query['organization'] = $organizationUri;
    }
    elseif ($userUri) {
      $query['user'] = $userUri;
    }

    try {
      $response = $this->httpClient->get($baseUrl, ['headers' => $headers, 'query' => $query]);
      $payload = json_decode($response->getBody()->getContents(), TRUE);
      foreach ($payload['collection'] ?? [] as $eventType) {
        if (empty($eventType['uri'])) {
          continue;
        }
        $name = $eventType['name'] ?? 'Event Type';
        $slug = $eventType['slug'] ?? $name;
        $catalog[$eventType['uri']] = [
          'uri' => $eventType['uri'],
          'name' => $name,
          'slug' => $slug,
          'category' => $this->categorizeByKeywords($name, $slug, $keywordMap),
          'owner' => $this->extractOwnerFromEventType($eventType),
        ];
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Failed to load Calendly event types: @msg', ['@msg' => $e->getMessage()]);
    }

    return $catalog;
  }

  /**
   * Fetches scheduled events within the window.
   */
  protected function loadScheduledEvents(array $headers, ?string $organizationUri, ?string $userUri, \DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $events = [];
    $endpoint = 'https://api.calendly.com/scheduled_events';
    $query = [
      'count' => 100,
      'sort' => 'start_time:asc',
      'min_start_time' => $start->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
      'max_start_time' => $end->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
    ];
    if ($organizationUri) {
      $query['organization'] = $organizationUri;
    }
    elseif ($userUri) {
      $query['user'] = $userUri;
    }

    do {
      try {
        $resp = $this->httpClient->get($endpoint, ['headers' => $headers, 'query' => $query]);
        $payload = json_decode($resp->getBody()->getContents(), TRUE);
        $events = array_merge($events, $payload['collection'] ?? []);
        $query['page_token'] = $payload['pagination']['next_page_token'] ?? NULL;
      }
      catch (RequestException $e) {
        $this->logger->error('Failed to load Calendly scheduled events: @msg', ['@msg' => $e->getMessage()]);
        break;
      }
    } while (!empty($query['page_token']));

    return $events;
  }

  /**
   * Splits comma separated keywords into an array.
   */
  protected function buildKeywordList(?string $keywords): array {
    $values = array_filter(array_map(static function ($value) {
      return strtolower(trim($value));
    }, explode(',', (string) $keywords)), static function ($value) {
      return $value !== '';
    });
    return array_values(array_unique($values));
  }

  /**
   * Applies keyword rules to determine an event-type category.
   */
  protected function categorizeByKeywords(string $label, string $slug, array $keywordMap): string {
    $haystack = strtolower($label . ' ' . $slug);
    foreach ($keywordMap['tour'] ?? [] as $needle) {
      if ($needle && str_contains($haystack, $needle)) {
        return 'tour';
      }
    }
    foreach ($keywordMap['orientation'] ?? [] as $needle) {
      if ($needle && str_contains($haystack, $needle)) {
        return 'orientation';
      }
    }
    return 'other';
  }

  /**
   * Converts API timestamps to the site timezone.
   */
  protected function convertToSiteTimezone(?string $value): ?\DateTimeImmutable {
    if (empty($value)) {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($value))->setTimezone($this->timezone);
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to parse Calendly timestamp "@value": @error', ['@value' => $value, '@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Calculates duration in minutes, falling back to provided length.
   */
  protected function calculateDurationMinutes(?\DateTimeImmutable $start, ?\DateTimeImmutable $end, $fallback): ?float {
    if ($start && $end) {
      return round(($end->getTimestamp() - $start->getTimestamp()) / 60, 1);
    }
    if (is_numeric($fallback)) {
      return (float) $fallback;
    }
    return NULL;
  }

  /**
   * Derives the staff owner from an event.
   */
  protected function extractStaffFromEvent(array $event, array $eventTypeInfo): array {
    $membership = $event['event_memberships'][0] ?? [];
    $uri = $membership['user'] ?? $membership['user_uri'] ?? ($eventTypeInfo['owner']['uri'] ?? '');
    $name = $membership['user_name'] ?? $membership['name'] ?? $eventTypeInfo['owner']['name'] ?? 'Unassigned';
    $email = $membership['user_email'] ?? '';
    $key = $uri ?: ($name ? strtolower($name) : '');
    if ($key === '' || $key === NULL) {
      $key = 'unassigned';
    }

    return [
      'key' => $key,
      'name' => $name ?: 'Unassigned',
      'email' => $email,
      'uri' => $uri,
    ];
  }

  /**
   * Extracts owner info from an event type payload.
   */
  protected function extractOwnerFromEventType(array $eventType): array {
    if (!empty($eventType['profile']['name'])) {
      return [
        'name' => $eventType['profile']['name'],
        'uri' => $eventType['profile']['owner'] ?? ($eventType['profile']['user'] ?? NULL),
      ];
    }
    if (!empty($eventType['owner']['name'])) {
      return [
        'name' => $eventType['owner']['name'],
        'uri' => $eventType['owner']['uri'] ?? NULL,
      ];
    }
    return [
      'name' => $eventType['name'] ?? 'Unassigned',
      'uri' => NULL,
    ];
  }

  /**
   * Assigns an hour bucket label.
   */
  protected function bucketHour(int $hour): string {
    if ($hour >= 6 && $hour < 11) {
      return 'morning';
    }
    if ($hour >= 11 && $hour < 14) {
      return 'midday';
    }
    if ($hour >= 14 && $hour < 18) {
      return 'afternoon';
    }
    if ($hour >= 18 && $hour < 22) {
      return 'evening';
    }
    return 'late';
  }

  /**
   * Formats a chart-friendly hour label.
   */
  protected function formatHourLabel(?int $hour): ?string {
    if ($hour === NULL) {
      return NULL;
    }
    return $this->dateFormatter->format(strtotime("today {$hour}:00"), 'custom', 'g a');
  }

  /**
   * Returns the top hour key from counts.
   */
  protected function determineTopHour(array $hourly): ?int {
    $topHour = NULL;
    foreach ($hourly as $hour => $count) {
      if ($count <= 0) {
        continue;
      }
      if ($topHour === NULL || $count > ($hourly[$topHour] ?? 0)) {
        $topHour = (int) $hour;
      }
    }
    return $topHour;
  }

  /**
   * Returns an ordered list of the most popular hours.
   */
  protected function extractTopHours(array $hourly): array {
    $sorted = $hourly;
    arsort($sorted);
    $top = [];
    foreach ($sorted as $hour => $count) {
      if ($count <= 0) {
        continue;
      }
      $top[] = [
        'hour' => (int) $hour,
        'label' => $this->formatHourLabel((int) $hour),
        'value' => $count,
      ];
      if (count($top) === 5) {
        break;
      }
    }
    return $top;
  }

  /**
   * Builds availability stats for the upcoming window.
   */
  protected function buildAvailabilitySummary(array $eventTypeCatalog, array $headers, int $windowDays): array {
    $windowDays = max(1, min(60, $windowDays));
    $windowStart = $this->now();
    $windowEnd = $windowStart->add(new \DateInterval('P' . $windowDays . 'D'));
    $summary = [
      'enabled' => TRUE,
      'days' => $windowDays,
      'total_slots' => 0,
      'staff' => [],
      'leaders' => [],
    ];

    foreach ($eventTypeCatalog as $eventType) {
      $slotCount = $this->countAvailableSlotsForEventType($eventType['uri'], $headers, $windowStart, $windowEnd);
      if ($slotCount <= 0) {
        continue;
      }

      $ownerKey = $eventType['owner']['uri'] ?? $eventType['owner']['name'] ?? $eventType['uri'];
      if (!isset($summary['staff'][$ownerKey])) {
        $summary['staff'][$ownerKey] = [
          'name' => $eventType['owner']['name'] ?? 'Unassigned',
          'uri' => $eventType['owner']['uri'] ?? NULL,
          'slots' => 0,
          'event_types' => [],
        ];
      }

      $summary['staff'][$ownerKey]['slots'] += $slotCount;
      $summary['staff'][$ownerKey]['event_types'][$eventType['name']] = ($summary['staff'][$ownerKey]['event_types'][$eventType['name']] ?? 0) + $slotCount;
      $summary['total_slots'] += $slotCount;
    }

    $summary['staff'] = array_values(array_map(static function (array $row) {
      arsort($row['event_types']);
      return $row;
    }, $summary['staff']));

    usort($summary['staff'], static function (array $a, array $b) {
      return ($b['slots'] ?? 0) <=> ($a['slots'] ?? 0);
    });

    $summary['leaders']['most_available_slots'] = $summary['staff'][0] ?? NULL;

    return $summary;
  }

  /**
   * Counts open slots for a given event type over the window.
   */
  protected function countAvailableSlotsForEventType(string $eventTypeUri, array $headers, \DateTimeImmutable $start, \DateTimeImmutable $end): int {
    $endpoint = 'https://api.calendly.com/event_type_available_times';
    $query = [
      'event_type' => $eventTypeUri,
      'count' => 100,
      'start_time' => $start->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
      'end_time' => $end->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
    ];
    $total = 0;

    do {
      try {
        $resp = $this->httpClient->get($endpoint, ['headers' => $headers, 'query' => $query]);
        $payload = json_decode($resp->getBody()->getContents(), TRUE);
        $total += count($payload['collection'] ?? []);
        $query['page_token'] = $payload['pagination']['next_page_token'] ?? NULL;
      }
      catch (RequestException $e) {
        $this->logger->warning('Failed to load availability for event type @type: @msg', ['@type' => $eventTypeUri, '@msg' => $e->getMessage()]);
        break;
      }
    } while (!empty($query['page_token']));

    return $total;
  }

  /**
   * Indexes availability rows by URI/name for quick lookups.
   */
  protected function buildAvailabilityIndex(array $availabilitySummary): array {
    $index = [];
    if (empty($availabilitySummary['staff']) || !is_array($availabilitySummary['staff'])) {
      return $index;
    }
    foreach ($availabilitySummary['staff'] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $key = $row['uri'] ?? ($row['name'] ?? NULL);
      if (!$key) {
        continue;
      }
      $index[$key] = [
        'slots' => $row['slots'] ?? 0,
        'name' => $row['name'] ?? '',
      ];
    }
    return $index;
  }

  /**
   * Finds the top performer for a derived metric.
   */
  protected function detectLeader(array $rows, callable $valueCallback): ?array {
    $leader = NULL;
    foreach ($rows as $row) {
      $value = (float) $valueCallback($row);
      if ($value <= 0) {
        continue;
      }
      if ($leader === NULL || $value > $leader['value']) {
        $leader = [
          'name' => $row['name'] ?? 'Unknown',
          'value' => $value,
        ];
      }
    }
    return $leader;
  }

  /**
   * Builds the condensed snapshot payload from full stats.
   */
  protected function buildSnapshotChunk(array $stats): array {
    $totals = $stats['totals'] ?? [];
    $leaders = $stats['leaders'] ?? [];
    $topHour = $stats['time_distribution']['top_hours'][0]['label'] ?? NULL;

    return [
      'total_events' => $totals['events'] ?? 0,
      'tours' => $totals['tours'] ?? 0,
      'orientations' => $totals['orientations'] ?? 0,
      'other_meetings' => $totals['other'] ?? 0,
      'popular_hour' => $topHour,
      'top_staff_tours' => $leaders['most_tours']['name'] ?? NULL,
      'top_staff_orientations' => $leaders['most_orientations']['name'] ?? NULL,
    ];
  }

  /**
   * Builds a lightweight summary for the previous period.
   */
  protected function buildComparisonSummary(\DateTimeImmutable $start, \DateTimeImmutable $end, array $headers, ?string $organizationUri, ?string $userUri, array $baseEventTypes, array $categoryOverrides, array $keywordMap): array {
    $eventTypeCatalog = $baseEventTypes;
    $events = $this->loadScheduledEvents($headers, $organizationUri, $userUri, $start, $end);

    $totals = [
      'events' => 0,
      'tours' => 0,
      'orientations' => 0,
      'other' => 0,
    ];
    $categories = [];

    foreach ($events as $event) {
      $eventTypeUri = $event['event_type'] ?? '';
      $eventTypeInfo = $eventTypeCatalog[$eventTypeUri] ?? [];
      [$categoryKey, $categoryLabel, $canonical] = $this->classifyEvent($eventTypeUri, $eventTypeInfo, $categoryOverrides, $keywordMap);

      $categories[$categoryKey]['label'] = $categoryLabel;
      $categories[$categoryKey]['count'] = ($categories[$categoryKey]['count'] ?? 0) + 1;
      $categories[$categoryKey]['canonical'] = $canonical;
      $categories[$categoryKey]['key'] = $categoryKey;

      $totalsKey = match ($canonical) {
        'tour' => 'tours',
        'orientation' => 'orientations',
        default => 'other',
      };
      $totals[$totalsKey] = ($totals[$totalsKey] ?? 0) + 1;
      $totals['events']++;
    }

    $categoriesList = array_values($categories);
    usort($categoriesList, static function (array $a, array $b) {
      return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
    });
    $categoriesByKey = [];
    foreach ($categoriesList as $row) {
      $categoriesByKey[$row['key']] = $row;
    }

    $label = $this->dateFormatter->format($start->getTimestamp(), 'custom', 'M j, Y') . ' – ' . $this->dateFormatter->format($end->getTimestamp(), 'custom', 'M j, Y');

    return [
      'period' => [
        'label' => $label,
        'start' => $start->getTimestamp(),
        'end' => $end->getTimestamp(),
        'days' => max(1, (int) ceil(max(1, $end->getTimestamp() - $start->getTimestamp()) / 86400)),
      ],
      'totals' => $totals,
      'categories' => $categoriesList,
      'categories_by_key' => $categoriesByKey,
    ];
  }

  /**
   * Figures out the reporting window for the requested range.
   */
  protected function determinePeriodWindow(\DateTimeImmutable $now, array $options, int $defaultDays): array {
    $explicitEnd = $this->normalizeDate($options['end'] ?? NULL);
    $periodEnd = $explicitEnd ?? $now;

    if ($explicitStart = $this->normalizeDate($options['start'] ?? NULL)) {
      return [$explicitStart, $periodEnd];
    }

    if (!empty($options['range_preset'])) {
      $preset = strtolower((string) $options['range_preset']);
      switch ($preset) {
        case 'mtd':
          $start = $periodEnd->setDate((int) $periodEnd->format('Y'), (int) $periodEnd->format('n'), 1)->setTime(0, 0, 0);
          return [$start, $periodEnd];

        case 'last_month':
          $firstOfMonth = $periodEnd->setDate((int) $periodEnd->format('Y'), (int) $periodEnd->format('n'), 1)->setTime(0, 0, 0);
          $start = $firstOfMonth->sub(new \DateInterval('P1M'));
          $end = $firstOfMonth->sub(new \DateInterval('PT1S'));
          return [$start, $end];

        case 'qtd':
          $month = (int) $periodEnd->format('n');
          $quarter = intdiv($month - 1, 3);
          $quarterMonth = $quarter * 3 + 1;
          $start = $periodEnd->setDate((int) $periodEnd->format('Y'), $quarterMonth, 1)->setTime(0, 0, 0);
          return [$start, $periodEnd];

        case 'last_quarter':
          $month = (int) $periodEnd->format('n');
          $quarter = intdiv($month - 1, 3);
          $quarterMonth = $quarter * 3 + 1;
          $currentQuarterStart = $periodEnd->setDate((int) $periodEnd->format('Y'), $quarterMonth, 1)->setTime(0, 0, 0);
          $start = $currentQuarterStart->sub(new \DateInterval('P3M'));
          $end = $currentQuarterStart->sub(new \DateInterval('PT1S'));
          return [$start, $end];
      }
    }

    $rangeDays = max(1, (int) ($options['range_days'] ?? $defaultDays));
    $periodStart = $periodEnd->sub(new \DateInterval('P' . $rangeDays . 'D'));
    return [$periodStart, $periodEnd];
  }

  /**
   * Returns the previous window matching the current period length.
   */
  protected function determineComparisonWindow(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): ?array {
    $periodSeconds = max(1, $periodEnd->getTimestamp() - $periodStart->getTimestamp());
    $comparisonEndTs = $periodStart->getTimestamp() - 1;
    if ($comparisonEndTs <= 0) {
      return NULL;
    }
    $comparisonStartTs = $comparisonEndTs - $periodSeconds;
    if ($comparisonStartTs < 0) {
      $comparisonStartTs = 0;
    }
    $comparisonStart = (new \DateTimeImmutable('@' . $comparisonStartTs))->setTimezone($this->timezone);
    $comparisonEnd = (new \DateTimeImmutable('@' . $comparisonEndTs))->setTimezone($this->timezone);

    return [
      'start' => $comparisonStart,
      'end' => $comparisonEnd,
    ];
  }

  /**
   * Builds a lookup of event type URIs -> category from block configuration.
   */
  protected function buildBlockCategoryOverrides(): array {
    if ($this->blockCategoryOverrides !== NULL) {
      return $this->blockCategoryOverrides;
    }

    $overrides = [];
    try {
      $storage = $this->entityTypeManager->getStorage('block');
      $blocks = $storage->loadMultiple();
      foreach ($blocks as $block) {
        if ($block->getPluginId() !== 'calendly_availability_block') {
          continue;
        }
        $settings = $block->get('settings');
        if (!is_array($settings)) {
          continue;
        }
        $label = trim((string) ($block->label() ?: ($settings['label'] ?? '')));
        $displayLabel = $label !== '' ? $label : 'Other meetings';
        $preferredKey = $settings['stats_category'] ?? '';
        $rawUris = $settings['selected_event_type_uris'] ?? [];
        if (empty($rawUris) || !is_array($rawUris)) {
          continue;
        }
        $uris = [];
        foreach ($rawUris as $key => $value) {
          if (is_string($value) && $value !== '') {
            $uris[] = $value;
          }
          elseif (is_string($key) && $key !== '' && $value) {
            $uris[] = $key;
          }
        }
        $categoryKey = $preferredKey !== '' ? $preferredKey : $this->machineNameFromString($displayLabel);
        $canonical = $this->normalizeCategory($preferredKey . ' ' . $displayLabel);
        foreach ($uris as $uri) {
          $overrides[$uri] = [
            'key' => $categoryKey,
            'label' => $displayLabel,
            'canonical' => $canonical,
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Unable to read Calendly block stats mappings: @msg', ['@msg' => $e->getMessage()]);
    }

    return $this->blockCategoryOverrides = $overrides;
  }

  /**
   * Creates a machine-safe key from free-form text.
   */
  protected function machineNameFromString(string $value): string {
    $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $value));
    $key = trim($key, '_');
    return $key !== '' ? $key : 'category_' . substr(md5($value), 0, 6);
  }

  /**
   * Normalizes various category labels to canonical keys.
   */
  protected function normalizeCategory(?string $category): string {
    $normalized = strtolower((string) $category);
    if (str_contains($normalized, 'tour')) {
      return 'tour';
    }
    if (str_contains($normalized, 'orient') || str_contains($normalized, 'safety') || str_contains($normalized, 'walk')) {
      return 'orientation';
    }
    return 'other';
  }

  /**
   * Determines the category metadata for an event.
   */
  protected function classifyEvent(string $eventTypeUri, array $eventTypeInfo, array $categoryOverrides, array $keywordMap): array {
    if (isset($categoryOverrides[$eventTypeUri])) {
      $override = $categoryOverrides[$eventTypeUri];
      return [
        $override['key'],
        $override['label'],
        $override['canonical'] ?? $this->normalizeCategory($override['label'] . ' ' . $override['key']),
      ];
    }

    $label = $eventTypeInfo['name'] ?? 'Other meetings';
    $slug = $eventTypeInfo['slug'] ?? '';
    $keywordCategory = $this->categorizeByKeywords($label, $slug, $keywordMap);
    if ($keywordCategory === 'tour') {
      return ['tours', $label, 'tour'];
    }
    if ($keywordCategory === 'orientation') {
      return ['orientations', $label, 'orientation'];
    }

    $key = $this->machineNameFromString($label);
    return [$key, $label, $this->normalizeCategory($label)];
  }

}
