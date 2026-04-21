<?php

namespace Drupal\calendly_availability\Service;

/**
 * Backward-compatible thin wrapper around CalendlyTokenManager.
 *
 * Historically the refresh logic lived directly in this trait, duplicated
 * with the cron hook. Both paths used the same refresh token, so a
 * concurrent refresh could hit Calendly's rotation and the loser would
 * receive HTTP 400 invalid_grant — and the old code wiped tokens on
 * that response, breaking tour/orientation booking until someone
 * manually re-authorized. All refresh logic now lives in the
 * CalendlyTokenManager service (single lock, cooldown-on-failure,
 * no destructive wipes).
 */
trait CalendlyTokenTrait {

  /**
   * Retrieves or refreshes the Calendly access token as needed.
   *
   * Returns NULL when no usable token is available so callers can render a
   * fallback UI rather than attempting API calls that will fail.
   */
  protected function getValidAccessToken(): ?string {
    return \Drupal::service('calendly_availability.token_manager')->getValidAccessToken();
  }

}
