<?php

/**
 * Main plugin file.
 *
 * @link              https://leadtrackr.io/
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       LeadTrackr
 * Description:       LeadTrackr description
 * Version:           1.0.6
 * Author:            LeadTrackr
 * Author URI:        https://leadtrackr.io/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires PHP:      7.0
 * Requires at least: 6.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use FluentForm\App\Helpers\Helper;

define('LEADTRACKR_PLUGIN_VERSION', '1.0.6');

define('LEADTRACKR_API_NAMESPACE', 'leadtrackr/v1');
define('LEADTRACKR_API_BASE_URL', home_url('/wp-json/' . LEADTRACKR_API_NAMESPACE));
// define('LEADTRACKR_LEAD_ENDPOINT', 'https://app.leadtrackr.io/api/leads/createLead');
define('LEADTRACKR_LEAD_ENDPOINT', 'https://webhook.site/f2180f09-4c5f-4570-b75b-7c7f84ed1525');

// Create the settings page
function leadtrackr_create_menu()
{
    $svg_xml = '<?xml version="1.0" encoding="utf-8"?>' . '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.7991 1H8.19939H1V4.37938H8.19939V12H11.7991V4.37938H19V1H11.7991Z" fill="#52B483"/><path d="M15.4003 15.5657H4.5997V8H1V15.5657V19H4.5997H15.4003H19V15.5657V8H15.4003V15.5657Z" fill="#52B483"/></svg>';
    $icon = sprintf(('data:image/svg+xml;base64,%s'), base64_encode($svg_xml));

    add_menu_page(
        "LeadTrackr",
        "LeadTrackr",
        "manage_options",
        "leadtrackr",
        "leadtrackr_settings_page",
        $icon
    );
}
add_action('admin_menu', 'leadtrackr_create_menu');

function leadtrackr_list_recursive_iterate_elements($elements, &$forms)
{
    /**
     * Start our loop.
     */
    foreach ($elements as $element) {
        /**
         * Check if our form.
         */
        if (isset($element->widgetType) && $element->widgetType == 'form') {
            $forms[] = $element;
        }
        /**
         * Check if we have elements.
         */
        if (empty($element->elements) === false) {
            $recursive = leadtrackr_list_recursive_iterate_elements($element->elements, $forms);
        }
    }
}
/**
 * Get all elementor forms.
 * This function retrieves data from wp_meta_table.
 * @param string $offset. The offset for pagination.
 *
 * @return array. Returns array of all form with relevant data or null if no forms.
 */
function leadtrackr_list_get_elementor_forms($offset = 0)
{
    global $wpdb;
    /**
     * Get the forms now.
     */
    $results = $wpdb->get_results("SELECT a.ID, b.meta_value FROM $wpdb->posts a, $wpdb->postmeta b WHERE a.post_type NOT IN ('draft', 'revision') AND a.post_status = 'publish' AND a.ID = b.post_id AND b.meta_key = '_elementor_data' LIMIT 100000");
    /**
     * Check if empty.
     */
    if (empty($results)) {
        return null;
    }
    /**
     * Set vars.
     */
    $all_forms = [];
    /**
     * Now loop over results, extract form data.
     */
    foreach ($results as $result) {
        /**
         * Decode the data first. They are stored in json format.
         */
        $data = json_decode($result->meta_value);
        /**
         * Only proceed if object.
         */

        if (is_array($data) === false || empty($data) === true) {
            continue;
        }
        /**
         * Set vars.
         */
        $forms = [];
        /**
         * Start recursive iteration.
         */
        $iteration = leadtrackr_list_recursive_iterate_elements($data, $forms);
        /**
         * Set data to all forms.
         */
        $all_forms[$result->ID] = $forms;
    }
    /**
     * Now filter the form data.
     */
    return $all_forms;
}

