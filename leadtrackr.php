<?php

/**
 * Main plugin file.
 *
 * @link              https://leadtrackr.io/
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       LeadTrackr
 * Description:       Capture form submissions and send lead data to LeadTrackr for offline conversion tracking, attribution, and channel flow analysis.
 * Version:           1.1.1
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

define('LEADTRACKR_PLUGIN_VERSION', '1.1.1');

define('LEADTRACKR_API_NAMESPACE', 'leadtrackr/v1');
define('LEADTRACKR_LEAD_ENDPOINT_LEGACY', 'https://app.leadtrackr.io/api/leads/createLead');
define('LEADTRACKR_LEAD_ENDPOINT', 'https://app.leadtrackr.io/api/leads/createServerSideLead');
// Pinned to the major version, so patches arrive without a plugin release but
// a breaking change never does.
define('LEADTRACKR_LEADBOT_SRC', 'https://cdn.jsdelivr.net/gh/leadtrackr/leadtrackr-leadbot@1/dist/lt-leadbot.min.js');

// The longest click ID any ad platform issues is well under this. Cookies are
// visitor-controlled and land on the lead, so a value that cannot plausibly be
// a click ID is dropped rather than stored.
define('LEADTRACKR_MAX_CLICK_ID_LENGTH', 512);

/**
 * Sites that already ran this plugin must not have their behaviour changed by an
 * update: Channel Flow stays off until they choose it, and the API token stays
 * optional. New installs get Channel Flow on and the token required.
 *
 * Decided once, on the first load after this version lands, by looking for
 * settings that only exist if the plugin was configured before.
 */
function leadtrackr_bootstrap_install_state()
{
    if (get_option('leadtrackr_install_state', false) !== false) {
        return;
    }

    $existing_settings = array(
        'leadtrackr_project_id',
        'leadtrackr_gf_forms',
        'leadtrackr_cf7_forms',
        'leadtrackr_elementor_forms',
        'leadtrackr_wpforms_forms',
        'leadtrackr_fluent_forms_forms',
        'leadtrackr_divi_process_contact_form',
    );

    $is_upgrade = false;
    foreach ($existing_settings as $option) {
        if (get_option($option, null) !== null) {
            $is_upgrade = true;
            break;
        }
    }

    add_option('leadtrackr_install_state', $is_upgrade ? 'upgraded' : 'fresh');
    // add_option leaves an existing explicit choice alone, so this only fills in
    // the starting position.
    add_option('leadtrackr_channelflow_enabled', !$is_upgrade);
}
add_action('plugins_loaded', 'leadtrackr_bootstrap_install_state');

/** True for sites that were already running the plugin before this version. */
function leadtrackr_is_legacy_install()
{
    return get_option('leadtrackr_install_state', 'fresh') === 'upgraded';
}

function leadtrackr_channelflow_enabled()
{
    return (bool)get_option('leadtrackr_channelflow_enabled', !leadtrackr_is_legacy_install());
}

/** Required for new installs, optional for sites that upgraded into it. */
function leadtrackr_api_token_required()
{
    return !leadtrackr_is_legacy_install();
}

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

/**
 * Defaults for the LeadBot embed. Only keys that differ from these are written
 * to the page, so a site that changes nothing ships no configuration at all.
 */
function leadtrackr_leadbot_defaults()
{
    return array(
        'enabled' => false,
        'companyName' => '',
        'agentName' => '',
        'agentPhoto' => '',
        'greeting' => '',
        'phone' => '',
        'whatsapp' => '',
        'launcher' => true,
        'teaser' => true,
        'whatsappInterceptor' => false,
        'whatsappPhoneQuestion' => true,
        'callTracking' => false,
        'position' => 'right',
        'offsetBottom' => 20,
        'offsetSide' => 20,
        'language' => 'auto',
        'responseTimeText' => '',
        'themePrimary' => '',
        'themePrimaryHover' => '',
        'themeRadius' => 16,
    );
}

