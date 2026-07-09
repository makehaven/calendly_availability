<?php

namespace Drupal\calendly_availability\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use Psr\Log\LoggerInterface;

/**
 * Fetches and caches consolidated Calendly availability data.
 *
 * The availability block used to call the Calendly API synchronously and
 * sequentially during page render — with 5 event types over 15+ days that
 * was 20+ round trips and 15-20 second page loads on every render-cache
 * miss. This service decouples data fetching from rendering:
 *
 * - Slot data lives in its own cache entry, stored permanently so stale
 *   data survives as a fallback when Calendly is slow or down.
 * - Refreshes run the API calls concurrently (batched promises), so a
 *   full refresh costs roughly one round trip per batch, not per call.
 * - hook_cron() re-warms the cache for every placed availability block,
 *   so visitors almost never pay for a refresh inline.
 */
class CalendlySlotRepository {

  /**
   * Cron re-warms data older than this (seconds).
   */
  const FRESH_TTL = 900;

  /**
   * A page render triggers an inline refresh when data is older than this.
   *
   * Matches the render-cache max-age the block has always used, so the
   * freshness visitors see is unchanged.
   */
  const INLINE_REFRESH_AFTER = 1800;

  /**
   * Concurrent requests per batch when talking to Calendly.
   */
  const BATCH_SIZE = 8;

