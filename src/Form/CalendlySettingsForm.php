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
    
    $form['token_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Current Authorization Status'),
        '#markup' => !empty($config->get('personal_access_token')) ? $this->t('Authorized. Token is stored.') : $this->t('Not Authorized.'),
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
    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Helper to get credentials for the current host.
   */
  public static function getCredentialsForCurrentHost(Config $config) {
    $current_host = \Drupal::request()->getSchemeAndHttpHost();
    $environments = ['production', 'testing', 'development'];

    foreach ($environments as $env) {
      $base_url = $config->get($env . '_base_url');
      if (!empty($base_url) && strcasecmp($base_url, $current_host) == 0) {
        return [
          'client_id' => $config->get($env . '_client_id'),
          'client_secret' => $config->get($env . '_client_secret'),
        ];
      }
    }
    return [];
  }
}