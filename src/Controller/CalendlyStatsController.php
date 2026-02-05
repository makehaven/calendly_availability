<?php

namespace Drupal\calendly_availability\Controller;

use Drupal\calendly_availability\Service\CalendlyStatsCollectorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Calendly statistics dashboard + JSON endpoint.
 */
class CalendlyStatsController extends ControllerBase {

  protected CalendlyStatsCollectorInterface $statsCollector;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('calendly_availability.stats_collector')
    );
  }

  public function __construct(CalendlyStatsCollectorInterface $statsCollector) {
    $this->statsCollector = $statsCollector;
  }

  /**
   * Renders the stats dashboard page.
   */
  public function view(Request $request): array {
    $options = $this->buildOptionsFromRequest($request);
    $stats = $this->statsCollector->collect($options);

    if (($stats['status'] ?? '') !== 'ok') {
      $settingsUrl = Url::fromRoute('calendly_availability.settings')->toString();
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['calendly-stats', 'calendly-stats--error']],
        'message' => [
          '#markup' => $this->t('Unable to load Calendly stats: @message. <a href=":settings">Go to settings to re-authorize</a>.', [
            '@message' => $stats['message'] ?? $this->t('Unknown error'),
            ':settings' => $settingsUrl,
          ]),
        ],
      ];
    }

    $rangeLinks = $this->buildRangeLinks($request, (int) ($stats['period']['days'] ?? 0));
    if (!isset($stats['meta']) || !is_array($stats['meta'])) {
      $stats['meta'] = [];
    }
    $stats['meta']['range_links'] = $rangeLinks;

    return [
      '#theme' => 'calendly_availability_stats',
      '#stats' => $stats,
      '#attached' => [
        'library' => ['calendly_availability/stats'],
        'drupalSettings' => [
          'calendlyAvailabilityStats' => [
            'period' => $stats['period'] ?? [],
            'snapshot' => $stats['snapshot'] ?? [],
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 300,
        'contexts' => [
          'url.query_args:start',
          'url.query_args:end',
          'url.query_args:range',
          'url.query_args:availability_days',
        ],
      ],
    ];
  }

  /**
   * Returns stats as JSON for external consumers/snapshots.
   */
  public function json(Request $request): JsonResponse {
    $options = $this->buildOptionsFromRequest($request);
    $stats = $this->statsCollector->collect($options);
    return new JsonResponse($stats);
  }

  /**
   * Parses request query params into collector options.
   */
  protected function buildOptionsFromRequest(Request $request): array {
    $options = [];
    if ($request->query->has('start')) {
      $options['start'] = $request->query->get('start');
    }
    if ($request->query->has('end')) {
      $options['end'] = $request->query->get('end');
    }
    if ($request->query->has('availability_days')) {
      $options['availability_window_days'] = (int) $request->query->get('availability_days');
    }
    if ($request->query->has('range')) {
      $rangeValue = $request->query->get('range');
      if (is_numeric($rangeValue)) {
        $options['range_days'] = (int) $rangeValue;
      }
      else {
        $options['range_preset'] = preg_replace('/[^a-z_]/i', '', strtolower((string) $rangeValue));
      }
    }
    return $options;
  }

  /**
   * Builds preset range links for quick filtering.
   */
  protected function buildRangeLinks(Request $request, int $activeDays): array {
    $numericRanges = [
      30 => $this->t('30 days'),
      60 => $this->t('60 days'),
      90 => $this->t('90 days'),
      120 => $this->t('120 days'),
    ];
    $presetRanges = [
      'mtd' => $this->t('Month to date'),
      'last_month' => $this->t('Last month'),
      'qtd' => $this->t('Quarter to date'),
      'last_quarter' => $this->t('Last quarter'),
    ];

    $rawRange = $request->query->get('range');
    $links = [];
    foreach ($numericRanges as $days => $label) {
      $query = $request->query->all();
      $query['range'] = $days;
      $url = Url::fromRoute('calendly_availability.stats', [], ['query' => $query])->toString();
      $links[] = [
        'label' => $label,
        'url' => $url,
        'active' => isset($rawRange)
          ? ((string) $days === (string) $rawRange)
          : ($days === $activeDays),
      ];
    }
    foreach ($presetRanges as $key => $label) {
      $query = $request->query->all();
      $query['range'] = $key;
      $url = Url::fromRoute('calendly_availability.stats', [], ['query' => $query])->toString();
      $links[] = [
        'label' => $label,
        'url' => $url,
        'active' => isset($rawRange) && $rawRange === $key,
      ];
    }
    return $links;
  }

}
