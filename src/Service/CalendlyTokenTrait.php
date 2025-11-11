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
   */
  protected function getValidAccessToken(): ?string {
    if (!isset($this->configFactory)) {
      throw new \RuntimeException('CalendlyTokenTrait requires a configFactory property.');
    }

    $config = $this->configFactory->getEditable('calendly_availability.settings');
    $accessToken = $config->get('personal_access_token');
    $refreshToken = $config->get('refresh_token');
    $expiresAt = $config->get('token_expires_at');

    if ((!$expiresAt || time() > ($expiresAt - 300)) && $refreshToken) {
      $this->logger->info('Calendly access token is expired or expiring soon. Attempting to refresh.');
      $credentials = CalendlySettingsForm::getCredentialsForCurrentHost($config);
      $client_id = $credentials['client_id'] ?? '';
      $client_secret = $credentials['client_secret'] ?? '';

      if (empty($client_id) || empty($client_secret)) {
        $this->logger->warning('Cannot refresh Calendly token: missing client credentials for current host.');
        return $accessToken;
      }
      try {
        $response = $this->httpClient->post('https://auth.calendly.com/oauth/token', [
          'form_params' => [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
          ],
        ]);

        $data = json_decode($response->getBody()->getContents(), TRUE);
        if (!empty($data['access_token'])) {
          $config
            ->set('personal_access_token', $data['access_token'])
            ->set('refresh_token', $data['refresh_token'] ?? $refreshToken)
            ->set('token_expires_at', time() + ($data['expires_in'] ?? 3600))
            ->save();

          $this->logger->info('Successfully refreshed Calendly access token.');
          return $data['access_token'];
        }
      }
      catch (RequestException $e) {
        $this->logger->error('Failed to refresh Calendly access token: @message', ['@message' => $e->getMessage()]);
        return $accessToken;
      }
    }

    return $accessToken;
  }

}
