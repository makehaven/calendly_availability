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
   * Notifies when the OAuth refresh flow fails.
   */
  public function notifyRefreshFailure(string $reason, string $message): void {
    $subject = 'Calendly token refresh failed';
    $body = sprintf(
      "The Calendly OAuth token refresh failed on %s.\n\nReason: %s\nDetails: %s\n\nImpact: tour and orientation booking pages will fall back to a plain Calendly embed. New members still see a booking option, but internal availability widgets are offline until an admin re-authorizes at /admin/config/services/calendly-availability.",
      \Drupal::request()->getSchemeAndHttpHost(),
      $reason,
      $message
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