function leadtrackr_get_leadbot_settings()
{
    $saved = get_option('leadtrackr_leadbot', array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_merge(leadtrackr_leadbot_defaults(), $saved);
}

/**
 * Translate the plugin's settings into the config the LeadBot expects. Empty
 * values are omitted rather than sent as empty strings, so the LeadBot falls
 * back to its own defaults instead of rendering blanks.
 */
function leadtrackr_leadbot_config($settings, $project_id)
{
    $config = array('projectId' => $project_id);

    foreach (array('companyName', 'agentName', 'agentPhoto', 'greeting', 'phone', 'whatsapp') as $key) {
        if ($settings[$key] !== '') {
            $config[$key] = $settings[$key];
        }
    }

    if (!$settings['launcher']) $config['launcher'] = false;
    if (!$settings['teaser']) $config['teaser'] = false;
    if ($settings['whatsappInterceptor']) $config['whatsappInterceptor'] = true;
    if (!$settings['whatsappPhoneQuestion']) $config['whatsappPhoneQuestion'] = false;
    if ($settings['callTracking']) $config['callTracking'] = true;
    if ($settings['position'] === 'left') $config['position'] = 'left';
    if ($settings['language'] !== 'auto') $config['language'] = $settings['language'];
    if ($settings['responseTimeText'] !== '') $config['responseTimeText'] = $settings['responseTimeText'];

    if ((int)$settings['offsetBottom'] !== 20 || (int)$settings['offsetSide'] !== 20) {
        $config['offset'] = array(
            'bottom' => (int)$settings['offsetBottom'],
            'side' => (int)$settings['offsetSide'],
        );
    }

    $theme = array();
    if ($settings['themePrimary'] !== '') $theme['primary'] = $settings['themePrimary'];
    if ($settings['themePrimaryHover'] !== '') $theme['primaryHover'] = $settings['themePrimaryHover'];
    if ((int)$settings['themeRadius'] !== 16) $theme['radius'] = (int)$settings['themeRadius'];
    if (!empty($theme)) $config['theme'] = $theme;

    return $config;
}

function leadtrackr_enqueue_frontend_scripts()
{
    if (is_admin()) {
        return;
    }

    if (leadtrackr_channelflow_enabled()) {
        $utm_config = get_option('leadtrackr_utm_params', array());

        // In the head, not the footer: the channel has to be resolved from the
        // referrer and query string before the visitor can navigate away.
        wp_enqueue_script(
            'leadtrackr-channelflow',
            plugin_dir_url(__FILE__) . 'assets/channelflow.js',
            array(),
            LEADTRACKR_PLUGIN_VERSION,
            false
        );

        if (!empty($utm_config)) {
            wp_add_inline_script(
                'leadtrackr-channelflow',
                'window.leadtrackrChannelFlowConfig = ' . wp_json_encode($utm_config) . ';',
                'before'
            );
        }
    }

    $leadbot = leadtrackr_get_leadbot_settings();
    $project_id = get_option('leadtrackr_project_id', '');

    // Nothing is requested from the CDN unless the LeadBot is switched on, so a
    // site that does not use it pays nothing for it.
    if (!$leadbot['enabled'] || $project_id === '') {
        return;
    }

    wp_enqueue_script(
        'leadtrackr-leadbot',
        LEADTRACKR_LEADBOT_SRC,
        array(),
        null,
        array('strategy' => 'async', 'in_footer' => true)
    );

    wp_add_inline_script(
        'leadtrackr-leadbot',
        'window.ltLeadBotConfig = ' . wp_json_encode(leadtrackr_leadbot_config($leadbot, $project_id)) . ';',
        'before'
    );
}
add_action('wp_enqueue_scripts', 'leadtrackr_enqueue_frontend_scripts');

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
    // Filtering on the widget type in SQL keeps this from loading every
    // _elementor_data blob on the site — often hundreds of KB per page — just
    // to find the handful that contain a form.
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.ID, b.meta_value
             FROM {$wpdb->posts} a
             INNER JOIN {$wpdb->postmeta} b ON a.ID = b.post_id
             WHERE a.post_status = 'publish'
               AND b.meta_key = '_elementor_data'
               AND b.meta_value LIKE %s
             LIMIT 500",
            '%' . $wpdb->esc_like('"widgetType":"form"') . '%'
        )
    );
    /**
     * Check if empty.
     */
    if (empty($results)) {
        return array();
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
    $gf_track_all = (bool)get_option('leadtrackr_gf_track_all', false);
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
    $cf7_track_all = (bool)get_option('leadtrackr_cf7_track_all', false);
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
    $elementor_track_all = (bool)get_option('leadtrackr_elementor_track_all', false);
    $elementor_forms_forms = get_option('leadtrackr_elementor_forms', array());
    if ($elementor_enabled) {
        $results = leadtrackr_list_get_elementor_forms();

        $elementor_forms = [];

        foreach ($results as $page_id => $forms) {
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
    $wpforms_track_all = (bool)get_option('leadtrackr_wpforms_track_all', false);
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
    $fluent_track_all = (bool)get_option('leadtrackr_fluent_track_all', false);
    $fluent_forms_forms = get_option('leadtrackr_fluent_forms_forms', array());
    if ($fluent_forms_enabled) {
        $ff_forms = \FluentForm\App\Helpers\Helper::getForms();
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

    // Matching only the active theme's name misses the two most common Divi
    // setups: a child theme (named "Divi Child") and the Divi Builder plugin
    // running on top of an unrelated theme.
    $current_theme = wp_get_theme();
    $divi_theme_enabled =
        $current_theme->get('Name') === 'Divi' ||
        $current_theme->get_template() === 'Divi' ||
        defined('ET_BUILDER_PLUGIN_ACTIVE');
    $divi_process_contact_form = get_option('leadtrackr_divi_process_contact_form', false);

    $utm_params = get_option('leadtrackr_utm_params', array());

    return array(
        // rest_url, not a hand-built /wp-json path: sites on plain permalinks
        // serve the REST API from /?rest_route= and would 404 on every save.
        'apiUrl' => esc_url_raw(rest_url(LEADTRACKR_API_NAMESPACE)),
        // Cookie authentication on the REST API is only accepted together with
        // this nonce. Without it every save comes back 401 rest_forbidden,
        // because WordPress treats the request as logged out.
        'nonce' => wp_create_nonce('wp_rest'),
        'projectId' => get_option('leadtrackr_project_id', ''),
        // Whether a token exists, never the token itself: wp_localize_script
        // prints this straight into the page source, which would undo the
        // point of encrypting it at rest.
        'apiTokenSet' => leadtrackr_decrypt_token(get_option('leadtrackr_api_token', '')) !== '',
        'channelFlowEnabled' => leadtrackr_channelflow_enabled(),
        'apiTokenRequired' => leadtrackr_api_token_required(),
        'utmParams' => array(
            'sourceParam' => isset($utm_params['sourceParam']) ? $utm_params['sourceParam'] : 'utm_source',
            'mediumParam' => isset($utm_params['mediumParam']) ? $utm_params['mediumParam'] : 'utm_medium',
            'campaignParam' => isset($utm_params['campaignParam']) ? $utm_params['campaignParam'] : 'utm_campaign',
            'contentParam' => isset($utm_params['contentParam']) ? $utm_params['contentParam'] : 'utm_content',
            'termParam' => isset($utm_params['termParam']) ? $utm_params['termParam'] : 'utm_term',
        ),
        'gravityForms' => array(
            'enabled' => $gravity_forms_enabled,
            'forms' => $gravity_forms_forms,
            'trackAll' => (bool)get_option('leadtrackr_gf_track_all', false),
        ),
        'cf7' => array(
            'enabled' => $cf7_enabled,
            'forms' => $cf7_forms_forms,
            'trackAll' => (bool)get_option('leadtrackr_cf7_track_all', false),
        ),
        'elementor' => array(
            'enabled' => $elementor_enabled,
            'forms' => $elementor_forms_forms,
            'trackAll' => (bool)get_option('leadtrackr_elementor_track_all', false),
        ),
        'wpforms' => array(
            'enabled' => $wpforms_enabled,
            'forms' => $wpforms_forms_forms,
            'trackAll' => (bool)get_option('leadtrackr_wpforms_track_all', false),
        ),
        'fluentForms' => array(
            'enabled' => $fluent_forms_enabled,
            'forms' => $fluent_forms_forms,
            'trackAll' => (bool)get_option('leadtrackr_fluent_track_all', false),
        ),
        'divi' => array(
            'enabled' => $divi_theme_enabled,
            'processContactForm' => $divi_process_contact_form,
        ),
        'leadbot' => leadtrackr_get_leadbot_settings(),
        'leadbotSrc' => LEADTRACKR_LEADBOT_SRC,
        'leadbotPreviewEndpoint' => esc_url_raw(rest_url(LEADTRACKR_API_NAMESPACE . '/leadbot-preview-lead')),
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


    // Not wp_localize_script: that casts every top-level scalar to a string, so
    // booleans arrive in JavaScript as "1" and "" and any strict comparison —
    // or a snapshot used to detect unsaved changes — silently misbehaves.
    wp_add_inline_script(
        'leadtrackr-app-js',
        'window.wpData = ' . wp_json_encode(leadtrackr_get_global_data()) . ';',
        'before'
    );
}


/**
 * These endpoints write the project ID and API token, which decide where leads
 * are sent. That is the same bar as the settings page itself, which is
 * registered with manage_options — allowing edit_others_posts would let an
 * editor repoint every lead on the site without being able to open the page.
 */
function leadtrackr_check_admin_permission()
{
    return current_user_can('manage_options');
}

function leadtrackr_encrypt_token($token)
{
    if (empty($token)) {
        return '';
    }
    $key = wp_salt('auth');
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
    return $encrypted !== false ? base64_encode($encrypted) : '';
}

function leadtrackr_decrypt_token($encrypted_token)
{
    if (empty($encrypted_token)) {
        return '';
    }
    $key = wp_salt('auth');
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    $decoded = base64_decode($encrypted_token);
    if ($decoded === false) {
        return $encrypted_token;
    }
    $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, $iv);
    return $decrypted !== false ? $decrypted : '';
}

/**
 * Whitelist the LeadBot settings. Anything not listed here never reaches the
 * option, so a crafted request cannot inject extra keys into the config that
 * gets printed onto every page of the site.
 */
function leadtrackr_sanitize_leadbot_settings($raw)
{
    $defaults = leadtrackr_leadbot_defaults();
    if (!is_array($raw)) {
        return $defaults;
    }

    $clean = $defaults;

    foreach (array('companyName', 'agentName', 'greeting', 'phone', 'whatsapp', 'responseTimeText') as $key) {
        if (isset($raw[$key])) {
            $clean[$key] = sanitize_text_field($raw[$key]);
        }
    }

    if (isset($raw['agentPhoto'])) {
        $clean['agentPhoto'] = esc_url_raw($raw['agentPhoto']);
    }

    foreach (array('enabled', 'launcher', 'teaser', 'whatsappInterceptor', 'whatsappPhoneQuestion', 'callTracking') as $key) {
        if (isset($raw[$key])) {
            $clean[$key] = (bool)$raw[$key];
        }
    }

    if (isset($raw['position'])) {
        $clean['position'] = $raw['position'] === 'left' ? 'left' : 'right';
    }
    if (isset($raw['language'])) {
        $clean['language'] = in_array($raw['language'], array('nl', 'en'), true) ? $raw['language'] : 'auto';
    }

    foreach (array('offsetBottom', 'offsetSide') as $key) {
        if (isset($raw[$key])) {
            $clean[$key] = max(0, min(400, (int)$raw[$key]));
        }
    }
    if (isset($raw['themeRadius'])) {
        $clean['themeRadius'] = max(0, min(40, (int)$raw['themeRadius']));
    }

    // Hex colours only: these end up in inline styles inside the LeadBot.
    foreach (array('themePrimary', 'themePrimaryHover') as $key) {
        if (isset($raw[$key])) {
            $colour = sanitize_hex_color($raw[$key]);
            $clean[$key] = $colour ? $colour : '';
        }
    }

    return $clean;
}

function leadtrackr_sanitize_forms_data($raw_forms)
{
    if (!is_array($raw_forms)) {
        return array();
    }

    $sanitized = array();
    foreach ($raw_forms as $form) {
        if (!is_array($form) || !isset($form['id'])) {
            continue;
        }

        $sanitized[] = array(
            'id' => is_int($form['id']) ? $form['id'] : sanitize_text_field($form['id']),
            'sendToLeadTrackr' => !empty($form['sendToLeadTrackr']),
            'customTitle' => isset($form['customTitle']) ? sanitize_text_field($form['customTitle']) : '',
        );
    }

    return $sanitized;
}

function leadtrackr_register_rest_api()
{
    register_rest_route(LEADTRACKR_API_NAMESPACE, '/project-id', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $project_id = sanitize_text_field($params['project_id'] ?? '');
            update_option('leadtrackr_project_id', $project_id);
            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/api-token', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $api_token = sanitize_text_field($params['api_token'] ?? '');
            update_option('leadtrackr_api_token', leadtrackr_encrypt_token($api_token));
            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/channelflow-settings', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            update_option('leadtrackr_channelflow_enabled', !empty($params['enabled']));

            $utm_params = array();
            if (isset($params['utmParams'])) {
                $allowed_keys = array('sourceParam', 'mediumParam', 'campaignParam', 'contentParam', 'termParam');
                foreach ($allowed_keys as $key) {
                    if (isset($params['utmParams'][$key])) {
                        $utm_params[$key] = sanitize_text_field($params['utmParams'][$key]);
                    }
                }
            }
            update_option('leadtrackr_utm_params', $utm_params);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    // The settings-page preview points the LeadBot's endpoint here instead of at
    // the real API, so trying out the form never creates a live lead.
    register_rest_route(LEADTRACKR_API_NAMESPACE, '/leadbot-preview-lead', array(
        'methods' => 'POST',
        'callback' => function () {
            return new WP_REST_Response(array('message' => 'Preview only, nothing was stored'), 200);
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/leadbot', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $settings = leadtrackr_sanitize_leadbot_settings($params['leadbot'] ?? array());
            update_option('leadtrackr_leadbot', $settings);

            return new WP_REST_Response(array(
                'success' => true,
                'leadbot' => $settings,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/track-all', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $builder = sanitize_text_field($params['builder'] ?? '');
            $enabled = !empty($params['enabled']);

            $allowed_builders = array(
                'gravity-forms' => 'leadtrackr_gf_track_all',
                'contact-form-7' => 'leadtrackr_cf7_track_all',
                'elementor' => 'leadtrackr_elementor_track_all',
                'wpforms' => 'leadtrackr_wpforms_track_all',
                'fluent-forms' => 'leadtrackr_fluent_track_all',
            );

            if (!isset($allowed_builders[$builder])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid builder',
                ), 400);
            }

            update_option($allowed_builders[$builder], $enabled);
            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/gravity-forms', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $forms = leadtrackr_sanitize_forms_data($params['forms'] ?? array());
            update_option('leadtrackr_gf_forms', $forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/contact-form-7', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $forms = leadtrackr_sanitize_forms_data($params['forms'] ?? array());
            update_option('leadtrackr_cf7_forms', $forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/elementor', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $forms = leadtrackr_sanitize_forms_data($params['forms'] ?? array());
            update_option('leadtrackr_elementor_forms', $forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/wpforms', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $forms = leadtrackr_sanitize_forms_data($params['forms'] ?? array());
            update_option('leadtrackr_wpforms_forms', $forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/fluent-forms', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $forms = leadtrackr_sanitize_forms_data($params['forms'] ?? array());
            update_option('leadtrackr_fluent_forms_forms', $forms);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));

    register_rest_route(LEADTRACKR_API_NAMESPACE, '/divi', array(
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            $params = $request->get_json_params();
            $process_contact_form = !empty($params['processContactForm']);
            update_option('leadtrackr_divi_process_contact_form', $process_contact_form);

            return new WP_REST_Response(array(
                'success' => true,
            ));
        },
        'permission_callback' => 'leadtrackr_check_admin_permission',
    ));
}

add_action('rest_api_init', 'leadtrackr_register_rest_api');

define('leadtrackr_firstNamePossibleNames', array('first_name', 'firstName', 'first-name', 'First Name', 'name', 'Name', 'voornaam', 'naam', 'Voornaam', 'Naam'));
define('leadtrackr_lastNamePossibleNames', array('last_name', 'lastName', 'last-name', 'Last Name', 'surname', 'Surname', 'achternaam', 'Achternaam'));
define('leadtrackr_emailPossibleNames', array('email', 'Email', 'e-mail', 'E-mail', 'e-mail address', 'E-mail Address', 'email address', 'Email Address', 'emailadres', 'Emailadres', 'e-mailadres', 'E-mailadres'));
define('leadtrackr_phonePossibleNames', array('phone', 'Phone', 'phone number', 'Phone Number', 'telefoon', 'Telefoon', 'telefoonnummer', 'Telefoonnummer'));
define('leadtrackr_companyPossibleNames', array('company', 'Company', 'company name', 'Company Name', 'bedrijf', 'Bedrijf', 'bedrijfsnaam', 'Bedrijfsnaam'));

/**
 * Extract userData from a flat key-value array of form fields.
 *
 * Matching strategy (in priority order):
 * 1. Field type hints (email, tel/phone) — if $field_types is provided
 * 2. Exact match against possibleNames arrays
 * 3. Sanitized match (stripped colons, trimmed) against possibleNames
 * 4. Partial match (strpos) — catches keys like "your-email" or "contact_phone"
 *
 * @param array $fields Key-value pairs of form field labels/names and their values.
 * @param array $field_types Optional. Key-value pairs of field names and their input types (e.g. 'email', 'tel').
 * @return array The extracted userData with keys: firstName, lastName, email, phone, company.
 */
function leadtrackr_extract_user_data($fields, $field_types = array())
{
    $user_data = array();

    $mapping = array(
        'firstName' => leadtrackr_firstNamePossibleNames,
        'lastName' => leadtrackr_lastNamePossibleNames,
        'email' => leadtrackr_emailPossibleNames,
        'phone' => leadtrackr_phonePossibleNames,
        'company' => leadtrackr_companyPossibleNames,
    );

    // Form builders hand composite fields over as arrays: Fluent Forms sends
    // names => [first_name, last_name], WPForms sends [first, last]. Left as
    // they are, the key "names" partially matches "name" and firstName becomes
    // the whole object. Splitting them into their parts first means the normal
    // matching sees first_name and last_name, which it already knows, and no
    // array can ever reach a user field.
    $flattened = array();
    foreach ($fields as $key => $value) {
        if (!is_array($value)) {
            $flattened[$key] = $value;
            continue;
        }
        foreach ($value as $sub_key => $sub_value) {
            if (is_array($sub_value) || $sub_value === '' || $sub_value === null) {
                continue;
            }
            if (is_string($sub_key)) {
                // Builders disagree on what to call the parts: Fluent Forms
                // uses first_name / last_name, WPForms uses first / last. Map
                // them onto one name so the matching below does not need to
                // know which builder it came from.
                $canonical = strtolower($sub_key);
                if ($canonical === 'first') {
                    $canonical = 'first_name';
                } elseif ($canonical === 'last') {
                    $canonical = 'last_name';
                } else {
                    $canonical = $sub_key;
                }
                $flattened[$canonical] = $sub_value;
            } else {
                // A plain list, such as a checkbox group: keep it under its own
                // label rather than losing it.
                $flattened[$key] = isset($flattened[$key])
                    ? $flattened[$key] . ', ' . $sub_value
                    : $sub_value;
            }
        }
    }

    $usable = array();
    foreach ($flattened as $key => $value) {
        if (empty($value)) {
            continue;
        }
        $usable[$key] = array(
            'value' => $value,
            'sanitized' => str_replace(':', '', sanitize_text_field($key)),
        );
    }

    $claim = function ($slot, $value) use (&$user_data) {
        if (isset($user_data[$slot])) {
            return;
        }
        // A checkbox called email_optin partially matches "email" and would
        // otherwise claim the slot with a value of "1", locking out the real
        // address. Nothing without an @ can be an email, so it is never a
        // useful fallback here.
        if ($slot === 'email' && (!is_string($value) || strpos($value, '@') === false)) {
            return;
        }
        $user_data[$slot] = $value;
    };

    // Three passes, from most to least certain. Running them across all fields
    // in turn — rather than deciding field by field in form order — is what
    // stops a weaker match earlier in the form from claiming a slot that a
    // better field further down would have filled.

    // 1. Types declared by the form builder itself.
    foreach ($usable as $key => $field) {
        $type = isset($field_types[$key]) ? $field_types[$key] : '';
        if ($type === 'email') {
            $claim('email', $field['value']);
        }
        if ($type === 'tel' || $type === 'phone') {
            $claim('phone', $field['value']);
        }
    }

    // 2. Field names that match a known name exactly.
    foreach ($mapping as $slot => $possible_names) {
        foreach ($usable as $key => $field) {
            if (in_array($key, $possible_names, true) || in_array($field['sanitized'], $possible_names, true)) {
                $claim($slot, $field['value']);
                break;
            }
        }
    }

    // 3. Anything that merely contains a known name. Deliberately generous: a
    // company name landing in firstName beats an empty lead, and a field is
    // allowed to fill a second slot here — but only now that every exact match
    // has already had its pick.
    foreach ($mapping as $slot => $possible_names) {
        if (isset($user_data[$slot])) {
            continue;
        }
        foreach ($usable as $key => $field) {
            foreach ($possible_names as $name) {
                if (stripos($key, $name) !== false) {
                    $claim($slot, $field['value']);
                    break 2;
                }
            }
        }
    }

    return $user_data;
}

/**
 * Reads the consent state that assets/channelflow.js left in a cookie. The lead
 * is sent from PHP, which cannot reach gtag's in-browser store itself.
 *
 * The cookie is visitor-controlled, so only the four known types and the two
 * known values survive: this ends up on the lead, and anything else would be a
 * stranger's text in your reporting. An unreadable or absent cookie yields null
 * and the field is left off entirely — never guessed at.
 */
function leadtrackr_consent_state()
{
    if (empty($_COOKIE['lt_consent'])) {
        return null;
    }

    $parsed = json_decode(wp_unslash($_COOKIE['lt_consent']), true);
    if (!is_array($parsed)) {
        return null;
    }

    $allowed_types = array('ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization');
    $consent = array();
    foreach ($allowed_types as $type) {
        if (!isset($parsed[$type])) {
            continue;
        }
        if ($parsed[$type] === 'granted' || $parsed[$type] === 'denied') {
            $consent[$type] = $parsed[$type];
        }
    }

    return empty($consent) ? null : $consent;
}

/**
 * The page the form was submitted from, as host and path without the query
 * string — the same shape the GTM tag sends.
 *
 * Taken from the referer rather than the request URI, because an AJAX
 * submission posts to /wp-json or admin-ajax.php and the request URI would
 * record that instead of the page the visitor was actually on.
 *
 * The referer is visitor-controlled and ends up on the lead, so anything
 * pointing somewhere other than this site is discarded.
 */
function leadtrackr_conversion_page()
{
    if (empty($_SERVER['HTTP_REFERER'])) {
        return '';
    }

    $referer = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])));
    if (empty($referer['host'])) {
        return '';
    }

    $home = wp_parse_url(home_url());
    if (empty($home['host']) || strcasecmp($referer['host'], $home['host']) !== 0) {
        return '';
    }

    $path = isset($referer['path']) ? $referer['path'] : '/';
    return $referer['host'] . $path;
}

/**
 * Where each field can be found: the URL parameter of the page converted on,
 * then the cookies the platform's own pixel writes.
 *
 * URL before cookie is the order the GTM tag and the LeadBot already use for
 * gclid, and the one every Stape template uses. The parameter is the freshest
 * copy and is there even when the pixel is missing, blocked by consent, or has
 * not written its cookie yet.
 *
 * Cookies are only read, never created. An ID we invent is one the platform
 * cannot match, so an absent cookie stays absent. Browser IDs have no URL
 * parameter for that reason — they exist only as something the pixel made.
 *
 * Field names match what each platform calls the parameter, so the API can pass
 * them to a conversions endpoint without a translation table.
 */
function leadtrackr_click_id_sources()
{
    return array(
        // field         url parameter   cookies, first match wins
        'ttclid'    => array('ttclid',    array('ttclid')),
        'ttp'       => array('',          array('_ttp')),
        'li_fat_id' => array('li_fat_id', array('li_fat_id')),
        // Snapchat capitalises its parameter where nobody else does.
        'scclid'    => array('ScCid',     array('_scclid')),
        'scid'      => array('',          array('_scid')),
        // rdt_cid without the underscore is what Reddit's older pixel wrote.
        'rdt_cid'   => array('rdt_cid',   array('_rdt_cid', 'rdt_cid')),
        'rdt_uuid'  => array('',          array('_rdt_uuid')),
        'epik'      => array('epik',      array('_epik')),
        'twclid'    => array('twclid',    array('twclid')),
        // OpenAI's cookies are its parameter with a __ prefix.
        'oppref'    => array('oppref',    array('__oppref')),
        'obref'     => array('',          array('__obref')),
        // Microsoft's browser ID. Its click ID needs a correction the rest do
        // not, so that one lives in leadtrackr_microsoft_click_id().
        'uetvid'    => array('',          array('uet_vid', '_uetvid')),
    );
}

/**
 * Query parameters of the page the form was submitted from.
 *
 * The referer is the one place a click ID's URL parameter survives to the
 * server: a same-origin request carries the full referring URL including its
 * query string, and a form submission is same-origin. This only helps when the
 * conversion happens on a page whose URL still carries the click ID — the usual
 * case for a paid landing page with the form on it, and never the case for a
 * visitor who converts three pages later. There the cookie is the only copy.
 *
 * The referer is visitor-controlled and ends up on the lead, so anything not
 * pointing at this site is discarded, as in leadtrackr_conversion_page().
 */
function leadtrackr_referer_query_params()
{
    $params = array();

    if (empty($_SERVER['HTTP_REFERER'])) {
        return $params;
    }

    $referer = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])));
    $home = wp_parse_url(home_url());

    if (empty($referer['host']) || empty($referer['query']) || empty($home['host'])) {
        return $params;
    }

    if (strcasecmp($referer['host'], $home['host']) === 0) {
        parse_str($referer['query'], $params);
    }

    return $params;
}

