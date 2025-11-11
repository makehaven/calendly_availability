<?php

namespace Drupal\calendly_availability\Service;

/**
 * Contract for services that build Calendly utilization stats.
 */
interface CalendlyStatsCollectorInterface {

  /**
   * Collects stats for the requested period.
   *
   * @param array $options
   *   Optional context such as 'start', 'end', or 'availability_window_days'.
   *
   * @return array
   *   Structured dataset describing totals, staff breakdowns, and timing info.
   */
  public function collect(array $options = []): array;

  /**
   * Reduces the stats payload to the snapshot-friendly subset of metrics.
   *
   * @param array|null $stats
   *   Optional stats array from collect(); gathered automatically if omitted.
   *
   * @return array
   *   KPI-ready data (counts, leaders, top hours, etc.).
   */
  public function buildSnapshotPayload(?array $stats = NULL): array;

}

