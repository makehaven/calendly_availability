<?php

namespace Drupal\calendly_availability\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error; // For logging exceptions
use Drupal\calendly_availability\Service\CalendlySlotRepository;
use Drupal\calendly_availability\Service\CalendlyTokenTrait;
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

  use CalendlyTokenTrait;

  protected $httpClient;
  protected $logger;
  protected $dateFormatter;
  protected ConfigFactoryInterface $configFactory;
  protected CalendlySlotRepository $slotRepository;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ClientInterface $http_client,
    LoggerInterface $logger,
    DateFormatterInterface $date_formatter,
    ConfigFactoryInterface $config_factory,
    CalendlySlotRepository $slot_repository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->dateFormatter = $date_formatter;
    $this->configFactory = $config_factory;
    $this->slotRepository = $slot_repository;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory')->get('calendly_availability'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
      $container->get('calendly_availability.slot_repository')
    );
  }

  public function defaultConfiguration() {
    return [
      'event_type_keywords' => '',
      'button_action_text' => $this->t('Schedule'),
      'days_to_show' => 7,
      'selected_event_type_uris' => [],
      'stats_category' => '',
      'display_mode' => 'week_table',
      'fallback_url' => '',
      'fallback_link_text' => $this->t('None of these times work for me'),
      'owner_name_display_slot' => 'first_name_only',
      'hide_empty_time_columns' => FALSE,
      'hide_empty_day_rows' => FALSE,
      'no_results_message' => $this->t('No slots currently available. Please check back later.'),
      'post_schedule_url' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * Collect the current member's Calendly prefill data (name / email / phone).
   *
   * Best-effort: email is reliable; name from field_first_name/last_name; phone
   * from the member's 'main' profile if present. Missing pieces are simply not
   * prefilled (Calendly leaves those fields blank). Never throws.
   */
  protected function _currentUserPrefill(): array {
    $prefill = [];
    try {
      $account = \Drupal::currentUser();
      if (!$account->isAuthenticated()) {
        return $prefill;
      }
      $user = \Drupal\user\Entity\User::load($account->id());
      if (!$user) {
        return $prefill;
      }
      $first = $user->hasField('field_first_name') ? trim((string) $user->get('field_first_name')->value) : '';
      $last = $user->hasField('field_last_name') ? trim((string) $user->get('field_last_name')->value) : '';
      $name = trim($first . ' ' . $last);
      if ($name !== '') {
        $prefill['name'] = $name;
      }
      $email = $account->getEmail();
      if (!empty($email)) {
        $prefill['email'] = $email;
      }
      if (\Drupal::moduleHandler()->moduleExists('profile')) {
        $profile = \Drupal::entityTypeManager()->getStorage('profile')->loadByUser($user, 'main');
        if ($profile && $profile->hasField('field_preferred_phone')) {
          $phone = trim((string) $profile->get('field_preferred_phone')->value);
          if ($phone !== '') {
            $prefill['phone'] = $phone;
          }
        }
      }
    }
    catch (\Exception $e) {
      // Prefill is a convenience; never let it break the block render.
      $this->logger->debug('Calendly prefill skipped: @m', ['@m' => $e->getMessage()]);
    }
    return $prefill;
  }

  protected function _get_available_event_types_for_form() {
    $this->logger->debug('CALLED: _get_available_event_types_for_form at ' . date('Y-m-d H:i:s'));
    $token = $this->getValidAccessToken();
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
        $this->logger->debug('Fetching event types for organization: @org_uri', ['@org_uri' => $organization_uri]);
        $event_types_fetch_url = 'https://api.calendly.com/event_types?organization=' . urlencode($organization_uri) . '&active=true&count=100&sort=name:asc';
      } else {
        $this->logger->debug('Organization URI not found for current user @user_uri. Fetching event types for current user only.', ['@user_uri' => $current_user_uri]);
        $event_types_fetch_url = 'https://api.calendly.com/event_types?user=' . urlencode($current_user_uri) . '&active=true&count=100&sort=name:asc';
      }
      $event_types_response = $this->httpClient->get($event_types_fetch_url, ['headers' => $headers]);
      $event_types_data = json_decode($event_types_response->getBody()->getContents(), TRUE);
      if (!empty($event_types_data['collection'])) {
        $this->logger->debug('Received @count event types in the collection for form. Processing them.', ['@count' => count($event_types_data['collection'])]);
        foreach ($event_types_data['collection'] as $et) {
            if (isset($et['uri'], $et['name'])) {
                $event_uri_val = $et['uri']; $event_name_val = $et['name'];
                $owner_display_name = $this->slotRepository->determineOwnerDisplayName($et, $user_names_cache, ['headers' => $headers], $current_user_uri, $current_user_name);
                $status_label = ($et['active'] ?? FALSE) ? 'Active' : 'Inactive';
                $event_options[$event_uri_val] = $event_name_val . ' (' . $owner_display_name . ' - ' . $status_label . ')';
            } else { $this->logger->debug('Skipping an event type from form collection due to missing URI or name field: @event_data', ['@event_data' => json_encode($et)]); }
        }
        if (!empty($event_options)) { asort($event_options); }
      } else { $this->logger->debug('No event types collection was found in the API response for form. Raw API response data: @data', ['@data' => json_encode($event_types_data)]);}
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

    $calendly_token = $this->getValidAccessToken();
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

    $form['selected_event_type_uris_fieldset']['stats_category'] = [
      '#type' => 'select',
      '#title' => $this->t('Stats category override'),
      '#description' => $this->t('If set, the Calendly stats dashboard will treat the selected event types from this block as the chosen category.'),
      '#options' => [
        '' => $this->t('Auto (keyword detection)'),
        'tour' => $this->t('Tours'),
        'orientation' => $this->t('Orientations'),
        'other' => $this->t('Other meetings'),
      ],
      '#default_value' => $config['stats_category'] ?? '',
    ];

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

    $form['display_settings_fieldset']['post_schedule_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('After-booking destination (path)'),
        '#default_value' => $config['post_schedule_url'],
        '#description' => $this->t('Internal path (e.g. <code>/involve</code>) to offer as the next step after a booking completes. When set, the Calendly popup closes on success and a "You\'re booked — continue" banner links here (with a gentle auto-advance). Leave empty to just close the popup.'),
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
    $this->configuration['stats_category'] = $values['selected_event_type_uris_fieldset']['stats_category'] ?? '';

    $this->configuration['event_type_keywords'] = $values['event_type_keywords'];
    
    $display_settings_values = $values['display_settings_fieldset'];
    $this->configuration['display_mode'] = $display_settings_values['display_mode'];
    $this->configuration['button_action_text'] = $display_settings_values['button_action_text'];
    $this->configuration['owner_name_display_slot'] = $display_settings_values['owner_name_display_slot'];
    $this->configuration['days_to_show'] = $display_settings_values['days_to_show'];
    $this->configuration['fallback_url'] = $display_settings_values['fallback_url'];
    $this->configuration['fallback_link_text'] = $display_settings_values['fallback_link_text'];
    $this->configuration['no_results_message'] = $display_settings_values['no_results_message'];
    $this->configuration['post_schedule_url'] = trim($display_settings_values['post_schedule_url'] ?? '');
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
    $config = $this->getConfiguration();
    $token = $this->getValidAccessToken();
    if (empty($token)) {
      return $this->renderFallbackOrAdminError($config, 'Calendly API is not configured or token is invalid.');
    }

    try {
      // Slot data comes from the repository's cache, kept warm by cron.
      // Worst case (cold or expired cache) the repository refreshes inline
      // with concurrent API calls; on failure it serves the last known
      // good data, however old.
      $payload = $this->slotRepository->getSlots($config);
      if ($payload === NULL) {
        return $this->renderFallbackOrAdminError($config, 'Calendly availability could not be loaded.');
      }
      $available_slots_raw = $payload['slots'];

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
      
      $this->logger->debug('Successfully processed @count available slots for display.', ['@count' => count($processed_slots)]);
      
      $display_mode = $config['display_mode'];
      // Prefill the popup with the member's details so they don't retype what
      // we already collected, plus the after-booking destination. Because this
      // carries the current user's own name/email/phone, the block is cached
      // per user (the 'user' cache context below).
      $prefill = $this->_currentUserPrefill();
      $build_array = [
        '#button_action_text_config' => $this->t($config['button_action_text']),
        '#fallback_url' => $fallback_url,
        '#fallback_link_text_config' => $this->t($config['fallback_link_text']),
        '#attached' => [
          'library' => [
            'calendly_availability/calendly_widget',
            'calendly_availability/calendly_availability',
            'calendly_availability/schedule',
          ],
          'drupalSettings' => [
            'calendlyAvailability' => [
              'prefill' => $prefill,
              'postScheduleUrl' => $config['post_schedule_url'] ?? '',
            ],
          ],
        ],
        '#cache' => [
          'max-age' => $config['display_mode'] === 'week_table' ? 1800 : 3600,
          'contexts' => ['user'],
        ],
      ];

      if ($display_mode === 'week_table') {
        $this->logger->debug('Display mode: week_table. Preparing weekly schedule data.');
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

    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
      $this->logger->error('Guzzle Request Error in build (HTTP @code): @message.', [
        '@code' => $status,
        '@message' => $e->getMessage(),
      ]);
      \Drupal::state()->set('calendly_availability.last_api_error_time', time());
      if (\Drupal::hasService('calendly_availability.alerts')) {
        \Drupal::service('calendly_availability.alerts')->notifyApiFailure('block_build', sprintf('HTTP %d: %s', $status, $e->getMessage()));
      }
      return $this->renderFallbackOrAdminError($config, 'Calendly API connection issue.');
    }
    catch (\Exception $e) {
      $this->logger->error('General Error in build: @message', ['@message' => $e->getMessage()]);
      return $this->renderFallbackOrAdminError($config, 'Unexpected error during build.');
    }
  }

  /**
   * Renders the configured fallback link, or an admin-facing error as a
   * last resort when no fallback URL is configured.
   */
  protected function renderFallbackOrAdminError(array $config, string $adminMessage): array {
    $fallback_url = $config['fallback_url'] ?? '';
    if (!empty($fallback_url)) {
      return [
        '#theme' => 'calendly_availability_fallback',
        '#fallback_url' => $fallback_url,
        '#message_for_fallback' => $this->t($config['no_results_message'] ?? 'Availability could not be loaded. Please use the booking link below.'),
        '#fallback_link_text_config' => $this->t($config['fallback_link_text'] ?? 'Book a time'),
        '#cache' => ['max-age' => 60],
      ];
    }
    return [
      '#markup' => $this->t('@msg Please <a href="@link">configure settings</a>.', [
        '@msg' => $adminMessage,
        '@link' => Url::fromRoute('calendly_availability.settings')->toString(),
      ]),
      '#cache' => ['max-age' => 60],
    ];
  }

}