/**
 * First usable value for one field, URL parameter before cookies.
 *
 * Both are visitor-controlled and land on the lead, so a value that cannot
 * plausibly be a click ID is skipped rather than truncated: half a click ID is
 * not a click ID, and storing it would look like real attribution.
 */
function leadtrackr_click_id($url_param, $cookie_names)
{
    $candidates = array();

    if ($url_param !== '') {
        $query = leadtrackr_referer_query_params();
        if (isset($query[$url_param]) && is_string($query[$url_param])) {
            $candidates[] = $query[$url_param];
        }
    }

    foreach ($cookie_names as $name) {
        if (isset($_COOKIE[$name])) {
            $candidates[] = wp_unslash($_COOKIE[$name]);
        }
    }

    foreach ($candidates as $candidate) {
        $value = sanitize_text_field($candidate);
        if ($value !== '' && strlen($value) <= LEADTRACKR_MAX_CLICK_ID_LENGTH) {
            return $value;
        }
    }

    return '';
}

/**
 * Microsoft's click ID, which needs one correction the others do not.
 *
 * UET's browser pixel writes the cookie's own name into its value: the ID in
 * _uetmsclkid arrives as "_uet561f11b5…" and Microsoft rejects that form. A
 * server-side container writes the same ID to uet_msclkid without the prefix.
 */
