<?php

namespace Drupal\calendly_availability\Service;

use Drupal\calendly_availability\Form\CalendlySettingsForm;
use GuzzleHttp\Exception\RequestException;

/**
 * Shared helper for services that need a valid Calendly access token.
 */
trait CalendlyTokenTrait {

  /**
   * Retrieves or refreshes the Calendly access token as needed.
   *
   * Returns NULL when no usable token is available so callers can render a
   * fallback UI rather than attempting API calls that will fail.
   */
  protected function getValidAccessToken(): ?string {
    if (!isset($this->configFactory)) {
      throw new \RuntimeException('CalendlyTokenTrait requires a configFactory property.');
    }

    $state = \Drupal::state();
    $config = $this->configFactory->get('calendly_availability.settings');
    $accessToken = $state->get('calendly_availability.personal_access_token');
    $refreshToken = $state->get('calendly_availability.refresh_token');
    $expiresAt = $state->get('calendly_availability.token_expires_at');

    // If token is valid and not near expiry, return immediately.
    if ($accessToken && $expiresAt && time() < ($expiresAt - 300)) {
      return $accessToken;
    }

    // Need to refresh. If we have no refresh token, surface what we have
    // (legacy PAT setups) but return NULL once already expired.
    if (empty($refreshToken)) {
      if ($expiresAt && time() > $expiresAt) {
        return NULL;
      }
      return $accessToken;
    }

    $this->logger->info('Calendly access token is expired or expiring soon. Attempting to refresh.');
    $credentials = CalendlySettingsForm::getCredentialsForCurrentHost($config);
    $client_id = $credentials['client_id'] ?? '';
    $client_secret = $credentials['client_secret'] ?? '';

    if (empty($client_id) || empty($client_secret)) {
      $this->logger->warning('Cannot refresh Calendly token: missing client credentials for current host.');
      $this->recordRefreshFailure('missing_credentials', 'No client_id/secret for current host.');
      return ($expiresAt && time() > $expiresAt) ? NULL : $accessToken;
    }

    try {
      $response = $this->httpClient->post('https://auth.calendly.com/oauth/token', [
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refreshToken,
          'client_id' => $client_id,
          'client_secret' => $client_secret,
        ],
        'http_errors' => TRUE,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      if (!empty($data['access_token'])) {
        $state->set('calendly_availability.personal_access_token', $data['access_token']);
        $state->set('calendly_availability.refresh_token', $data['refresh_token'] ?? $refreshToken);
        $state->set('calendly_availability.token_expires_at', time() + ($data['expires_in'] ?? 3600));
        $state->delete('calendly_availability.last_refresh_error_time');
        $state->delete('calendly_availability.last_refresh_error_message');

        $this->logger->info('Successfully refreshed Calendly access token.');
        return $data['access_token'];
      }
      $this->recordRefreshFailure('empty_response', 'Refresh response lacked access_token.');
    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
      $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      $this->logger->error('Failed to refresh Calendly access token (HTTP @code): @message. Body: @body', [
        '@code' => $status,
        '@message' => $e->getMessage(),
        '@body' => $body,
      ]);

      // 400 invalid_grant or 401 unauthorized_client means the refresh token
      // is permanently dead. Clear it so the settings page shows "Not
      // Authorized" rather than the admin believing it will self-heal.
      if (in_array($status, [400, 401], TRUE)) {
        $state->delete('calendly_availability.personal_access_token');
        $state->delete('calendly_availability.refresh_token');
        $state->delete('calendly_availability.token_expires_at');
        $this->recordRefreshFailure('auth_revoked', sprintf('HTTP %d from refresh endpoint; tokens cleared. Body: %s', $status, $body));
        return NULL;
      }

      $this->recordRefreshFailure('http_error', sprintf('HTTP %d: %s', $status, $e->getMessage()));
    }

    return ($expiresAt && time() > $expiresAt) ? NULL : $accessToken;
  }

  /**
   * Records a refresh failure and triggers an alert (rate-limited).
   */
  protected function recordRefreshFailure(string $reason, string $message): void {
    $state = \Drupal::state();
    $state->set('calendly_availability.last_refresh_error_time', time());
    $state->set('calendly_availability.last_refresh_error_message', $reason . ': ' . $message);
    if (\Drupal::hasService('calendly_availability.alerts')) {
      \Drupal::service('calendly_availability.alerts')->notifyRefreshFailure($reason, $message);
    }
  }

}