  /**
   * Per-request Guzzle options for all Calendly calls.
   */
  const REQUEST_OPTIONS = ['timeout' => 10, 'connect_timeout' => 5];

  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected CacheBackendInterface $cache,
    protected LockBackendInterface $lock,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CalendlyTokenManager $tokenManager,
    protected CalendlyAlertService $alerts,
  ) {}

  /**
   * Returns cached slot data for the given block settings.
   *
   * Serves cached data when it is younger than INLINE_REFRESH_AFTER.
   * Otherwise refreshes inline (under a lock, concurrently) and falls back
   * to stale data — however old — when the refresh fails or another
   * request is already refreshing.
   *
   * @return array|null
   *   ['slots' => array, 'fetched_at' => int], or NULL when no data has
   *   ever been fetched successfully and a fetch is not possible now.
   */
  public function getSlots(array $settings): ?array {
    $key = $this->cacheKey($settings);
    $payload = $this->readCache($key);
    $age = $payload ? $this->time->getRequestTime() - $payload['fetched_at'] : PHP_INT_MAX;

    if ($payload && $age < self::INLINE_REFRESH_AFTER) {
      return $payload;
    }

    $lock_name = 'calendly_availability_refresh:' . $key;
    if ($this->lock->acquire($lock_name, 60)) {
      try {
        $fresh = $this->refresh($settings);
        return $fresh ?? $payload;
      }
      finally {
        $this->lock->release($lock_name);
      }
    }

    // Another request holds the refresh lock. Stale data beats waiting.
    if ($payload) {
      return $payload;
    }
    $this->lock->wait($lock_name, 15);
    return $this->readCache($key);
  }

  /**
   * Refreshes cached data when it is older than FRESH_TTL. Cron entry point.
   */
  public function refreshIfStale(array $settings): void {
    $key = $this->cacheKey($settings);
    $payload = $this->readCache($key);
    if ($payload && ($this->time->getRequestTime() - $payload['fetched_at']) < self::FRESH_TTL) {
      return;
    }
    $lock_name = 'calendly_availability_refresh:' . $key;
    if ($this->lock->acquire($lock_name, 60)) {
      try {
        $this->refresh($settings);
      }
      finally {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Re-warms the slot cache for every enabled availability block.
   *
   * Block instances are deduplicated by cache key, so the same
   * configuration placed in several themes is only fetched once.
   */
  public function refreshAllPlacedBlocks(): void {
    $storage = $this->entityTypeManager->getStorage('block');
    $ids = $storage->getQuery()
      ->condition('plugin', 'calendly_availability_block')
      ->condition('status', TRUE)
      ->execute();
    $seen = [];
    foreach ($storage->loadMultiple($ids) as $block) {
      $settings = $block->get('settings');
      $key = $this->cacheKey($settings);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      try {
        $this->refreshIfStale($settings);
      }
      catch (\Exception $e) {
        $this->logger->error('Cron refresh failed for availability block @id: @msg', [
          '@id' => $block->id(),
          '@msg' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Fetches availability from Calendly and writes it to the cache.
   *
   * @return array|null
   *   The fresh payload, or NULL on hard failure (existing cached data is
   *   left untouched so stale fallback keeps working).
   */
  public function refresh(array $settings): ?array {
    $token = $this->tokenManager->getValidAccessToken();
    if (empty($token)) {
      $this->logger->warning('Cannot refresh Calendly availability: no valid access token.');
      return NULL;
    }
    $headers = ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'];
    $options = self::REQUEST_OPTIONS + ['headers' => $headers];

    $selected_uris = array_values($settings['selected_event_type_uris'] ?? []);
    $keywords_string = $settings['event_type_keywords'] ?? '';
    $keywords = !empty($keywords_string) ? array_map('trim', explode(',', strtolower($keywords_string))) : [];
    $days_to_show = (int) ($settings['days_to_show'] ?? 7);

    try {
      $response = $this->httpClient->request('GET', 'https://api.calendly.com/users/me', $options);
      $current_user_data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      $this->recordApiFailure('users/me', $e);
      return NULL;
    }
    if (!isset($current_user_data['resource']['uri'])) {
      $this->logger->warning('Could not determine user URI from /users/me during availability refresh.');
      return NULL;
    }
    $user_uri = $current_user_data['resource']['uri'];
    $current_user_name = $current_user_data['resource']['name'] ?? 'Current User';
    $user_names_cache = [$user_uri => $current_user_name];

    $raw_event_types = $this->fetchEventTypes($selected_uris, $current_user_data, $options);
    if ($raw_event_types === NULL) {
      return NULL;
    }

    $relevant_event_types = [];
    foreach ($raw_event_types as $et_raw) {
      $et_raw['event_owner_name'] = $this->determineOwnerDisplayName($et_raw, $user_names_cache, $options, $user_uri, $current_user_name);
      if (empty($selected_uris) && !empty($keywords)) {
        $event_name_lower = strtolower($et_raw['name'] ?? '');
        $matched = FALSE;
        foreach ($keywords as $keyword) {
          if (strpos($event_name_lower, $keyword) !== FALSE) {
            $matched = TRUE;
            break;
          }
        }
        if (!$matched) {
          continue;
        }
      }
      $relevant_event_types[] = $et_raw;
    }

    $slots = $this->fetchAvailability($relevant_event_types, $days_to_show, $options);

    $payload = [
      'slots' => $slots,
      'fetched_at' => $this->time->getCurrentTime(),
    ];
    $this->cache->set($this->cid($this->cacheKey($settings)), $payload, CacheBackendInterface::CACHE_PERMANENT);
    $this->logger->debug('Refreshed Calendly availability cache: @count slots across @types event types.', [
      '@count' => count($slots),
      '@types' => count($relevant_event_types),
    ]);
    return $payload;
  }

  /**
   * Fetches event type definitions, concurrently when URIs are selected.
   *
   * @return array|null
   *   Raw event type resources, or NULL when nothing could be fetched.
   */
  protected function fetchEventTypes(array $selected_uris, array $current_user_data, array $options): ?array {
    if (!empty($selected_uris)) {
      $raw = [];
      foreach (array_chunk($selected_uris, self::BATCH_SIZE) as $batch) {
        $promises = [];
        foreach ($batch as $uri) {
          $promises[$uri] = $this->httpClient->requestAsync('GET', $uri, $options);
        }
        foreach (Utils::settle($promises)->wait() as $uri => $result) {
          if ($result['state'] === 'fulfilled') {
            $data = json_decode($result['value']->getBody()->getContents(), TRUE);
            if (isset($data['resource'])) {
              $raw[] = $data['resource'];
            }
          }
          else {
            $this->logger->error('Failed to fetch selected event type @uri: @msg', [
              '@uri' => $uri,
              '@msg' => $result['reason'] instanceof \Throwable ? $result['reason']->getMessage() : 'unknown',
            ]);
          }
        }
      }
      if (empty($raw)) {
        // All selected event types failed — treat as a hard failure so the
        // caller preserves stale data instead of caching an empty schedule.
        return NULL;
      }
      return $raw;
    }

    $organization_uri = $current_user_data['resource']['current_organization'] ?? NULL;
    $user_uri = $current_user_data['resource']['uri'];
    $fetch_url = $organization_uri
      ? 'https://api.calendly.com/event_types?organization=' . urlencode($organization_uri) . '&active=true&count=100&sort=name:asc'
      : 'https://api.calendly.com/event_types?user=' . urlencode($user_uri) . '&active=true&count=100&sort=name:asc';
    try {
      $response = $this->httpClient->request('GET', $fetch_url, $options);
      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['collection'] ?? [];
    }
    catch (RequestException $e) {
      $this->recordApiFailure('event_types list', $e);
      return NULL;
    }
  }

  /**
   * Fetches available times for all event types, in concurrent batches.
   *
   * Calendly's event_type_available_times endpoint caps ranges at 7 days,
   * so each event type needs ceil(days/7) requests. All of them are
   * independent, so they run as batched concurrent promises. Individual
   * failures are logged and skipped, matching the old sequential behavior.
   */
  protected function fetchAvailability(array $event_types, int $days_to_show, array $options): array {
    $max_days_per_request = 7;
    $requests = [];
    foreach ($event_types as $event_type) {
      $event_type_uri = $event_type['uri'] ?? NULL;
      $event_type_name = $event_type['name'] ?? 'Unknown Event Name';
      $scheduling_url = $event_type['scheduling_url'] ?? NULL;
      if (!$event_type_uri) {
        continue;
      }
      if (!$scheduling_url) {
        $this->logger->warning('Event type "@name" (URI: @uri) is missing a scheduling_url. Skipping.', [
          '@name' => $event_type_name,
          '@uri' => $event_type_uri,
        ]);
        continue;
      }
      for ($offset = 0; $offset < $days_to_show; $offset += $max_days_per_request) {
        $loop_start_date = new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC'));
        $chunk_start_date = $loop_start_date->modify("+$offset days");
        $days_in_this_chunk = min($max_days_per_request, $days_to_show - $offset);
        $chunk_end_date = $chunk_start_date->modify("+$days_in_this_chunk days");
        $requests[] = [
          'event_name' => $event_type_name,
          'event_owner_name' => $event_type['event_owner_name'] ?? '',
          'booking_url' => $scheduling_url,
          'query' => [
            'event_type' => $event_type_uri,
            'start_time' => $chunk_start_date->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $chunk_end_date->format('Y-m-d\TH:i:s\Z'),
          ],
        ];
      }
    }

    $all_available_slots = [];
    foreach (array_chunk($requests, self::BATCH_SIZE) as $batch) {
      $promises = [];
      foreach ($batch as $i => $request) {
        $promises[$i] = $this->httpClient->requestAsync('GET', 'https://api.calendly.com/event_type_available_times', $options + ['query' => $request['query']]);
      }
      foreach (Utils::settle($promises)->wait() as $i => $result) {
        $request = $batch[$i];
        if ($result['state'] !== 'fulfilled') {
          $this->logger->error('Failed to fetch availability for event type "@name": @msg', [
            '@name' => $request['event_name'],
            '@msg' => $result['reason'] instanceof \Throwable ? $result['reason']->getMessage() : 'unknown',
          ]);
          continue;
        }
        $availability_data = json_decode($result['value']->getBody()->getContents(), TRUE);
        foreach ($availability_data['collection'] ?? [] as $slot_item) {
          if (($slot_item['status'] ?? 'not-available') === 'available') {
            $all_available_slots[] = [
              'event_name' => $request['event_name'],
              'event_owner_name' => $request['event_owner_name'],
              'start_time' => $slot_item['start_time'],
              'invitees_remaining' => $slot_item['invitees_remaining'] ?? NULL,
              'booking_url' => $request['booking_url'],
            ];
          }
        }
      }
    }

    if (!empty($all_available_slots)) {
      $unique_slots = [];
      foreach ($all_available_slots as $slot) {
        $unique_slots[$slot['start_time'] . '_' . $slot['booking_url']] = $slot;
      }
      $all_available_slots = array_values($unique_slots);
      usort($all_available_slots, function ($a, $b) {
        return strtotime($a['start_time']) - strtotime($b['start_time']);
      });
    }
    return $all_available_slots;
  }

  /**
   * Resolves the display name of an event type's owner.
   *
   * Public so the block's admin form can reuse it when listing event types.
   */
  public function determineOwnerDisplayName(array $et, array &$user_names_cache, array $options, ?string $current_user_uri = NULL, string $current_user_name = ''): string {
    $owner_display_name = 'Unknown Owner';
    $event_name_val = $et['name'] ?? 'N/A';
    if (isset($et['profile']['type'], $et['profile']['name'], $et['profile']['owner'])) {
      $profile_type = $et['profile']['type'];
      $profile_name = $et['profile']['name'];
      $profile_owner_uri = $et['profile']['owner'];
      if ($profile_type === 'Team') {
        $owner_display_name = $profile_name . ' (Team)';
      }
      elseif ($profile_type === 'User') {
        $owner_display_name = $profile_name;
        if ($profile_owner_uri) {
          $user_names_cache[$profile_owner_uri] = $owner_display_name;
        }
      }
      else {
        $this->logger->warning('Event "@event" has a profile object, but with an unknown type: "@type". Profile data: @profile', [
          '@event' => $event_name_val,
          '@type' => $profile_type,
          '@profile' => json_encode($et['profile']),
        ]);
      }
    }
    elseif (isset($et['user'])) {
      $event_owner_user_uri = $et['user'];
      if (isset($user_names_cache[$event_owner_user_uri])) {
        $owner_display_name = $user_names_cache[$event_owner_user_uri];
      }
      elseif ($event_owner_user_uri === $current_user_uri && !empty($current_user_name)) {
        $owner_display_name = $current_user_name;
        $user_names_cache[$event_owner_user_uri] = $owner_display_name;
      }
      else {
        try {
          $response = $this->httpClient->request('GET', $event_owner_user_uri, $options);
          $user_details = json_decode($response->getBody()->getContents(), TRUE);
          if (isset($user_details['resource']['name'])) {
            $owner_display_name = $user_details['resource']['name'];
            $user_names_cache[$event_owner_user_uri] = $owner_display_name;
          }
          else {
            $owner_display_name = 'User (Name N/A)';
          }
        }
        catch (RequestException $e) {
          $this->logger->warning('Failed to fetch user details for URI @uri (via direct "user" field). Message: @message', [
            '@uri' => $event_owner_user_uri,
            '@message' => $e->getMessage(),
          ]);
          $owner_display_name = 'User (Fetch Failed)';
        }
      }
    }
    else {
      $this->logger->warning('Could not determine owner for event type "@name" using profile or direct user field. Defaulting to "Unknown Owner". Event Data: @event_data', [
        '@name' => $event_name_val,
        '@event_data' => json_encode($et),
      ]);
    }
    return $owner_display_name;
  }

  /**
   * Derives a stable cache key from the settings that affect fetched data.
   */
  public function cacheKey(array $settings): string {
    $uris = array_values($settings['selected_event_type_uris'] ?? []);
    sort($uris);
    return md5(json_encode([
      'uris' => $uris,
      'keywords' => strtolower(trim($settings['event_type_keywords'] ?? '')),
      'days' => (int) ($settings['days_to_show'] ?? 7),
    ]));
  }

  /**
   * Reads a payload from the cache, NULL when absent.
   */
  protected function readCache(string $key): ?array {
    $cached = $this->cache->get($this->cid($key));
    return $cached ? $cached->data : NULL;
  }

  /**
   * Builds the cache ID for a settings key.
   */
  protected function cid(string $key): string {
    return 'calendly_availability:slots:' . $key;
  }

  /**
   * Logs an API failure and notifies the alert service.
   */
  protected function recordApiFailure(string $context, RequestException $e): void {
    $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
    $this->logger->error('Calendly API request failed during availability refresh (@context, HTTP @code): @msg', [
      '@context' => $context,
      '@code' => $status,
      '@msg' => $e->getMessage(),
    ]);
    $this->state->set('calendly_availability.last_api_error_time', $this->time->getCurrentTime());
    $this->alerts->notifyApiFailure('slot_refresh:' . $context, sprintf('HTTP %d: %s', $status, $e->getMessage()));
  }

}