function leadtrackr_get_global_data()
{
    $gravity_forms_enabled = class_exists('GFForms');
    $gravity_forms_forms = get_option('leadtrackr_gf_forms', array());
    if ($gravity_forms_enabled) {
        $gf_forms = GFAPI::get_forms();
        // Map GFAPI forms to include only ID and title with default 'sendToLeadTrackr'
        $gravity_forms_forms = array_map(function ($form) use ($gravity_forms_forms) {
            $form_data = array(
                'id' => $form['id'],
                'title' => $form['title'],
                'sendToLeadTrackr' => false,
                'customTitle' => '',
            );

            // Check if this form ID exists in leadtrackr_gf_forms
            $leadtrackr_form = array_filter($gravity_forms_forms, function ($leadtrackr_form) use ($form_data) {
                return $leadtrackr_form['id'] === $form_data['id'];
            });

            $leadtrackr_form = reset($leadtrackr_form);

            if ($leadtrackr_form) {
                // Merge the data from leadtrackr_gf_forms with GFAPI form data
                return array_merge($form_data, $leadtrackr_form);
            }

            return $form_data;
        }, $gf_forms);
    }

    $cf7_enabled = class_exists('WPCF7_ContactForm');
    $cf7_forms_forms = get_option('leadtrackr_cf7_forms', array());
    if ($cf7_enabled) {
        $cf7_forms = WPCF7_ContactForm::find();
        $cf7_forms_forms = array_map(function ($form) use ($cf7_forms_forms) {
            $form_data = array(
                'id' => $form->id(),
                'title' => $form->title(),
                'sendToLeadTrackr' => false,
                'customTitle' => '',
            );


            $leadtrackr_form = array_filter($cf7_forms_forms, function ($leadtrackr_form) use ($form_data) {
                return $leadtrackr_form['id'] === $form_data['id'];
            });

            $leadtrackr_form = reset($leadtrackr_form);

            if ($leadtrackr_form) {
                return array_merge($form_data, $leadtrackr_form);
            }

            return $form_data;
        }, $cf7_forms);
    }

    $elementor_enabled = is_plugin_active('elementor-pro/elementor-pro.php');
    $elementor_forms_forms = get_option('leadtrackr_elementor_forms', array());
    if ($elementor_enabled) {
        $results = leadtrackr_list_get_elementor_forms();

        $elementor_forms = [];

        foreach ($results as $page_id => $forms) {
            /**
             * Now loop over sub-forms for page-id.
             */
            foreach ($forms as $form) {
                $form->page_id = strval($page_id);
                $elementor_forms[] = $form;
            }
        }

        $elementor_forms_forms = array_map(function ($form) use ($elementor_forms_forms) {
            $form_data = array(
                'id' => $form->page_id . "_" . $form->id,
                'title' => $form->settings->form_name,
                'sendToLeadTrackr' => false,
                'customTitle' => '',
            );

            $leadtrackr_form = array_filter($elementor_forms_forms, function ($leadtrackr_form) use ($form_data) {
                return $leadtrackr_form['id'] === $form_data['id'];
            });

            $leadtrackr_form = reset($leadtrackr_form);

            if ($leadtrackr_form) {
                return array_merge($form_data, $leadtrackr_form);
            }

            return $form_data;
        }, $elementor_forms);
    }

    $wpforms_enabled = class_exists('WPForms');
    $wpforms_forms_forms = get_option('leadtrackr_wpforms_forms', array());
    if ($wpforms_enabled) {
        $wpforms_forms = WPForms()->form->get();
        $wpforms_forms_forms = array_map(function ($form) use ($wpforms_forms_forms) {
            $form_data = array(
                'id' => $form->ID,
                'title' => $form->post_title,
                'sendToLeadTrackr' => false,
                'customTitle' => '',
            );

            $leadtrackr_form = array_filter($wpforms_forms_forms, function ($leadtrackr_form) use ($form_data) {
                return $leadtrackr_form['id'] === $form_data['id'];
            });

            $leadtrackr_form = reset($leadtrackr_form);

            if ($leadtrackr_form) {
                return array_merge($form_data, $leadtrackr_form);
            }

            return $form_data;
        }, $wpforms_forms);
    }

    $fluent_forms_enabled = is_plugin_active('fluentform/fluentform.php');
    $fluent_forms_forms = get_option('leadtrackr_fluent_forms_forms', array());
    if ($fluent_forms_enabled) {
        $ff_forms = Helper::getForms(); // all forms with form id as keyed array
        foreach ($ff_forms as $id => $form_name) {
            $form_data = array(
                'id' => $id,
                'title' => $form_name,
                'sendToLeadTrackr' => false,
                'customTitle' => '',
            );

            $leadtrackr_form = array_filter($fluent_forms_forms, function ($leadtrackr_form) use ($form_data) {
                return $leadtrackr_form['id'] === $form_data['id'];
            });

            $leadtrackr_form = reset($leadtrackr_form);
            
            if ($leadtrackr_form) {
                // Merge and replace form based on ID in fluent_forms_forms
                foreach ($fluent_forms_forms as $index => $existing_form) {
                    if ($existing_form['id'] === $form_data['id']) {
                        $fluent_forms_forms[$index] = array_merge($form_data, $leadtrackr_form);
                        break;
                    }
                }
            } else {
                $fluent_forms_forms[] = $form_data;
            }
        }
    }

    $current_theme = wp_get_theme();
    $divi_theme_enabled = $current_theme->get('Name') === 'Divi';
    $divi_process_contact_form = get_option('leadtrackr_divi_process_contact_form', false);

    return array(
        'apiUrl' => LEADTRACKR_API_BASE_URL,
        'projectId' => get_option('leadtrackr_project_id', ''),
        'gravityForms' => array(
            'enabled' => $gravity_forms_enabled,
            'forms' => $gravity_forms_forms,
        ),
        'cf7' => array(
            'enabled' => $cf7_enabled,
            'forms' => $cf7_forms_forms,
        ),
        'elementor' => array(
            'enabled' => $elementor_enabled,
            'forms' => $elementor_forms_forms,
        ),
        'wpforms' => array(
            'enabled' => $wpforms_enabled,
            'forms' => $wpforms_forms_forms,
        ),
        'fluentForms' => array(
            'enabled' => $fluent_forms_enabled,
            'forms' => $fluent_forms_forms,
        ),
        'divi' => array(
            'enabled' => $divi_theme_enabled,
            'processContactForm' => $divi_process_contact_form,
        ),
    );
}