function leadtrackr_microsoft_click_id()
{
    $value = leadtrackr_click_id('msclkid', array('uet_msclkid', '_uetmsclkid'));

    return strpos($value, '_uet') === 0 ? substr($value, 4) : $value;
}

/**
 * One of Google's click IDs, which reaches us through either of two cookies in
 * either of two formats.
 *
 * A server-side container writes FPGCLAW, FPGCLGB, FPGCLAG and FPGCLDC; the
 * browser tag writes the _gcl_* pair. Sites on server-side GTM often have only
 * the former, the same reason FPID is read alongside _ga for the client ID.
 *
 * Reading the server-side ones only works here. They are set over HTTP as
 * HttpOnly, so document.cookie cannot see them and neither can the GTM tag or
 * the LeadBot — but PHP receives them in $_COOKIE like any other cookie. Do not
 * carry this half over to the browser-side sources; it is dead code there.
 *
 * The browser tag wraps the ID in a dot-separated container whose last segment
 * is the ID; the server-side one wraps it between ".k" and "$i". gbraid is the
 * odd one out twice over: it lives in _gcl_ag rather than beside its siblings,
 * and that cookie carries the server-side format even though the browser tag
 * writes it.
 *
 * Google documents the cookie names but not which ID each holds; the mapping is
 * taken from stape-io/google-conversion-events-tag.
 */
