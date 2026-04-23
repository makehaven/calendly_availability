<?php

namespace Drupal\calendly_availability\Service;

use Drupal\calendly_availability\Form\CalendlySettingsForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Owns all Calendly OAuth token lifecycle: retrieval, refresh, cooldown.
 *
 * Concentrating refresh logic here (instead of duplicating across a trait
 * and the cron hook) lets us enforce a single refresh lock so cron and
 * on-demand code paths can't race and invalidate each other's refresh
 * tokens — Calendly rotates the refresh token on every use, so two
 * concurrent refreshes cause one to receive HTTP 400 invalid_grant.
 */
class CalendlyTokenManager {

  /**
   * How long after a 400/401 refresh failure we stop trying to refresh.
   *
   * Prevents hammering Calendly when tokens are genuinely revoked and
   * gives a rotation-race a window to settle.
   */
  const REFRESH_COOLDOWN_SECONDS = 900;

  /**
   * Maximum seconds to wait for the refresh lock.
   */
  const LOCK_WAIT_SECONDS = 15;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected CalendlyAlertService $alerts,
  ) {}

  /**
   * Returns a valid access token, refreshing when close to expiry.
   *
   * Returns NULL when no usable token is available so callers can render a
   * fallback UI rather than attempting API calls that will 401.
   */
  public function getValidAccessToken(): ?string {
    $accessToken = $this->state->get('calendly_availability.personal_access_token');
    $refreshToken = $this->state->get('calendly_availability.refresh_token');
    $expiresAt = $this->state->get('calendly_availability.token_expires_at');

    // Valid and not near expiry — fast path, no lock, no HTTP call.
    if ($accessToken && $expiresAt && time() < ($expiresAt - 300)) {
      return $accessToken;
    }

    // Respect cooldown after repeated refresh failures.
    $cooldownUntil = (int) $this->state->get('calendly_availability.refresh_cooldown_until', 0);
    if ($cooldownUntil && time() < $cooldownUntil) {
      return $this->stillUsable($accessToken, $expiresAt);
    }

    // No refresh token = legacy PAT setup; nothing to refresh against.
    if (empty($refreshToken)) {
      return $this->stillUsable($accessToken, $expiresAt);
    }

    return $this->refreshWithLock($accessToken, $refreshToken, $expiresAt);
  }

  /**
   * Proactive refresh for cron — refreshes when within 24h of expiry.
   *
   * Rate-limited to at most one attempt per hour to avoid cron thrash.
   */
  public function refreshIfNeeded(): void {
    $lastAttempt = (int) $this->state->get('calendly_availability.cron_last_attempt', 0);
    if ($lastAttempt && (time() - $lastAttempt) < 3600) {
      return;
    }

    $refreshToken = $this->state->get('calendly_availability.refresh_token');
    $expiresAt = $this->state->get('calendly_availability.token_expires_at');
    if (empty($refreshToken) || empty($expiresAt)) {
      return;
    }

    if (time() < ($expiresAt - 86400)) {
      return;
    }

    $cooldownUntil = (int) $this->state->get('calendly_availability.refresh_cooldown_until', 0);
    if ($cooldownUntil && time() < $cooldownUntil) {
      return;
    }

    $this->state->set('calendly_availability.cron_last_attempt', time());

    $accessToken = $this->state->get('calendly_availability.personal_access_token');
    $this->refreshWithLock($accessToken, $refreshToken, $expiresAt);
  }

  /**
   * Acquires the refresh lock, re-reads state, performs the refresh.
   *
   * If another process holds the lock we wait briefly; if they completed
   * successfully, their new token is in state and we can return it
   * without making our own refresh call (which would fail with
   * invalid_grant since the refresh token rotated).
   */
  protected function refreshWithLock(?string $accessToken, string $refreshToken, ?int $expiresAt): ?string {
    $lockName = 'calendly_availability.refresh';

    if (!$this->lock->acquire($lockName, 30.0)) {
      $this->lock->wait($lockName, self::LOCK_WAIT_SECONDS);
      $freshAccess = $this->state->get('calendly_availability.personal_access_token');
      $freshExpiresAt = $this->state->get('calendly_availability.token_expires_at');
      if ($freshAccess && $freshExpiresAt && time() < ($freshExpiresAt - 60)) {
        return $freshAccess;
      }
      return $this->stillUsable($freshAccess, $freshExpiresAt);
    }

    try {
      // Re-read state inside the lock: another process may have just
      // completed a successful refresh while we were waiting.
      $currentAccess = $this->state->get('calendly_availability.personal_access_token');
      $currentRefresh = $this->state->get('calendly_availability.refresh_token') ?: $refreshToken;
      $currentExpiresAt = $this->state->get('calendly_availability.token_expires_at');
      if ($currentAccess && $currentExpiresAt && time() < ($currentExpiresAt - 300)) {
        return $currentAccess;
      }

      return $this->executeRefresh($currentAccess, $currentRefresh, $currentExpiresAt);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Performs the actual refresh HTTP call.
   *
   * On HTTP 400/401 we do NOT wipe tokens: a single invalid_grant is
   * often a rotation race with another in-flight refresh, and wiping
   * would turn a transient error into a permanent outage requiring
   * manual re-authorization. We start a cooldown, alert the admin,
   * and let a future attempt (or genuine re-auth) recover.
   */
  protected function executeRefresh(?string $accessToken, string $refreshToken, ?int $expiresAt): ?string {
    $this->logger->info('Calendly access token expired or near expiry; attempting refresh.');

    $config = $this->configFactory->get('calendly_availability.settings');
    $credentials = CalendlySettingsForm::getRefreshCredentials($config);
    $client_id = $credentials['client_id'] ?? '';
    $client_secret = $credentials['client_secret'] ?? '';

    if (empty($client_id) || empty($client_secret)) {
      $this->logger->warning('Cannot refresh Calendly token: no OAuth client credentials available for the authorized environment.');
      $this->recordRefreshFailure('missing_credentials', 'No client_id/secret for authorized environment.');
      return $this->stillUsable($accessToken, $expiresAt);
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
        $this->state->set('calendly_availability.personal_access_token', $data['access_token']);
        $this->state->set('calendly_availability.refresh_token', $data['refresh_token'] ?? $refreshToken);
        $this->state->set('calendly_availability.token_expires_at', time() + ($data['expires_in'] ?? 3600));
        $this->state->delete('calendly_availability.last_refresh_error_time');
        $this->state->delete('calendly_availability.last_refresh_error_message');
        $this->state->delete('calendly_availability.refresh_cooldown_until');
        $this->state->delete('calendly_availability.consecutive_refresh_failures');
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

      if (in_array($status, [400, 401], TRUE)) {
        $this->state->set('calendly_availability.refresh_cooldown_until', time() + self::REFRESH_COOLDOWN_SECONDS);
        $this->recordRefreshFailure('auth_refused', sprintf('HTTP %d from refresh endpoint; cooldown %ds. Body: %s', $status, self::REFRESH_COOLDOWN_SECONDS, $body));
        return $this->stillUsable($accessToken, $expiresAt);
      }

      $this->recordRefreshFailure('http_error', sprintf('HTTP %d: %s', $status, $e->getMessage()));
    }

    return $this->stillUsable($accessToken, $expiresAt);
  }

  /**
   * Returns the access token iff it hasn't yet passed its absolute expiry.
   */
  protected function stillUsable(?string $accessToken, ?int $expiresAt): ?string {
    if (empty($accessToken)) {
      return NULL;
    }
    if ($expiresAt && time() > $expiresAt) {
      return NULL;
    }
    return $accessToken;
  }

  /**
   * Records a refresh failure and triggers an alert (rate-limited).
   *
   * A single invalid_grant is often a transient rotation race and the
   * existing access token usually keeps widgets online until the next
   * refresh attempt succeeds. To avoid waking staff up for self-healing
   * blips, we only alert once we've seen consecutive failures — a second
   * failure after the cooldown indicates the issue is persistent and a
   * human probably needs to re-authorize.
   */
  protected function recordRefreshFailure(string $reason, string $message): void {
    $this->state->set('calendly_availability.last_refresh_error_time', time());
    $this->state->set('calendly_availability.last_refresh_error_message', $reason . ': ' . $message);

    $consecutive = ((int) $this->state->get('calendly_availability.consecutive_refresh_failures', 0)) + 1;
    $this->state->set('calendly_availability.consecutive_refresh_failures', $consecutive);

    if ($consecutive < 2) {
      $this->logger->info('Calendly refresh failed (attempt @n, suppressing alert): @reason — @message', [
        '@n' => $consecutive,
        '@reason' => $reason,
        '@message' => $message,
      ]);
      return;
    }

    $expiresAt = (int) $this->state->get('calendly_availability.token_expires_at', 0);
    $this->alerts->notifyRefreshFailure($reason, $message, $consecutive, $expiresAt);
  }

}