// Render the settings page
function leadtrackr_settings_page()
{
    echo '<div id="leadtrackr-app-settings"></div>';

    leadtrackr_enqueue_scripts();
}

function leadtrackr_enqueue_scripts()
{
    wp_enqueue_script(
        'leadtrackr-app-js',
        plugin_dir_url(__FILE__) . 'app/dist/assets/index.js',
        array(),
        LEADTRACKR_PLUGIN_VERSION,
        null
    );

    wp_enqueue_style(
        'leadtrackr-app-css',
        plugin_dir_url(__FILE__) . 'app/dist/assets/index.css',
        array(),
        LEADTRACKR_PLUGIN_VERSION
    );


    wp_localize_script('leadtrackr-app-js', 'wpData', leadtrackr_get_global_data());
}


function leadtrackr_register_rest_api()
{
    register_rest_route(LEADTRACKR_API_NAMESPACE, '/project-id', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $project_id = $request->get_json_params()['project_id'];
            update_option('leadtrackr_project_id', $project_id);
            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => '__return_true',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/gravity-forms', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $leadtrackr_gf_forms = $request->get_json_params()['forms'];
            update_option('leadtrackr_gf_forms', $leadtrackr_gf_forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => '__return_true',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/contact-form-7', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $leadtrackr_cf7_forms = $request->get_json_params()['forms'];
            update_option('leadtrackr_cf7_forms', $leadtrackr_cf7_forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => '__return_true',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/elementor', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $leadtrackr_elementor_forms = $request->get_json_params()['forms'];
            update_option('leadtrackr_elementor_forms', $leadtrackr_elementor_forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => '__return_true',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/wpforms', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $leadtrackr_wpforms_forms = $request->get_json_params()['forms'];
            update_option('leadtrackr_wpforms_forms', $leadtrackr_wpforms_forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        }
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/fluent-forms', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $leadtrackr_fluent_forms_forms = $request->get_json_params()['forms'];
            update_option('leadtrackr_fluent_forms_forms', $leadtrackr_fluent_forms_forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/divi', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $process_contact_form = $request->get_json_params()['processContactForm'];
            update_option('leadtrackr_divi_process_contact_form', $process_contact_form);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
    ));
}

add_action('rest_api_init', 'leadtrackr_register_rest_api');

define('leadtrackr_firstNamePossibleNames', array('first_name', 'firstName', 'first-name', 'First Name', 'name', 'Name', 'voornaam', 'naam', 'Voornaam', 'Naam'));
define('leadtrackr_lastNamePossibleNames', array('last_name', 'lastName', 'last-name', 'Last Name', 'surname', 'Surname', 'achternaam', 'Achternaam'));
define('leadtrackr_emailPossibleNames', array('email', 'Email', 'e-mail', 'E-mail', 'e-mail address', 'E-mail Address', 'email address', 'Email Address', 'emailadres', 'Emailadres', 'e-mailadres', 'E-mailadres'));
define('leadtrackr_phonePossibleNames', array('phone', 'Phone', 'phone number', 'Phone Number', 'telefoon', 'Telefoon', 'telefoonnummer', 'Telefoonnummer'));
define('leadtrackr_companyPossibleNames', array('company', 'Company', 'company name', 'Company Name', 'bedrijf', 'Bedrijf', 'bedrijfsnaam', 'Bedrijfsnaam'));

function leadtrackr_parse_attributes_data()
{
    $attributes_data = array();

    if (!isset($_COOKIE)) {
        return $attributes_data;
    }

    $cid_cookie = '';

    if (isset($_COOKIE['FPID'])) {
        $cid_cookie = sanitize_text_field(wp_unslash($_COOKIE['FPID']));
        $parts = explode('.', $cid_cookie);
        $cid_cookie = implode('.', array_slice($parts, 2));
    } else if (isset($_COOKIE['_ga'])) {
        $cid_cookie = sanitize_text_field(wp_unslash($_COOKIE['_ga']));
        $parts = explode('.', $cid_cookie);
        $cid_cookie = implode('.', array_slice($parts, 2));
    }

    if (isset($_COOKIE['_fbc'])) {
        $attributes_data['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
    }

    if (isset($_COOKIE['_fbp'])) {
        $attributes_data['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
    }

    if (isset($_COOKIE['_gcl_aw'])) {
        $cookie_parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE['_gcl_aw'])));
        if (isset($cookie_parts[2])) {
            $attributes_data['gclid'] =  $cookie_parts[2];
        }
    }

    if (isset($_COOKIE['_gcl_gb'])) {
        $cookie_parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE['_gcl_gb'])));
        if (isset($cookie_parts[2])) {
            $attributes_data['wbraid'] =  $cookie_parts[2];
        }
    }

    if ($cid_cookie !== '') {
        $attributes_data['cid'] = $cid_cookie;
    }

    if (isset($_COOKIE['_ga'])) {
        $sid_cookie = sanitize_text_field(wp_unslash($_COOKIE['_ga']));
        $parts = explode('.', $sid_cookie);
        $stripped = implode('.', array_slice($parts, 2));
        $attributes_data['sid'] = explode('$', $stripped)[0];
    }

    return $attributes_data;
}