function leadtrackr_google_click_id($field, $server_cookie, $browser_cookie, $browser_uses_server_format = false)
{
    $from_url = leadtrackr_click_id($field, array());
    if ($from_url !== '') {
        return $from_url;
    }

    $from_server = leadtrackr_unwrap_gcl_cookie(leadtrackr_click_id('', array($server_cookie)), true);
    if ($from_server !== '') {
        return $from_server;
    }

    return leadtrackr_unwrap_gcl_cookie(
        leadtrackr_click_id('', array($browser_cookie)),
        $browser_uses_server_format
    );
}

/**
 * Pulls the ID out of a _gcl container. A value with no separator at all is not
 * a container that lost its ID, so it yields nothing rather than being passed
 * on whole.
 */
function leadtrackr_unwrap_gcl_cookie($value, $server_format)
{
    if ($server_format) {
        return preg_match('/\.k(.+)\$i/', $value, $matches) ? $matches[1] : '';
    }

    if (strpos($value, '.') === false) {
        return '';
    }

    $parts = explode('.', $value);

    return (string) end($parts);
}
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

    // dclid is collected but cannot be uploaded to Google Ads: ClickConversion
    // takes gclid, gbraid or wbraid only. It belongs to Campaign Manager 360 and
    // DV360, so it stays unused until there is an integration for those — but
    // gathering it now costs a line and saves a release later.
    $google_click_ids = array(
        'gclid'  => array('FPGCLAW', '_gcl_aw', false),
        'wbraid' => array('FPGCLGB', '_gcl_gb', false),
        'gbraid' => array('FPGCLAG', '_gcl_ag', true),
        'dclid'  => array('FPGCLDC', '_gcl_dc', false),
    );

    foreach ($google_click_ids as $field => $sources) {
        $value = leadtrackr_google_click_id($field, $sources[0], $sources[1], $sources[2]);
        if ($value !== '') {
            $attributes_data[$field] = $value;
        }
    }

    $msclkid = leadtrackr_microsoft_click_id();
    if ($msclkid !== '') {
        $attributes_data['msclkid'] = $msclkid;
    }

    foreach (leadtrackr_click_id_sources() as $field => $sources) {
        $value = leadtrackr_click_id($sources[0], $sources[1]);
        if ($value !== '') {
            $attributes_data[$field] = $value;
        }
    }

    if ($cid_cookie !== '') {
        $attributes_data['cid'] = $cid_cookie;
    }

    $conversion_page = leadtrackr_conversion_page();
    if ($conversion_page !== '') {
        $attributes_data['conversionPage'] = $conversion_page;
    }

    $consent = leadtrackr_consent_state();
    if ($consent !== null) {
        $attributes_data['consent'] = $consent;
    }

    return $attributes_data;
}

