<?php

namespace Drupal\calendly_availability\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends rate-limited alerts when the Calendly integration misbehaves.
 */
class CalendlyAlertService {

  const SUPPRESSION_WINDOW_SECONDS = 21600;
  const DEFAULT_EMAIL = 'staff@makehaven.org';
  const DEFAULT_SLACK_CHANNEL = '#tech';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected MailManagerInterface $mailManager,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Notifies when the OAuth refresh flow fails persistently.
   *
   * Callers should only invoke this after consecutive failures — a single
   * invalid_grant is often a transient rotation race and the existing
   * access token keeps widgets online until the next retry succeeds.
   */
  public function notifyRefreshFailure(string $reason, string $message, int $consecutiveFailures = 1, int $tokenExpiresAt = 0): void {
    $subject = 'Calendly token refresh failing repeatedly';

    $tokenStatus = 'Token expiry unknown.';
    if ($tokenExpiresAt > 0) {
      $remaining = $tokenExpiresAt - time();
      if ($remaining > 0) {
        $tokenStatus = sprintf(
          'Current access token is still valid for about %d minutes (until %s UTC); widgets remain online until then.',
          (int) ($remaining / 60),
          gmdate('Y-m-d H:i', $tokenExpiresAt)
        );
      }
      else {
        $tokenStatus = sprintf('Current access token expired %s UTC; widgets have fallen back to a plain Calendly embed.', gmdate('Y-m-d H:i', $tokenExpiresAt));
      }
    }

    $body = sprintf(
      "Calendly OAuth refresh has failed %d consecutive times on %s.\n\nReason: %s\nDetails: %s\n\n%s\n\nThe system retries automatically after a 15-minute cooldown. Because this failure has repeated past the cooldown, a human likely needs to re-authorize at /admin/config/services/calendly-availability.",
      $consecutiveFailures,
      \Drupal::request()->getSchemeAndHttpHost(),
      $reason,
      $message,
      $tokenStatus
    );
    $this->send('refresh_failure', $subject, $body);
  }

  /**
   * Notifies when a live API call fails with the API returning an error.
   */
  public function notifyApiFailure(string $context, string $message): void {
    $subject = 'Calendly API error';
    $body = sprintf(
      "A Calendly API call failed on %s.\n\nContext: %s\nDetails: %s\n\nUsers will see the fallback scheduling link. Check /admin/reports/status and Drupal logs.",
      \Drupal::request()->getSchemeAndHttpHost(),
      $context,
      $message
    );
    $this->send('api_failure', $subject, $body);
  }

  /**
   * Sends the alert via email and Slack, rate-limited per key.
   */
  protected function send(string $key, string $subject, string $body): void {
    $last = $this->state->get('calendly_availability.alert_last.' . $key, 0);
    if ($last && (time() - $last) < self::SUPPRESSION_WINDOW_SECONDS) {
      return;
    }
    $this->state->set('calendly_availability.alert_last.' . $key, time());

    $config = $this->configFactory->get('calendly_availability.settings');
    $email = trim((string) $config->get('alert_email')) ?: self::DEFAULT_EMAIL;

    try {
      $this->mailManager->mail(
        'calendly_availability',
        'alert',
        $email,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        ['subject' => $subject, 'body' => $body],
        NULL,
        TRUE,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to send Calendly alert email: @msg', ['@msg' => $e->getMessage()]);
    }

    $this->sendSlack($subject, $body);
  }

  /**
   * Posts to Slack via slack_connector's shared webhook, if configured.
   */
  protected function sendSlack(string $subject, string $body): void {
    $connector = $this->configFactory->get('slack_connector.settings');
    $webhook = $connector->get('webhook_url');
    if (empty($webhook)) {
      return;
    }
    $config = $this->configFactory->get('calendly_availability.settings');
    $channel = trim((string) $config->get('alert_slack_channel')) ?: self::DEFAULT_SLACK_CHANNEL;
    $channel = '#' . ltrim($channel, '#');

    try {
      $this->httpClient->post($webhook, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => [
          'channel' => $channel,
          'blocks' => [
            [
              'type' => 'section',
              'text' => [
                'type' => 'mrkdwn',
                'text' => ":warning: *{$subject}*\n```{$body}```",
              ],
            ],
          ],
        ],
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to post Calendly alert to Slack: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
