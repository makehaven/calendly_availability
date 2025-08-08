<?php

namespace Drupal\calendly_availability\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\calendly_availability\Form\CalendlySettingsForm;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for handling Calendly OAuth and diagnostics.
 */
class CalendlyAuthController extends ControllerBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new CalendlyAuthController.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * The configuration factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   * The HTTP client.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client')
    );
  }

  /**
   * OAuth callback to handle the authorization code exchange.
   */
  public function callback(Request $request) {
    $settings_route = 'calendly_availability.settings';
    $code = $request->query->get('code');

    if (!$code) {
      $this->messenger()->addError($this->t('Authorization code not found in the request.'));
      return $this->redirect($settings_route);
    }

    $config = $this->config('calendly_availability.settings');
    $credentials = CalendlySettingsForm::getCredentialsForCurrentHost($config);

    if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
      $this->messenger()->addError($this->t('Could not find Client ID or Secret for the current host: @host', ['@host' => $request->getSchemeAndHttpHost()]));
      return $this->redirect($settings_route);
    }

    try {
      $response = $this->httpClient->post('https://auth.calendly.com/oauth/token', [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => Url::fromRoute('calendly_availability.oauth_callback', [], ['absolute' => TRUE])->toString(),
          'client_id' => $credentials['client_id'],
          'client_secret' => $credentials['client_secret'],
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (!empty($data['access_token'])) {
        $this->configFactory->getEditable('calendly_availability.settings')
          ->set('personal_access_token', $data['access_token'])
          ->set('refresh_token', $data['refresh_token'])
          ->set('token_expires_at', time() + ($data['expires_in'] ?? 7200))
          ->save();
        $this->messenger()->addStatus($this->t('Successfully connected to Calendly API.'));
      }
      else {
        $this->messenger()->addError($this->t('Failed to obtain access token from Calendly.'));
      }
    }
    catch (\Exception $e) {
      $this->logger('calendly_availability')->error('Error in OAuth callback: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred during Calendly OAuth process. See logs for details.'));
    }

    return $this->redirect($settings_route);
  }

  /**
   * Provides diagnostic information for Calendly API connectivity.
   */
  public function diagnostics() {
    $config = $this->config('calendly_availability.settings');
    $personal_access_token = trim($config->get('personal_access_token'));
    $messages = [];

    if (empty($personal_access_token)) {
      $messages[] = $this->t('Personal Access Token is missing. Please authorize the module on the settings page.');
    }
    else {
      $messages[] = $this->t('Personal Access Token is configured.');
      try {
        $response = $this->httpClient->get('https://api.calendly.com/users/me', [
          'headers' => [
            'Authorization' => 'Bearer ' . $personal_access_token,
            'Accept' => 'application/json',
          ],
        ]);
        $userData = json_decode($response->getBody(), TRUE);
        $messages[] = $this->t('Successfully connected to Calendly API. User: @user', ['@user' => $userData['resource']['name']]);
      }
      catch (\Exception $e) {
        $messages[] = $this->t('Error connecting to Calendly API: @error', ['@error' => $e->getMessage()]);
        $this->logger('calendly_availability')->error('Error connecting to Calendly API: @error', ['@error' => $e->getMessage()]);
      }
    }

    return [
      '#theme' => 'item_list',
      '#items' => $messages,
      '#title' => $this->t('Calendly Availability Diagnostics'),
    ];
  }

}