/**
 * Handle Gravity Forms submission.
 *
 * @param array $entry An array containing the entry data.
 * @param array|GF_Form $form The form object or array.
 */
function leadtrackr_gravity_forms_submission($entry, $form)
{
    $leadtrackr_gf_forms = get_option('leadtrackr_gf_forms', array());
    $form_id = $form['id'];

    $leadtrackr_form = array_filter($leadtrackr_gf_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);


    if (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr']) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form['id'],
            'formName' => $form['title'],
            'customFormName' => $leadtrackr_form['customTitle'] ?? '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => $entry['ip'],
            'userAgent' => $entry['user_agent'],
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    if (isset($_COOKIE['lt_channelflow'])) {
        $data['lt_channelflow'] = sanitize_text_field(wp_unslash($_COOKIE['lt_channelflow']));
    }

    foreach ($form['fields'] as $field) {
        if (isset($entry[$field['id']])) {
            $data['formData']['formFields'][] = array(
                $field['label'] => $entry[$field['id']],
            );

            $sanitized_label = sanitize_text_field($field['label']);
            $sanitized_label = str_replace(':', '', $sanitized_label);

            if (in_array($field['label'], leadtrackr_firstNamePossibleNames)) {
                $data['userData']['firstName'] = $entry[$field['id']];
            } else if (in_array($sanitized_label, leadtrackr_firstNamePossibleNames)) {
                $data['userData']['firstName'] = $entry[$field['id']];
            }

            if (in_array($field['label'], leadtrackr_lastNamePossibleNames)) {
                $data['userData']['lastName'] = $entry[$field['id']];
            } else if (in_array($sanitized_label, leadtrackr_lastNamePossibleNames)) {
                $data['userData']['lastName'] = $entry[$field['id']];
            }

            if ($field['inputType'] === 'email') {
                $data['userData']['email'] = $entry[$field['id']];
            }

            if (in_array($field['label'], leadtrackr_emailPossibleNames)) {
                $data['userData']['email'] = $entry[$field['id']];
            } else if (in_array($sanitized_label, leadtrackr_emailPossibleNames)) {
                $data['userData']['email'] = $entry[$field['id']];
            }

            if ($field['inputType'] === 'tel' || $field['inputType'] === 'phone') {
                $data['userData']['phone'] = $entry[$field['id']];
            }

            if (in_array($field['label'], leadtrackr_phonePossibleNames)) {
                $data['userData']['phone'] = $entry[$field['id']];
            } else if (in_array($sanitized_label, leadtrackr_phonePossibleNames)) {
                $data['userData']['phone'] = $entry[$field['id']];
            }

            if (in_array($field['label'], leadtrackr_companyPossibleNames)) {
                $data['userData']['company'] = $entry[$field['id']];
            } else if (in_array($sanitized_label, leadtrackr_companyPossibleNames)) {
                $data['userData']['company'] = $entry[$field['id']];
            }
        }
    }

    $response = wp_remote_post(LEADTRACKR_LEAD_ENDPOINT, array(
        'body' => wp_json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending Gravity Forms submission to LeadTrackr: ' . $response->get_error_message());
    }
}

add_action('gform_after_submission', 'leadtrackr_gravity_forms_submission', 10, 2);


/**
 * Handle Contact Form 7 submission.
 *
 * @param WPCF7_ContactForm $contact_form
 */
function leadtrackr_cf7_submission($contact_form)
{
    $leadtrackr_cf7_forms = get_option('leadtrackr_cf7_forms', array());
    $form_id = $contact_form->id();

    $leadtrackr_form = array_filter($leadtrackr_cf7_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr']) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $contact_form->id(),
            'formName' => $contact_form->title(),
            'customFormName' => $leadtrackr_form['customTitle'] ?? '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    if (isset($_COOKIE['lt_channelflow'])) {
        $data['lt_channelflow'] = sanitize_text_field(wp_unslash($_COOKIE['lt_channelflow']));
    }

    if (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
        $data['deviceData']['ipAddress'] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    if (isset($_SERVER['HTTP_USER_AGENT']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
        $data['deviceData']['userAgent'] = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
    }

    $submission = WPCF7_Submission::get_instance();

    if (!$submission) {
        error_log('LeadTrackr: Error getting Contact Form 7 submission with form ID: ' . $contact_form->id());
        return;
    }

    $all_form_fields = array();

    foreach ($submission->get_posted_data() as $key => $value) {
        $data['formData']['formFields'][$key] = $value;

        $all_form_fields[] = $key;
    }

    foreach (leadtrackr_firstNamePossibleNames as $possibleName) {
        if ($submission->get_posted_data($possibleName) && (($data['userData']['firstName'] ?? '') === '')) {
            $data['userData']['firstName'] = $submission->get_posted_data($possibleName);
            break;
        }

        if ((($data['userData']['firstName'] ?? '') === '')) {
            foreach ($all_form_fields as $field) {
                if (strpos($field, $possibleName) !== false) {
                    $data['userData']['firstName'] = $submission->get_posted_data($field);
                    break;
                }
            }
        }
    }

    foreach (leadtrackr_lastNamePossibleNames as $possibleName) {
        if ($submission->get_posted_data($possibleName) && (($data['userData']['lastName'] ?? '') === '')) {
            $data['userData']['lastName'] = $submission->get_posted_data($possibleName);
            break;
        }

        if ((($data['userData']['lastName'] ?? '') === '')) {
            foreach ($all_form_fields as $field) {
                if (strpos($field, $possibleName) !== false) {
                    $data['userData']['lastName'] = $submission->get_posted_data($field);
                    break;
                }
            }
        }
    }

    foreach (leadtrackr_emailPossibleNames as $possibleName) {
        if ($submission->get_posted_data($possibleName) && (($data['userData']['email'] ?? '') === '')) {
            $data['userData']['email'] = $submission->get_posted_data($possibleName);
            break;
        }

        if ((($data['userData']['email'] ?? '') === '')) {
            foreach ($all_form_fields as $field) {
                if (strpos($field, $possibleName) !== false) {
                    $data['userData']['email'] = $submission->get_posted_data($field);
                    break;
                }
            }
        }
    }

    if ((($data['userData']['email'] ?? '') === '')) {
        $emailField = $contact_form->scan_form_tags(['type' => 'email'])[0];
        $data['userData']['email'] = $submission->get_posted_data($emailField['name']);
    }

    foreach (leadtrackr_phonePossibleNames as $possibleName) {
        if ($submission->get_posted_data($possibleName) && (($data['userData']['phone'] ?? '') === '')) {
            $data['userData']['phone'] = $submission->get_posted_data($possibleName);
            break;
        }

        if ((($data['userData']['phone'] ?? '') === '')) {
            foreach ($all_form_fields as $field) {
                if (strpos($field, $possibleName) !== false) {
                    $data['userData']['phone'] = $submission->get_posted_data($field);
                    break;
                }
            }
        }
    }

    if ((($data['userData']['phone'] ?? '') === '')) {
        $phoneFields = $contact_form->scan_form_tags(['type' => 'tel']);

        if (!empty($phoneFields) && isset($phoneFields[0])) {
            $data['userData']['phone'] = $submission->get_posted_data($phoneFields[0]['name']);
        }
    }


    foreach (leadtrackr_companyPossibleNames as $possibleName) {
        if ($submission->get_posted_data($possibleName) && (($data['userData']['company'] ?? '') === '')) {
            $data['userData']['company'] = $submission->get_posted_data($possibleName);
            break;
        }

        if ((($data['userData']['company'] ?? '') === '')) {
            foreach ($all_form_fields as $field) {
                if (strpos($field, $possibleName) !== false) {
                    $data['userData']['company'] = $submission->get_posted_data($field);
                    break;
                }
            }
        }
    }

    $response = wp_remote_post(LEADTRACKR_LEAD_ENDPOINT, array(
        'body' => wp_json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending Contact Form 7 submission to LeadTrackr: ' . $response->get_error_message());
    }
}

add_action('wpcf7_mail_sent', 'leadtrackr_cf7_submission', 10, 1);

/**
 * Handle Elementor form submission.
 * 
 * @param ElementorPro\Modules\Forms\Classes\Form_Record $record The form record object.
 */
function leadtrackr_elementor_forms_submission($record)
{
    $leadtrackr_elementor_forms = get_option('leadtrackr_elementor_forms', array());
    $form_id = $record->get_form_settings('id');
    $form_post_id = $record->get_form_settings('form_post_id');

    if (!$form_id || !$form_post_id) {
        return;
    }

    $leadtrackr_form = array_filter($leadtrackr_elementor_forms, function ($leadtrackr_form) use ($form_id, $form_post_id) {
        return $leadtrackr_form['id'] === $form_post_id . "_" . $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr']) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form_post_id . "_" . $form_id,
            'formName' => $record->get_form_settings('form_name'),
            'customFormName' => $leadtrackr_form['customTitle'] ?? '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => $_SERVER['REMOTE_ADDR'],
            'userAgent' => $_SERVER['HTTP_USER_AGENT'],
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    if (isset($_COOKIE['lt_channelflow'])) {
        $data['lt_channelflow'] = sanitize_text_field(wp_unslash($_COOKIE['lt_channelflow']));
    }

    $fields = $record->get_formatted_data();

    foreach ($fields as $key => $value) {
        $data['formData']['formFields'][$key] = $value;

        if (in_array($key, leadtrackr_firstNamePossibleNames)) {
            $data['userData']['firstName'] = $value;
        }

        if (in_array($key, leadtrackr_lastNamePossibleNames)) {
            $data['userData']['lastName'] = $value;
        }

        if (in_array($key, leadtrackr_emailPossibleNames)) {
            $data['userData']['email'] = $value;
        }

        if (in_array($key, leadtrackr_phonePossibleNames)) {
            $data['userData']['phone'] = $value;
        }

        if (in_array($key, leadtrackr_companyPossibleNames)) {
            $data['userData']['company'] = $value;
        }
    }

    $response = wp_remote_post(LEADTRACKR_LEAD_ENDPOINT, array(
        'body' => wp_json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending Elementor form submission to LeadTrackr: ' . $response->get_error_message());
    }
}

add_action('elementor_pro/forms/new_record', 'leadtrackr_elementor_forms_submission', 10, 1);

/**
 * Handle WPForms submission.
 * 
 * @param array $fields The form fields.
 * @param array $entry The form entry.
 * @param array $form_data The form data.
 * @param int $entry_id The entry id.
 */
function leadtrackr_wpforms_forms_submission($fields, $entry, $form_data, $entry_id)
{
    $leadtrackr_wpforms_forms = get_option('leadtrackr_wpforms_forms', array());
    $form_id = (int)$form_data['id'];

    if (!$form_id) {
        return;
    }

    $leadtrackr_form = array_filter($leadtrackr_wpforms_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr']) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form_id,
            'formName' => $form_data['settings']['form_title'],
            'customFormName' => $leadtrackr_form['customTitle'] ?? '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => $_SERVER['REMOTE_ADDR'],
            'userAgent' => $_SERVER['HTTP_USER_AGENT'],
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    if (isset($_COOKIE['lt_channelflow'])) {
        $data['lt_channelflow'] = sanitize_text_field(wp_unslash($_COOKIE['lt_channelflow']));
    }

    foreach ($entry['fields'] as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $data['formData']['formFields'][$subKey] = $subValue;
            }
        } else {
            $label = $fields[$key]['name'];
            $data['formData']['formFields'][$label] = $value;

            $type = $fields[$key]['type'];
            if ($type === 'email') {
                $data['userData']['email'] = $value;
            }

            if ($type === 'phone') {
                $data['userData']['phone'] = $value;
            }
        }
    }

    foreach ($data['formData']['formFields'] as $key => $value) {
        if (in_array($key, leadtrackr_firstNamePossibleNames)) {
            $data['userData']['firstName'] = $value;
        }

        if (in_array($key, leadtrackr_lastNamePossibleNames)) {
            $data['userData']['lastName'] = $value;
        }

        if (in_array($key, leadtrackr_emailPossibleNames)) {
            $data['userData']['email'] = $value;
        }

        if (in_array($key, leadtrackr_phonePossibleNames)) {
            $data['userData']['phone'] = $value;
        }

        if (in_array($key, leadtrackr_companyPossibleNames)) {
            $data['userData']['company'] = $value;
        }
    }


    $response = wp_remote_post(LEADTRACKR_LEAD_ENDPOINT, array(
        'body' => wp_json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending WPForms submission to LeadTrackr: ' . $response->get_error_message());
    }

    return;
}

add_action('wpforms_process_complete', 'leadtrackr_wpforms_forms_submission', 10, 4);

function leadtrackr_fluent_forms_submission($submissionId, $formData, $form) {
    $form_id = (int)$form['id'];

    if (!$form_id) {
        return;
    }

    $leadtrackr_fluent_forms_forms = get_option('leadtrackr_fluent_forms_forms', array());
    $leadtrackr_form = array_filter($leadtrackr_fluent_forms_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr']) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form_id,
            'formName' => $form['title'],
            'customFormName' => $leadtrackr_form['customTitle'] ?? '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => $_SERVER['REMOTE_ADDR'],
            'userAgent' => $_SERVER['HTTP_USER_AGENT'],
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    if (isset($_COOKIE['lt_channelflow'])) {
        $data['lt_channelflow'] = sanitize_text_field(wp_unslash($_COOKIE['lt_channelflow']));
    }

    foreach ($formData as $key => $value) {
        if (in_array($key, array('__fluent_form_embded_post_id', '_fluentform_1_fluentformnonce', '_wp_http_referer'))) {
            continue;
        }

        $data['formData']['formFields'][$key] = $value;

        if (in_array($key, leadtrackr_firstNamePossibleNames)) {
            $data['userData']['firstName'] = $value;
        }

        if (in_array($key, leadtrackr_lastNamePossibleNames)) {
            $data['userData']['lastName'] = $value;
        }

        if (in_array($key, leadtrackr_emailPossibleNames)) {
            $data['userData']['email'] = $value;
        }

        if (in_array($key, leadtrackr_phonePossibleNames)) {
            $data['userData']['phone'] = $value;
        }

        if (in_array($key, leadtrackr_companyPossibleNames)) {
            $data['userData']['company'] = $value;
        }
    }

    $response = wp_remote_post(LEADTRACKR_LEAD_ENDPOINT, array(
        'body' => wp_json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending Fluent Forms submission to LeadTrackr: ' . $response->get_error_message());
    }

    return;
}

add_action('fluentform/submission_inserted', 'leadtrackr_fluent_forms_submission', 10, 3);

function leadtrackr_divi_contact_form_submission($processed_fields_values, $et_contact_error, $contact_form_info) {
    $divi_process_contact_form = get_option('leadtrackr_divi_process_contact_form', false);

    if (!$divi_process_contact_form) {
        return;
    }

    wp_remote_post(LEADTRACKR_LEAD_ENDPOINT, array(
        'body' => wp_json_encode(array(
            'processedFieldsValues' => $processed_fields_values,
            'etContactError' => $et_contact_error,
            'contactFormInfo' => $contact_form_info,
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    ));
}

add_action('et_pb_contact_form_submit', 'leadtrackr_divi_contact_form_submission', 10, 3);