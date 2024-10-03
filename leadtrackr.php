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
 * Version:           1.0.1
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

define('LEADTRACKR_PLUGIN_VERSION', '1.0.1');

define('LEADTRACKR_API_NAMESPACE', 'leadtrackr/v1');
define('LEADTRACKR_API_BASE_URL', home_url('/wp-json/' . LEADTRACKR_API_NAMESPACE));
define('LEADTRACKR_LEAD_ENDPOINT', 'https://app.leadtrackr.io/api/leads/createLead');

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

    foreach ($form['fields'] as $field) {
        $data['formData']['formFields'][] = array(
            $field['label'] => $entry[$field['id']],
        );

        if (in_array($field['label'], leadtrackr_firstNamePossibleNames)) {
            $data['userData']['firstName'] = $entry[$field['id']];
        }

        if (in_array($field['label'], leadtrackr_lastNamePossibleNames)) {
            $data['userData']['lastName'] = $entry[$field['id']];
        }

        if ($field['inputType'] === 'email') {
            $data['userData']['email'] = $entry[$field['id']];
        }

        if (in_array($field['label'], leadtrackr_emailPossibleNames)) {
            $data['userData']['email'] = $entry[$field['id']];
        }

        if ($field['inputType'] === 'tel') {
            $data['userData']['phone'] = $entry[$field['id']];
        }

        if (in_array($field['label'], leadtrackr_phonePossibleNames)) {
            $data['userData']['phone'] = $entry[$field['id']];
        }

        if (in_array($field['label'], leadtrackr_companyPossibleNames)) {
            $data['userData']['company'] = $entry[$field['id']];
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