/**
 * Send lead data to the LeadTrackr API.
 *
 * @param array $data The lead data to send.
 * @return array|WP_Error The response or WP_Error on failure.
 */
function leadtrackr_send_lead($data)
{
    // Without a project the API can only reject this, and the visitor waits out
    // the timeout for nothing. The settings page says no leads are sent until
    // the Project ID is filled in; this is what makes that true.
    if (empty($data['projectId'])) {
        return null;
    }

    if (isset($_COOKIE['lt_channelflow'])) {
        // Deliberately not run through sanitize_text_field: that strips
        // percent-encoded octets, which mangles campaign values. json_decode
        // returning an array is the validation.
        $parsed = json_decode(wp_unslash($_COOKIE['lt_channelflow']), true);
        if (is_array($parsed)) {
            $data['channelFlow'] = $parsed;
        }
    }

    $api_token = leadtrackr_decrypt_token(get_option('leadtrackr_api_token', ''));
    $has_token = !empty($api_token);

    $endpoint = $has_token ? LEADTRACKR_LEAD_ENDPOINT : LEADTRACKR_LEAD_ENDPOINT_LEGACY;

    $headers = array('Content-Type' => 'application/json');
    if ($has_token) {
        // The API reads x-api-key. Sending any other header name authenticates
        // nothing and the lead comes back 401.
        $headers['x-api-key'] = $api_token;
    }

    $response = wp_remote_post($endpoint, array(
        'body' => wp_json_encode($data),
        'headers' => $headers,
        // This call blocks the visitor's form submission, so it must fail fast
        // rather than hold the page while LeadTrackr is unreachable.
        'timeout' => 5,
    ));

    leadtrackr_log_lead_result($response, $endpoint);

    return $response;
}

