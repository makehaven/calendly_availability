<?php

namespace Drupal\calendly_availability\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error; // For logging exceptions
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'CalendlyAvailabilityBlock' block.
 *
 * @Block(
 * id = "calendly_availability_block",
 * admin_label = @Translation("Calendly Availability Block"),
 * )
 */
class CalendlyAvailabilityBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $httpClient;
  protected $logger;
  protected $dateFormatter;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ClientInterface $http_client,
    LoggerInterface $logger,
    DateFormatterInterface $date_formatter
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->dateFormatter = $date_formatter;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory')->get('calendly_availability'),
      $container->get('date.formatter')
    );
  }

  /**
   * Ensures the access token is valid, refreshing it if necessary.
   */
  private function _getValidAccessToken() {
    $config = \Drupal::configFactory()->getEditable('calendly_availability.settings');
    $accessToken = $config->get('personal_access_token');
    $refreshToken = $config->get('refresh_token');
    $expiresAt = $config->get('token_expires_at');

    if ((!$expiresAt || time() > ($expiresAt - 300)) && $refreshToken) {
      $this->logger->info('Calendly access token is expired or expiring soon. Attempting to refresh.');
      try {
        $client_id = $config->get('client_id');
        $client_secret = $config->get('client_secret');

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
          $newAccessToken = $data['access_token'];
          $newRefreshToken = $data['refresh_token'];
          $newExpiresIn = $data['expires_in'];

          $config
            ->set('personal_access_token', $newAccessToken)
            ->set('refresh_token', $newRefreshToken)
            ->set('token_expires_at', time() + $newExpiresIn)
            ->save();

          $this->logger->info('Successfully refreshed Calendly access token.');
          return $newAccessToken;
        }
      } catch (RequestException $e) {
        $this->logger->error('Failed to refresh Calendly access token: @message', ['@message' => $e->getMessage()]);
        $config->set('personal_access_token', '')->set('refresh_token', '')->save();
        return NULL;
      }
    }

    return $accessToken;
  }

  public function defaultConfiguration() {
    return [
      'event_type_keywords' => '',
      'button_action_text' => $this->t('Schedule'),
      'days_to_show' => 7,
      'selected_event_type_uris' => [],
      'display_mode' => 'week_table',
      'fallback_url' => '',
      'fallback_link_text' => $this->t('None of these times work for me'),
      'owner_name_display_slot' => 'first_name_only',
      'hide_empty_time_columns' => FALSE,
      'hide_empty_day_rows' => FALSE,
      'no_results_message' => $this->t('No slots currently available. Please check back later.'),
    ] + parent::defaultConfiguration();
  }

  protected function _determine_owner_display_name(array $et, array &$user_names_cache, array $headers, string $current_user_uri = NULL, string $current_user_name = '') {
    $owner_display_name = 'Unknown Owner';
    $event_name_val = $et['name'] ?? 'N/A';
    if (isset($et['profile']['type'], $et['profile']['name'], $et['profile']['owner'])) {
        $profile_type = $et['profile']['type'];
        $profile_name_from_obj = $et['profile']['name'];
        $profile_owner_uri = $et['profile']['owner'];
        if ($profile_type === 'Team') {
            $owner_display_name = $profile_name_from_obj . ' (Team)';
        } elseif ($profile_type === 'User') {
            $owner_display_name = $profile_name_from_obj;
            if ($profile_owner_uri) { $user_names_cache[$profile_owner_uri] = $owner_display_name; }
        } else { $this->logger->warning('Event "@event" has a profile object, but with an unknown type: "@type". Profile data: @profile', ['@event' => $event_name_val, '@type' => $profile_type, '@profile' => json_encode($et['profile'])]); }
    } elseif (isset($et['user'])) {
        $event_owner_user_uri = $et['user'];
        if (isset($user_names_cache[$event_owner_user_uri])) { $owner_display_name = $user_names_cache[$event_owner_user_uri]; }
        else { if ($event_owner_user_uri === $current_user_uri && !empty($current_user_name)) { $owner_display_name = $current_user_name; $user_names_cache[$event_owner_user_uri] = $owner_display_name; }
            else { try { $user_details_response = $this->httpClient->get($event_owner_user_uri, ['headers' => $headers]); $user_details_data = json_decode($user_details_response->getBody()->getContents(), TRUE);
                    if (isset($user_details_data['resource']['name'])) { $owner_display_name = $user_details_data['resource']['name']; $user_names_cache[$event_owner_user_uri] = $owner_display_name; }
                    else { $owner_display_name = 'User (Name N/A)'; } }
                catch (RequestException $user_fetch_ex) { $this->logger->warning('Failed to fetch user details for URI @uri (via direct "user" field). Message: @message', ['@uri' => $event_owner_user_uri, '@message' => $user_fetch_ex->getMessage()]); $owner_display_name = 'User (Fetch Failed)'; }}}}
    else { $this->logger->warning('Could not determine owner for event type "@name" using profile or direct user field. Defaulting to "Unknown Owner". Event Data: @event_data', ['@name' => $event_name_val, '@event_data' => json_encode($et)]);}
    return $owner_display_name;
  }

  protected function _get_available_event_types_for_form() {
    $this->logger->debug('CALLED: _get_available_event_types_for_form at ' . date('Y-m-d H:i:s'));
    $token = $this->_getValidAccessToken();
    if (empty($token)) { $this->logger->warning('Cannot fetch event types for form: Calendly token is missing or invalid.'); return []; }
    $headers = ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'];
    $event_options = []; $user_names_cache = [];
    try {
      $current_user_details_response = $this->httpClient->get('https://api.calendly.com/users/me', ['headers' => $headers]);
      $current_user_data = json_decode($current_user_details_response->getBody()->getContents(), TRUE);
      if (!isset($current_user_data['resource']['uri'])) { $this->logger->error('Failed to get current user URI for form event types. Response: @resp', ['@resp' => json_encode($current_user_data)]); return []; }
      $current_user_uri = $current_user_data['resource']['uri'];
      $current_user_name = $current_user_data['resource']['name'] ?? 'Current User';
      $user_names_cache[$current_user_uri] = $current_user_name;
      $organization_uri = $current_user_data['resource']['current_organization'] ?? NULL;
      $event_types_fetch_url = '';
      if ($organization_uri) {
        $this->logger->info('Fetching event types for organization: @org_uri', ['@org_uri' => $organization_uri]);
        $event_types_fetch_url = 'https://api.calendly.com/event_types?organization=' . urlencode($organization_uri) . '&active=true&count=100&sort=name:asc';
      } else {
        $this->logger->info('Organization URI not found for current user @user_uri. Fetching event types for current user only.', ['@user_uri' => $current_user_uri]);
        $event_types_fetch_url = 'https://api.calendly.com/event_types?user=' . urlencode($current_user_uri) . '&active=true&count=100&sort=name:asc';
      }
      $event_types_response = $this->httpClient->get($event_types_fetch_url, ['headers' => $headers]);
      $event_types_data = json_decode($event_types_response->getBody()->getContents(), TRUE);
      if (!empty($event_types_data['collection'])) {
        $this->logger->info('Received @count event types in the collection for form. Processing them.', ['@count' => count($event_types_data['collection'])]);
        foreach ($event_types_data['collection'] as $et) {
            if (isset($et['uri'], $et['name'])) {
                $event_uri_val = $et['uri']; $event_name_val = $et['name'];
                $owner_display_name = $this->_determine_owner_display_name($et, $user_names_cache, $headers, $current_user_uri, $current_user_name);
                $status_label = ($et['active'] ?? FALSE) ? 'Active' : 'Inactive';
                $event_options[$event_uri_val] = $event_name_val . ' (' . $owner_display_name . ' - ' . $status_label . ')';
            } else { $this->logger->debug('Skipping an event type from form collection due to missing URI or name field: @event_data', ['@event_data' => json_encode($et)]); }
        }
        if (!empty($event_options)) { asort($event_options); }
      } else { $this->logger->info('No event types collection was found in the API response for form. Raw API response data: @data', ['@data' => json_encode($event_types_data)]);}
    } catch (RequestException $e) { $this->logger->error('Failed to fetch Calendly event types for block form: @message. Vars: @vars', ['@message' => $e->getMessage(),'@vars' => Error::decodeException($e),]); return []; }
      catch (\Exception $e) { $this->logger->error('A general error occurred while fetching Calendly event types for block form: @message. Vars: @vars', ['@message' => $e->getMessage(),'@vars' => Error::decodeException($e),]); return []; }
    return $event_options;
  }

  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['module_settings_link'] = [
        '#type' => 'item',
        '#markup' => $this->t('Manage Calendly API credentials or re-authorize on the <a href=":settings_url">main Calendly Settings page</a>. This might be needed if the token expires.', [
            ':settings_url' => Url::fromRoute('calendly_availability.settings')->toString()
        ]),
        '#weight' => -20,
    ];

    $form['selection_mode_notice'] = [
        '#type' => 'item',
        '#markup' => $this->t('<strong>Note:</strong> If you select specific event types below, those selections will be used. The "Event Type Keywords" filter will be ignored. If no specific event types are selected, the keyword filter will apply.'),
        '#weight' => -10,
    ];

    $form['selected_event_type_uris_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Select Specific Event Types (Overrides Keywords)'),
      '#weight' => -5,
    ];

    $calendly_token = $this->_getValidAccessToken();
    if (empty($calendly_token)) {
        $form['selected_event_type_uris_fieldset']['token_missing_message'] = [
            '#type' => 'item',
            '#markup' => $this->t('Calendly API token is not configured or is invalid. Please <a href=":settings_url">configure the API settings</a> to load and select event types.', [':settings_url' => Url::fromRoute('calendly_availability.settings')->toString()]),
        ];
    } else {
        $available_event_types = $this->_get_available_event_types_for_form();
        if (empty($available_event_types)) {
            $form['selected_event_type_uris_fieldset']['no_events_message'] = [
                '#type' => 'item',
                '#markup' => $this->t('Could not fetch event types from Calendly, or no event types are available. Check Drupal logs for errors. Ensure the API token has correct permissions for event types (potentially organization-level). You may need to re-authorize on the main Calendly Settings page.'),
            ];
        } else {
            $form['selected_event_type_uris_fieldset']['selected_event_type_uris'] = [
              '#type' => 'checkboxes',
              '#title' => $this->t('Choose Event Types'),
              '#options' => $available_event_types,
              '#default_value' => $config['selected_event_type_uris'] ?? [],
              '#description' => $this->t('Select the specific event types you want to display. If none are selected, the keyword filter below will be used.'),
            ];
        }
    }

    $form['event_type_keywords'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event Type Keywords (Fallback)'),
      '#description' => $this->t('Enter comma-separated keywords to filter Calendly event types. Used ONLY if no specific event types are selected above.'),
      '#default_value' => $config['event_type_keywords'],
      '#weight' => 0,
    ];

    $form['display_settings_fieldset'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Display & Fallback Settings'),
        '#weight' => 10,
    ];
    $form['display_settings_fieldset']['display_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Display Mode'),
      '#options' => [
        'list' => $this->t('List View'),
        'week_table' => $this->t('Weekly Schedule Table View'),
      ],
      '#default_value' => $config['display_mode'],
    ];

    $form['display_settings_fieldset']['button_action_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button Action Text'),
      '#description' => $this->t('Text to prefix the time on scheduling buttons (e.g., "Schedule", "Book"). Default: "Schedule".'),
      '#default_value' => $config['button_action_text'],
    ];

    $form['display_settings_fieldset']['owner_name_display_slot'] = [
      '#type' => 'select',
      '#title' => $this->t('Staff Name Display in Slot Details'),
      '#options' => [
        'first_name_only' => $this->t('First Name Only (e.g., "With J.R.")'),
        'full_name' => $this->t('Full Name (e.g., "With John Logan")'),
        'hide' => $this->t('Do Not Display Staff Name'),
      ],
      '#default_value' => $config['owner_name_display_slot'],
      '#description' => $this->t('Choose how to display the staff/owner name for each slot.'),
    ];

    $form['display_settings_fieldset']['days_to_show'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to Show Availability For'),
      '#description' => $this->t('Number of days from today to fetch and display availability for.'),
      '#default_value' => $config['days_to_show'],
      '#min' => 1,
      '#max' => 90,
    ];
    
    $form['display_settings_fieldset']['fallback_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Fallback Scheduling URL'),
        '#description' => $this->t('If no available slots are found, users can be directed to this URL.'),
        '#default_value' => $config['fallback_url'],
    ];

    $form['display_settings_fieldset']['fallback_link_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Fallback Link Text'),
        '#description' => $this->t('Text for the "None of these times work for me" link, if a fallback URL is provided.'),
        '#default_value' => $config['fallback_link_text'],
    ];
    
    $form['display_settings_fieldset']['no_results_message'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Custom "No Results" Message'),
        '#default_value' => $config['no_results_message'],
        '#description' => $this->t('Message displayed if no slots are found and no fallback URL is used.'),
    ];
    
    $form['display_settings_fieldset']['hide_empty_time_columns'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide empty time columns (Weekly View)'),
      '#default_value' => $config['hide_empty_time_columns'],
      '#description' => $this->t('If checked, time blocks that have no slots across all shown days will be hidden from the table.'),
    ];
    $form['display_settings_fieldset']['hide_empty_day_rows'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide empty day rows (Weekly View)'),
      '#default_value' => $config['hide_empty_day_rows'],
      '#description' => $this->t('If checked, days that have no slots across all visible time blocks will be hidden from the table.'),
    ];
    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();

    if (isset($values['selected_event_type_uris_fieldset']['selected_event_type_uris'])) {
        $this->configuration['selected_event_type_uris'] = array_keys(array_filter($values['selected_event_type_uris_fieldset']['selected_event_type_uris']));
    } else {
        if (isset($values['selected_event_type_uris_fieldset'])) {
             $this->configuration['selected_event_type_uris'] = [];
        }
    }

    $this->configuration['event_type_keywords'] = $values['event_type_keywords'];
    
    $display_settings_values = $values['display_settings_fieldset'];
    $this->configuration['display_mode'] = $display_settings_values['display_mode'];
    $this->configuration['button_action_text'] = $display_settings_values['button_action_text'];
    $this->configuration['owner_name_display_slot'] = $display_settings_values['owner_name_display_slot'];
    $this->configuration['days_to_show'] = $display_settings_values['days_to_show'];
    $this->configuration['fallback_url'] = $display_settings_values['fallback_url'];
    $this->configuration['fallback_link_text'] = $display_settings_values['fallback_link_text'];
    $this->configuration['no_results_message'] = $display_settings_values['no_results_message'];
    $this->configuration['hide_empty_time_columns'] = $display_settings_values['hide_empty_time_columns'];
    $this->configuration['hide_empty_day_rows'] = $display_settings_values['hide_empty_day_rows'];
  }

  protected function _prepare_weekly_schedule_data(array $processed_slots) {
    $schedule = [];
    $config = $this->getConfiguration();
    
    $time_blocks_definition_source = [
        'morning'   => ['label_code' => 'Morning',   'start_hour' => 9,  'end_hour' => 11],
        'midday'    => ['label_code' => 'Mid Day',   'start_hour' => 11, 'end_hour' => 14],
        'afternoon' => ['label_code' => 'Afternoon', 'start_hour' => 14, 'end_hour' => 17],
        'evening'   => ['label_code' => 'Evening',   'start_hour' => 17, 'end_hour' => 20],
        'late'      => ['label_code' => 'Late',      'start_hour' => 20, 'end_hour' => 23],
    ];
    $time_blocks_definition = [];
    foreach($time_blocks_definition_source as $key => $def) {
        $start_label = $this->dateFormatter->format(strtotime("{$def['start_hour']}:00"), 'custom', 'g A');
        $end_label = $this->dateFormatter->format(strtotime("{$def['end_hour']}:00"), 'custom', 'g A');
        $time_range_label = $start_label . ' - ' . $end_label;
        $time_blocks_definition[$key] = [
            'label' => $this->t($def['label_code']) . ' (' . $time_range_label . ')',
            'start_hour' => $def['start_hour'],
            'end_hour' => $def['end_hour'],
        ];
    }

    $site_timezone_string = date_default_timezone_get();
    $site_timezone = new \DateTimeZone($site_timezone_string);
    $days_to_show_count = (int) ($config['days_to_show'] ?? 7);
    $start_date = new \DateTimeImmutable('now', $site_timezone);
    
    $display_days_info = []; 
    $day_keys_ordered_init = [];

    for ($i = 0; $i < $days_to_show_count; $i++) {
        $current_day_iterate = $start_date->modify("+$i days");
        $day_key = $current_day_iterate->format('Y-m-d');
        $day_keys_ordered_init[] = $day_key;
        $display_days_info[$day_key] = [
            'name' => $this->dateFormatter->format($current_day_iterate->getTimestamp(), 'custom', 'l'),
            'date_full' => $this->dateFormatter->format($current_day_iterate->getTimestamp(), 'custom', 'F j, Y'),
        ];
        $schedule[$day_key] = [];
        foreach (array_keys($time_blocks_definition) as $block_key) {
            $schedule[$day_key][$block_key] = ['slots' => []];
        }
    }
    
    foreach ($processed_slots as $slot) {
        try {
            $utc_dt = new \DateTimeImmutable($slot['start_time_raw'], new \DateTimeZone('UTC'));
            $local_dt = $utc_dt->setTimezone($site_timezone);
            $slot_day_key = $local_dt->format('Y-m-d');
            $slot_hour = (int) $local_dt->format('G'); 

            if (isset($schedule[$slot_day_key])) { 
                foreach ($time_blocks_definition as $block_key => $block_def) {
                    if ($slot_hour >= $block_def['start_hour'] && $slot_hour < $block_def['end_hour']) {
                        $schedule[$slot_day_key][$block_key]['slots'][] = $slot;
                        break; 
                    }
                }
            }
        } catch (\Exception $e) { $this->logger->error('Error processing date for weekly schedule: @message for slot @slot', ['@message' => $e->getMessage(), '@slot' => json_encode($slot)]); }
    }

    $final_time_block_headers = $time_blocks_definition;
    if ($config['hide_empty_time_columns']) {
        $active_block_keys = [];
        foreach (array_keys($time_blocks_definition) as $block_key) {
            foreach ($day_keys_ordered_init as $day_key_check) {
                 if (isset($schedule[$day_key_check]) && !empty($schedule[$day_key_check][$block_key]['slots'])) {
                    $active_block_keys[$block_key] = TRUE;
                    break; 
                }
            }
        }
        $final_time_block_headers = array_intersect_key($time_blocks_definition, $active_block_keys);
    }

    $final_day_keys_ordered = [];
    $final_display_days_info_filtered = [];
    $final_days_data = [];

    if ($config['hide_empty_day_rows']) {
        foreach ($day_keys_ordered_init as $day_key) {
            $day_has_slots = FALSE;
            foreach (array_keys($final_time_block_headers) as $block_key) { 
                if (isset($schedule[$day_key][$block_key]) && !empty($schedule[$day_key][$block_key]['slots'])) {
                    $day_has_slots = TRUE;
                    break;
                }
            }
            if ($day_has_slots) {
                $final_day_keys_ordered[] = $day_key;
                if(isset($display_days_info[$day_key])) $final_display_days_info_filtered[$day_key] = $display_days_info[$day_key];
                if(isset($schedule[$day_key])) $final_days_data[$day_key] = $schedule[$day_key];
            }
        }
    } else {
        $final_day_keys_ordered = $day_keys_ordered_init;
        $final_display_days_info_filtered = $display_days_info;
        $final_days_data = $schedule;
    }

    return [
        'days_data' => $final_days_data, 
        'day_keys_ordered' => $final_day_keys_ordered, 
        'display_days_info' => $final_display_days_info_filtered, 
        'time_block_headers' => $final_time_block_headers, 
    ];
  }

  public function build() {
    $token = $this->_getValidAccessToken();
    if (empty($token)) { return ['#markup' => $this->t('Calendly API is not configured or token is invalid. Please <a href="@link">configure settings</a>.', ['@link' => Url::fromRoute('calendly_availability.settings')->toString()])]; }

    $config = $this->getConfiguration();
    try {
      $this->logger->info('--- Starting Calendly Availability Fetch for block display ---');
      $selected_uris = $config['selected_event_type_uris'] ?? [];
      $keywords_string = $config['event_type_keywords'] ?? '';
      $keywords = !empty($keywords_string) ? array_map('trim', explode(',', strtolower($keywords_string))) : [];
      $days_to_show = (int) ($config['days_to_show'] ?? 7);

      if (!empty($selected_uris)) { $this->logger->info('Filtering by explicitly selected URIs: @uris', ['@uris' => json_encode($selected_uris)]); }
      else { $this->logger->info('Using keyword filter: "@keywords"', ['@keywords' => $keywords_string]); }
      $this->logger->info('Days to show: @days', ['@days' => $days_to_show]);

      $available_slots_raw = $this->getConsolidatedAvailability($token, $selected_uris, $keywords, $days_to_show);

      $fallback_url = $config['fallback_url'];
      if (empty($available_slots_raw) && !empty($fallback_url)) {
        return [
          '#theme' => 'calendly_availability_fallback',
          '#fallback_url' => $fallback_url,
          '#message_for_fallback' => $this->t($config['no_results_message']),
          '#fallback_link_text_config' => $this->t($config['fallback_link_text']),
          '#cache' => ['max-age' => 300],
        ];
      }
      if (empty($available_slots_raw) && empty($fallback_url)) {
        return ['#markup' => $this->t($config['no_results_message']), '#cache' => ['max-age' => 300]];
      }

      $processed_slots = [];
      foreach ($available_slots_raw as $slot) {
        try {
            $timestamp = strtotime($slot['start_time']);
            $owner_name_full = $slot['event_owner_name'] ?? '';
            $owner_name_display_text = '';
            if ($config['owner_name_display_slot'] !== 'hide' && !empty($owner_name_full)) {
                $temp_owner_name = trim(str_replace('(Team)', '', $owner_name_full));
                if ($config['owner_name_display_slot'] === 'first_name_only') {
                    $parts = explode(' ', $temp_owner_name);
                    $owner_name_display_text = $parts[0]; 
                } else { 
                    $owner_name_display_text = $temp_owner_name;
                }
            }
            $processed_slots[] = [
                'event_name' => $slot['event_name'],
                'event_owner_name_display' => trim($owner_name_display_text),
                'start_time_raw' => $slot['start_time'],
                'date_formatted' => $this->dateFormatter->format($timestamp, 'custom', 'l, F j, Y'),
                'time_formatted_for_button' => $this->dateFormatter->format($timestamp, 'custom', 'g:ia'),
                'time_formatted_verbose' => $this->dateFormatter->format($timestamp, 'custom', 'g:i A T'),
                'invitees_remaining' => $slot['invitees_remaining'],
                'booking_url' => $slot['booking_url'],
            ];
        } catch (\Exception $e) { $this->logger->error('Error processing slot start_time for display: @message for slot "@slot_name"', ['@message' => $e->getMessage(), '@slot_name' => ($slot['event_name'] ?? 'Unknown event')]); }
      }
      
      $this->logger->info('Successfully processed @count available slots for display.', ['@count' => count($processed_slots)]);
      
      $display_mode = $config['display_mode'];
      $build_array = [
        '#button_action_text_config' => $this->t($config['button_action_text']),
        '#fallback_url' => $fallback_url, 
        '#fallback_link_text_config' => $this->t($config['fallback_link_text']),
        '#attached' => ['library' => ['calendly_availability/calendly_widget']],
        '#cache' => ['max-age' => $config['display_mode'] === 'week_table' ? 1800 : 3600],
      ];

      if ($display_mode === 'week_table') {
        $this->logger->info('Display mode: week_table. Preparing weekly schedule data.');
        $weekly_data = $this->_prepare_weekly_schedule_data($processed_slots);
        
        if (empty($weekly_data['day_keys_ordered']) || empty($weekly_data['time_block_headers'])) {
            if (empty($fallback_url)) {
                 return ['#markup' => $this->t($config['no_results_message']), '#cache' => ['max-age' => 300]];
            }
        }
        $build_array['#theme'] = 'calendly_availability_week_schedule';
        $build_array['#schedule_data'] = $weekly_data;
      } else { // List view
        if (empty($processed_slots) && empty($fallback_url)) {
             return ['#markup' => $this->t($config['no_results_message']), '#cache' => ['max-age' => 300]];
        }
        $build_array['#theme'] = 'calendly_availability_block';
        $build_array['#available_slots'] = $processed_slots;
      }
      return $build_array;

    } catch (RequestException $e) { $this->logger->error('Guzzle Request Error in build: @message.', ['@message' => $e->getMessage()]); return ['#markup' => $this->t('API connection issue during build.'), '#cache' => ['max-age' => 60]]; }
      catch (\Exception $e) { $this->logger->error('General Error in build: @message', ['@message' => $e->getMessage()]); return ['#markup' => $this->t('Unexpected error during build.'), '#cache' => ['max-age' => 60]]; }
  }

  /**
   * Fetches and consolidates availability slots from the Calendly API.
   * This version loops to get more than 7 days.
   */
  protected function getConsolidatedAvailability($token, array $selected_uris, array $keywords, $days_to_show) {
    $all_available_slots = [];
    $headers = ['Authorization' => "Bearer $token",'Content-Type' => 'application/json',];
    $this->logger->debug('Using token for API calls: @token_fragment...', ['@token_fragment' => substr($token, 0, 10)]);
    $user_uri = NULL; $current_user_data_for_runtime = NULL;
    $this->logger->info('Step 1: Fetching current user URI from /users/me...');
    try {
      $response = $this->httpClient->get('https://api.calendly.com/users/me', ['headers' => $headers]);
      $user_data_raw = $response->getBody()->getContents();
      $current_user_data_for_runtime = json_decode($user_data_raw, TRUE);
      if (isset($current_user_data_for_runtime['resource']['uri'])) {
        $user_uri = $current_user_data_for_runtime['resource']['uri'];
        $this->logger->info('Fetched user URI: @uri.', ['@uri' => $user_uri]);
      } else { $this->logger->warning('Could not determine user URI from /users/me.'); return []; }
    } catch (RequestException $e) { $this->logger->error('Guzzle error fetching user URI: @msg', ['@msg' => $e->getMessage()]); return [];}
    
    $current_user_name = $current_user_data_for_runtime['resource']['name'] ?? 'Current User';
    $runtime_user_names_cache = $user_uri ? [$user_uri => $current_user_name] : [];

    $raw_event_type_list_for_processing = [];
    if (!empty($selected_uris)) {
        $this->logger->info('Runtime: Fetching details for @count explicitly selected event URIs.', ['@count' => count($selected_uris)]);
        foreach($selected_uris as $uri) { try { $resp = $this->httpClient->get($uri, ['headers' => $headers]); $data = json_decode($resp->getBody()->getContents(), TRUE); if(isset($data['resource'])) $raw_event_type_list_for_processing[] = $data['resource']; } catch (\Exception $_e){ $this->logger->error('Failed to fetch selected URI @uri: @msg', ['@uri' => $uri, '@msg' => $_e->getMessage()]);}}
    } else {
        $organization_uri_for_runtime = $current_user_data_for_runtime['resource']['current_organization'] ?? null;
        $fetch_url = '';
        if ($organization_uri_for_runtime) {
            $fetch_url = 'https://api.calendly.com/event_types?organization=' . urlencode($organization_uri_for_runtime) . '&active=true&count=100&sort=name:asc';
            $this->logger->info('Runtime: No specific selections. Fetching event types for organization: @org_uri', ['@org_uri' => $organization_uri_for_runtime]);
        } elseif ($user_uri) { 
            $fetch_url = 'https://api.calendly.com/event_types?user=' . urlencode($user_uri) . '&active=true&count=100&sort=name:asc';
            $this->logger->info('Runtime: No specific selections or org URI. Fetching event types for current user: @user_uri', ['@user_uri' => $user_uri]);
        }
        if ($fetch_url) { try { $resp = $this->httpClient->get($fetch_url, ['headers' => $headers]); $data = json_decode($resp->getBody()->getContents(), TRUE); if(!empty($data['collection'])) $raw_event_type_list_for_processing = $data['collection']; else {$this->logger->info('Runtime: Fetched event types but collection is empty. URL: @url', ['@url' => $fetch_url]);}} catch (\Exception $_e){$this->logger->error('Failed to fetch event types from @url : @msg', ['@url' => $fetch_url, '@msg' => $_e->getMessage()]); return[];}}
        else { $this->logger->warning('Runtime: Could not determine URL to fetch event types (no org URI and no user URI).'); return[]; }
    }

    $relevant_event_types_processed = [];
    foreach ($raw_event_type_list_for_processing as $et_raw) {
        $owner_name = $this->_determine_owner_display_name($et_raw, $runtime_user_names_cache, $headers, $user_uri, $current_user_name);
        $et_with_owner = $et_raw; 
        $et_with_owner['event_owner_name'] = $owner_name; 
        if (empty($selected_uris)) { 
            if (!empty($keywords)) {
                $event_name_original = $et_raw['name'] ?? '';
                $event_name_lower = strtolower($event_name_original);
                $keyword_match = FALSE;
                foreach ($keywords as $keyword) { if (strpos($event_name_lower, $keyword) !== FALSE) { $keyword_match = TRUE; break; } }
                if ($keyword_match) $relevant_event_types_processed[] = $et_with_owner;
            } else { $relevant_event_types_processed[] = $et_with_owner; }
        } else { $relevant_event_types_processed[] = $et_with_owner; }
    }

    if (empty($relevant_event_types_processed)) { $this->logger->info('No relevant event types found after owner processing and keyword filtering.'); return []; }
    $this->logger->info('Processed @count relevant event types with owner names.', ['@count' => count($relevant_event_types_processed)]);

    $max_days_per_request = 7; 

    foreach ($relevant_event_types_processed as $event_type_obj) {
        $event_type_uri = $event_type_obj['uri'];
        $event_type_name = $event_type_obj['name'] ?? 'Unknown Event Name';
        $event_scheduling_url = $event_type_obj['scheduling_url'] ?? NULL; 
        $event_owner_name_for_slot = $event_type_obj['event_owner_name']; 

        if (!$event_scheduling_url) { $this->logger->warning('Event type "@name" (URI: @uri) is missing a scheduling_url. Skipping.', ['@name' => $event_type_name, '@uri' => $event_type_uri]); continue; }
        
        for ($offset = 0; $offset < $days_to_show; $offset += $max_days_per_request) {
            // *** THE DEFINITIVE FIX: Recalculate the start time inside the loop ***
            $loop_start_date = new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC'));
            $chunk_start_date = $loop_start_date->modify("+$offset days");
            $days_in_this_chunk = min($max_days_per_request, $days_to_show - $offset);
            $chunk_end_date = $chunk_start_date->modify("+$days_in_this_chunk days");

            $start_time_param_slots = $chunk_start_date->format('Y-m-d\TH:i:s\Z');
            $end_time_param_slots = $chunk_end_date->format('Y-m-d\TH:i:s\Z');
            
            $this->logger->info('Fetching availability for @name from @start to @end', ['@name' => $event_type_name, '@start' => $start_time_param_slots, '@end' => $end_time_param_slots]);

            $query_params = ['event_type' => $event_type_uri, 'start_time' => $start_time_param_slots, 'end_time' => $end_time_param_slots];
            try {
                $response = $this->httpClient->get('https://api.calendly.com/event_type_available_times', ['headers' => $headers, 'query' => $query_params]);
                $availability_data = json_decode($response->getBody()->getContents(), TRUE);
                if (!empty($availability_data['collection'])) {
                    foreach ($availability_data['collection'] as $slot_item) {
                        if (($slot_item['status'] ?? 'not-available') === 'available') {
                            $all_available_slots[] = [
                                'event_name' => $event_type_name,
                                'event_owner_name' => $event_owner_name_for_slot,
                                'start_time' => $slot_item['start_time'],
                                'invitees_remaining' => $slot_item['invitees_remaining'],
                                'booking_url' => $event_scheduling_url,
                            ];
                        }
                    }
                }
            } catch (RequestException $e_slots) { $this->logger->error('Failed to fetch availability for ET "@name" in chunk: @msg', ['@name' => $event_type_name, '@msg' => $e_slots->getMessage()]); }
        }
    } 

    if (!empty($all_available_slots)) {
        $unique_slots = [];
        foreach ($all_available_slots as $slot) {
            $key = $slot['start_time'] . '_' . $slot['booking_url'];
            $unique_slots[$key] = $slot;
        }
        $all_available_slots = array_values($unique_slots);

        usort($all_available_slots, function($a, $b) { return strtotime($a['start_time']) - strtotime($b['start_time']); });
    }
    $this->logger->info('--- Finished Calendly Availability Fetch --- Returning @count slots.', ['@count' => count($all_available_slots)]); 
    return $all_available_slots;
  }
}