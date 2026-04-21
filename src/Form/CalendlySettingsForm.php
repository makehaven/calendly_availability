<?php

namespace Drupal\calendly_availability\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\Config;

/**
 * Defines a form to configure Calendly API settings for multiple environments.
 */
class CalendlySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'calendly_availability_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['calendly_availability.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('calendly_availability.settings');
    $form['#tree'] = TRUE;

    $form['instructions'] = [
      '#markup' => $this->t('<p>Configure the credentials for each of your environments. The module will automatically use the settings that match the current website\'s URL. You will need a separate OAuth App in Calendly for each environment.</p>'),
    ];

    $form['stats_link'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p><strong>Need utilization insights?</strong> Visit the <a href=":stats">Calendly Tours &amp; Meetings dashboard</a> to see tour/orientation performance and export-friendly counts.</p>', [
        ':stats' => Url::fromRoute('calendly_availability.stats')->toString(),
      ]),
      '#weight' => -50,
    ];
    
    // --- Production Environment Settings ---
    $form['production'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Production Environment'),
    ];
    $form['production']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('The base URL of your live site (e.g., https://www.makehaven.org).'),
      '#default_value' => $config->get('production_base_url'),
    ];
    $form['production']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('production_client_id'),
    ];
    $form['production']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('production_client_secret'),
    ];

    // --- Testing Environment Settings ---
    $form['testing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Testing Environment'),
    ];
    $form['testing']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('The base URL of your test site (e.g., https://test.makehaven.org).'),
      '#default_value' => $config->get('testing_base_url'),
    ];
    $form['testing']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('testing_client_id'),
    ];
    $form['testing']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('testing_client_secret'),
    ];

    // --- Development Environment Settings ---
    $form['development'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Development Environment'),
    ];
    $form['development']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('The base URL of your local dev site (e.g., http://localhost).'),
      '#default_value' => $config->get('development_base_url'),
    ];
    $form['development']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('development_client_id'),
    ];
    $form['development']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('development_client_secret'),
    ];
    
    // Webhook Signing Key (Optional) - Global
    $form['webhook_signing_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Calendly Webhook Signing Key (Global)'),
      '#default_value' => $config->get('webhook_signing_key'),
      '#description' => $this->t('Enter the Webhook Signing Key for Calendly webhooks, if using.'),
      '#required' => FALSE,
    ];

    $form['stats'] = [
      '#type' => 'details',
      '#title' => $this->t('Statistics defaults'),
      '#open' => TRUE,
    ];
    $form['stats']['stats_default_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default lookback window (days)'),
      '#default_value' => $config->get('stats_default_days') ?? 30,
      '#min' => 1,
      '#description' => $this->t('How many days of Calendly history to include when no explicit range is provided.'),
    ];
    $form['stats']['stats_availability_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Availability window (days)'),
      '#default_value' => $config->get('stats_availability_window_days') ?? 14,
      '#min' => 1,
      '#description' => $this->t('Future window for counting available slots per staff member.'),
    ];
    $form['stats']['stats_tour_keywords'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tour keywords'),
      '#default_value' => $config->get('stats_tour_keywords') ?? 'tour',
      '#description' => $this->t('Comma-separated keywords that identify tour event types.'),
    ];
    $form['stats']['stats_orientation_keywords'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Orientation keywords'),
      '#default_value' => $config->get('stats_orientation_keywords') ?? 'orientation,orient,safety,walk',
      '#description' => $this->t('Comma-separated keywords that identify orientation event types.'),
    ];

    $form['alerts'] = [
      '#type' => 'details',
      '#title' => $this->t('Failure alerts'),
      '#open' => TRUE,
      '#description' => $this->t('Where to send notifications when token refresh fails or the Calendly API errors. Alerts are rate-limited to once every 6 hours per failure type.'),
    ];
    $form['alerts']['alert_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Alert email'),
      '#default_value' => $config->get('alert_email') ?? 'staff@makehaven.org',
      '#description' => $this->t('Leave blank to use the default (staff@makehaven.org).'),
    ];
    $form['alerts']['alert_slack_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alert Slack channel'),
      '#default_value' => $config->get('alert_slack_channel') ?? '#tech',
      '#description' => $this->t('Slack channel (with or without #) to post to via slack_connector. Leave blank to skip Slack alerts.'),
    ];

    // --- Authorization Button ---
    $current_credentials = self::getCredentialsForCurrentHost(\Drupal::config('calendly_availability.settings'));
    if (!empty($current_credentials['client_id'])) {
      $redirect_uri = Url::fromRoute('calendly_availability.oauth_callback', [], ['absolute' => TRUE])->toString();
      $authorize_url = 'https://auth.calendly.com/oauth/authorize?' . http_build_query([
        'client_id' => $current_credentials['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $redirect_uri,
      ]);

      $form['authorize_calendly'] = [
        '#type' => 'markup',
        '#markup' => '<p><a href="' . $authorize_url . '" class="button button--primary" target="_self">' . $this->t('Authorize with Calendly for this Environment') . '</a></p>',
        '#description' => $this->t('Clicking will use the credentials for the current host: <strong>@host</strong>. Save any changes before authorizing.', ['@host' => \Drupal::request()->getSchemeAndHttpHost()]),
        '#allowed_tags' => ['a', 'strong', 'p'],
      ];
    }
    else {
      $form['authorize_missing_creds'] = [
        '#markup' => '<p><strong>' . $this->t('Please configure and save the settings for the current host (@host) to enable authorization.', ['@host' => \Drupal::request()->getSchemeAndHttpHost()]) . '</strong></p>',
      ];
    }
    
    $state = \Drupal::state();
    $token = $state->get('calendly_availability.personal_access_token');
    $expires_at = $state->get('calendly_availability.token_expires_at');
    $authorized_env = $state->get('calendly_availability.authorized_environment');
    $cooldown_until = (int) $state->get('calendly_availability.refresh_cooldown_until', 0);
    $date_formatter = \Drupal::service('date.formatter');

    if (!empty($token) && $expires_at && time() < $expires_at) {
      $expires_label = $date_formatter->format($expires_at, 'custom', 'Y-m-d H:i:s');
      $token_status_markup = $this->t('Authorized. Token expires at @time.', ['@time' => $expires_label]);
    }
    elseif (!empty($token) && !$expires_at) {
      $token_status_markup = $this->t('Authorized. Token is stored (no expiry recorded).');
    }
    else {
      $token_status_markup = '<strong style="color:red">' . $this->t('Not Authorized. Re-authorize using the button above.') . '</strong>';
    }

    if (!empty($authorized_env)) {
      $token_status_markup .= '<br>' . $this->t('Authorized environment: <strong>@env</strong> (used for background refresh).', ['@env' => $authorized_env]);
    }
    if ($cooldown_until && time() < $cooldown_until) {
      $token_status_markup .= '<br><strong style="color:#b94a00">' . $this->t('Refresh paused (cooldown) until @when after a recent 400/401 from Calendly. Re-authorize to clear.', [
        '@when' => $date_formatter->format($cooldown_until, 'custom', 'Y-m-d H:i:s'),
      ]) . '</strong>';
    }

    $form['token_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Current Authorization Status'),
      '#markup' => $token_status_markup,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('calendly_availability.settings');
    $environments = ['production', 'testing', 'development'];

    foreach ($environments as $env) {
      $values = $form_state->getValue($env);
      $config->set($env . '_base_url', rtrim($values['base_url'], '/'));
      $config->set($env . '_client_id', $values['client_id']);
      $config->set($env . '_client_secret', $values['client_secret']);
    }

    $config->set('webhook_signing_key', $form_state->getValue('webhook_signing_key'));

    $statsValues = $form_state->getValue('stats');
    $config
      ->set('stats_default_days', max(1, (int) ($statsValues['stats_default_days'] ?? 30)))
      ->set('stats_availability_window_days', max(1, (int) ($statsValues['stats_availability_window_days'] ?? 14)))
      ->set('stats_tour_keywords', $statsValues['stats_tour_keywords'] ?? 'tour')
      ->set('stats_orientation_keywords', $statsValues['stats_orientation_keywords'] ?? 'orientation,orient');

    $alertValues = $form_state->getValue('alerts');
    $config
      ->set('alert_email', trim((string) ($alertValues['alert_email'] ?? '')))
      ->set('alert_slack_channel', trim((string) ($alertValues['alert_slack_channel'] ?? '')));

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Supported environment keys, in preference order.
   */
  const ENVIRONMENTS = ['production', 'testing', 'development'];

  /**
   * Helper to get credentials for the current host.
   *
   * Used during the interactive OAuth Authorize flow, where a real HTTP
   * request with a trustworthy host is available. For background refresh
   * (cron, queue, CLI) use getRefreshCredentials() instead — it anchors
   * on the environment the admin last authorized against rather than
   * whatever host the current process happens to see.
   */
  public static function getCredentialsForCurrentHost(Config $config) {
    $env = self::resolveEnvironmentForCurrentHost($config);
    if ($env !== NULL) {
      $client_id = $config->get($env . '_client_id');
      $client_secret = $config->get($env . '_client_secret');
      if (!empty($client_id) && !empty($client_secret)) {
        return ['client_id' => $client_id, 'client_secret' => $client_secret];
      }
    }

    // Legacy top-level credentials.
    if ($config->get('client_id') && $config->get('client_secret')) {
      return [
        'client_id' => $config->get('client_id'),
        'client_secret' => $config->get('client_secret'),
      ];
    }

    return [];
  }

  /**
   * Resolves which environment config applies to the current HTTP host.
   *
   * Returns the env key ('production', 'testing', 'development') or NULL
   * when the current host matches no configured base_url and no fallback
   * is safe. The "any env with credentials" fallback is permitted only
   * when the current host clearly looks like local dev (lando.site,
   * localhost, 127.0.0.1) — sending a production refresh token to a
   * development OAuth app's endpoint used to be how this integration
   * got silently killed.
   */
  public static function resolveEnvironmentForCurrentHost(Config $config): ?string {
    $current_host = \Drupal::request()->getSchemeAndHttpHost();

    foreach (self::ENVIRONMENTS as $env) {
      $base_url = $config->get($env . '_base_url');
      if (!empty($base_url) && strcasecmp($base_url, $current_host) == 0) {
        return $env;
      }
    }

    if (self::isLocalDevHost($current_host)) {
      foreach (self::ENVIRONMENTS as $env) {
        if (!empty($config->get($env . '_client_id')) && !empty($config->get($env . '_client_secret'))) {
          return $env;
        }
      }
    }

    return NULL;
  }

  /**
   * Credentials for background refresh.
   *
   * Prefers the environment key stored at authorize time
   * (`calendly_availability.authorized_environment` state). Cron and
   * other non-HTTP contexts can't trust getSchemeAndHttpHost(), so
   * anchoring on the authorize-time env prevents signing a refresh with
   * the wrong OAuth app — which would trigger HTTP 400 and (before this
   * fix) wipe the stored tokens.
   */
  public static function getRefreshCredentials(Config $config): array {
    $env = \Drupal::state()->get('calendly_availability.authorized_environment');
    if ($env && in_array($env, self::ENVIRONMENTS, TRUE)) {
      $client_id = $config->get($env . '_client_id');
      $client_secret = $config->get($env . '_client_secret');
      if (!empty($client_id) && !empty($client_secret)) {
        return ['client_id' => $client_id, 'client_secret' => $client_secret];
      }
    }
    return self::getCredentialsForCurrentHost($config);
  }

  /**
   * Tells whether a URL looks like a local dev environment.
   */
  protected static function isLocalDevHost(string $host): bool {
    $lower = strtolower($host);
    return str_contains($lower, 'lndo.site')
      || str_contains($lower, 'localhost')
      || str_contains($lower, '127.0.0.1')
      || str_contains($lower, '.test')
      || str_contains($lower, '.local');
  }

}