/**
 * Record why a lead did not arrive. Without this a 401 or a timeout is
 * indistinguishable from a lead that was never submitted.
 *
 * @param array|WP_Error $response Result of the API call.
 * @param string         $endpoint The endpoint that was called.
 */
function leadtrackr_log_lead_result($response, $endpoint)
{
    if (is_wp_error($response)) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[LeadTrackr] Lead not sent to ' . $endpoint . ': ' . $response->get_error_message());
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(
            '[LeadTrackr] Lead rejected by ' . $endpoint . ' with status ' . $code . ': ' .
            wp_remote_retrieve_body($response)
        );
    }
}

/**
 * Handle Gravity Forms submission.
 *
 * @param array $entry An array containing the entry data.
 * @param array|GF_Form $form The form object or array.
 */
function leadtrackr_gravity_forms_submission($entry, $form)
{
    $track_all = get_option('leadtrackr_gf_track_all', false);
    $leadtrackr_gf_forms = get_option('leadtrackr_gf_forms', array());
    $form_id = $form['id'];

    $leadtrackr_form = array_filter($leadtrackr_gf_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (!$track_all && (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr'])) {
        return;
    }

    $form_fields = array();
    $field_types = array();
    foreach ($form['fields'] as $field) {
        if (isset($entry[$field['id']])) {
            $form_fields[$field['label']] = $entry[$field['id']];
            if (!empty($field['inputType'])) {
                $field_types[$field['label']] = $field['inputType'];
            }
        }
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form['id'],
            'formName' => $form['title'],
            'customFormName' => !empty($leadtrackr_form) ? ($leadtrackr_form['customTitle'] ?? '') : '',
            'formFields' => $form_fields,
        ),
        'userData' => leadtrackr_extract_user_data($form_fields, $field_types),
        'deviceData' => array(
            'ipAddress' => $entry['ip'],
            'userAgent' => $entry['user_agent'],
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    $response = leadtrackr_send_lead($data);

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
    $track_all = get_option('leadtrackr_cf7_track_all', false);
    $leadtrackr_cf7_forms = get_option('leadtrackr_cf7_forms', array());
    $form_id = $contact_form->id();

    $leadtrackr_form = array_filter($leadtrackr_cf7_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (!$track_all && (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr'])) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $contact_form->id(),
            'formName' => $contact_form->title(),
            'customFormName' => !empty($leadtrackr_form) ? ($leadtrackr_form['customTitle'] ?? '') : '',
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

    $form_fields = array();
    $field_types = array();

    foreach ($submission->get_posted_data() as $key => $value) {
        $form_fields[$key] = $value;
        $data['formData']['formFields'][$key] = $value;
    }

    // Build field type map from CF7 form tags for type-based fallback
    $email_tags = $contact_form->scan_form_tags(['type' => 'email']);
    if (!empty($email_tags) && isset($email_tags[0])) {
        $field_types[$email_tags[0]['name']] = 'email';
    }

    $tel_tags = $contact_form->scan_form_tags(['type' => 'tel']);
    if (!empty($tel_tags) && isset($tel_tags[0])) {
        $field_types[$tel_tags[0]['name']] = 'tel';
    }

    $data['userData'] = leadtrackr_extract_user_data($form_fields, $field_types);

    $response = leadtrackr_send_lead($data);

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
    $track_all = get_option('leadtrackr_elementor_track_all', false);
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

    if (!$track_all && (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr'])) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form_post_id . "_" . $form_id,
            'formName' => $record->get_form_settings('form_name'),
            'customFormName' => !empty($leadtrackr_form) ? ($leadtrackr_form['customTitle'] ?? '') : '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    $form_fields = $record->get_formatted_data();
    $data['formData']['formFields'] = $form_fields;
    $data['userData'] = leadtrackr_extract_user_data($form_fields);

    $response = leadtrackr_send_lead($data);

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
    $track_all = get_option('leadtrackr_wpforms_track_all', false);
    $leadtrackr_wpforms_forms = get_option('leadtrackr_wpforms_forms', array());
    $form_id = (int)$form_data['id'];

    if (!$form_id) {
        return;
    }

    $leadtrackr_form = array_filter($leadtrackr_wpforms_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (!$track_all && (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr'])) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form_id,
            'formName' => $form_data['settings']['form_title'],
            'customFormName' => !empty($leadtrackr_form) ? ($leadtrackr_form['customTitle'] ?? '') : '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    $form_fields = array();
    $field_types = array();

    foreach ($entry['fields'] as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $form_fields[$subKey] = $subValue;
            }
        } else {
            $label = $fields[$key]['name'];
            $form_fields[$label] = $value;

            if (!empty($fields[$key]['type'])) {
                $field_types[$label] = $fields[$key]['type'];
            }
        }
    }

    $data['formData']['formFields'] = $form_fields;
    $data['userData'] = leadtrackr_extract_user_data($form_fields, $field_types);

    $response = leadtrackr_send_lead($data);

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending WPForms submission to LeadTrackr: ' . $response->get_error_message());
    }
}

add_action('wpforms_process_complete', 'leadtrackr_wpforms_forms_submission', 10, 4);

function leadtrackr_fluent_forms_submission($submissionId, $formData, $form) {
    $track_all = get_option('leadtrackr_fluent_track_all', false);
    $form_id = (int)$form['id'];

    if (!$form_id) {
        return;
    }

    $leadtrackr_fluent_forms_forms = get_option('leadtrackr_fluent_forms_forms', array());
    $leadtrackr_form = array_filter($leadtrackr_fluent_forms_forms, function ($leadtrackr_form) use ($form_id) {
        return $leadtrackr_form['id'] === $form_id;
    });

    $leadtrackr_form = reset($leadtrackr_form);

    if (!$track_all && (empty($leadtrackr_form) || !$leadtrackr_form['sendToLeadTrackr'])) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $form_id,
            'formName' => $form['title'],
            'customFormName' => !empty($leadtrackr_form) ? ($leadtrackr_form['customTitle'] ?? '') : '',
            'formFields' => array()
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    $form_fields = array();

    foreach ($formData as $key => $value) {
        if (in_array($key, array('__fluent_form_embded_post_id', '_fluentform_1_fluentformnonce', '_wp_http_referer'))) {
            continue;
        }

        $form_fields[$key] = $value;
    }

    $data['formData']['formFields'] = $form_fields;
    $data['userData'] = leadtrackr_extract_user_data($form_fields);

    $response = leadtrackr_send_lead($data);

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending Fluent Forms submission to LeadTrackr: ' . $response->get_error_message());
    }
}

add_action('fluentform/submission_inserted', 'leadtrackr_fluent_forms_submission', 10, 3);

function leadtrackr_divi_contact_form_submission($processed_fields_values, $et_contact_error, $contact_form_info) {
    $divi_process_contact_form = get_option('leadtrackr_divi_process_contact_form', false);

    if (!$divi_process_contact_form) {
        return;
    }

    $data = array(
        'projectId' => get_option('leadtrackr_project_id', ''),
        'formData' => array(
            'formId' => $contact_form_info['contact_form_id'],
            'formName' => 'Divi Contact Form',
            'customFormName' => '',
            'formFields' => array(),
        ),
        'userData' => array(),
        'deviceData' => array(
            'ipAddress' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ),
        'attributionData' => leadtrackr_parse_attributes_data(),
    );

    $form_fields = array();

    foreach ($processed_fields_values as $key => $value) {
        $form_fields[$key] = $value['value'];
    }

    $data['formData']['formFields'] = $form_fields;
    $data['userData'] = leadtrackr_extract_user_data($form_fields);

    $response = leadtrackr_send_lead($data);

    if (is_wp_error($response)) {
        error_log('LeadTrackr: Error sending Divi Contact Form submission to LeadTrackr: ' . $response->get_error_message());
    }
}

add_action('et_pb_contact_form_submit', 'leadtrackr_divi_contact_form_submission', 10, 3);