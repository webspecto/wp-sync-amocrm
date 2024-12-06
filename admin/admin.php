<?php

/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023-2024 WebSpecto.
 */

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMApiException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\Dotenv\Dotenv;

defined('ABSPATH') or die('Access denied');

class WP_Sync_AmoCRM_Admin
{
    protected static ?AmoCRMApiClient $apiClient = null;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'wpsyncamo_add_admin_menu'));
        add_action('admin_init', array($this, 'wpsyncamo_register_settings'));
    }

    public function wpsyncamo_add_admin_menu()
    {
        $page = add_menu_page(
            'AmoCRM | Kommo Integration',
            'AmoCRM',
            'manage_options',
            'wpsyncamo',
            array($this, 'wpsyncamo_render_admin_page'),
            'dashicons-chart-pie',
            30
        );

        add_action("admin_print_styles-{$page}", array($this, 'wpsyncamo_enqueue_assets'));
    }

    public function wpsyncamo_enqueue_assets()
    {
        $version = hrtime(true);
        wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css');
        wp_enqueue_style('wpsyncamo', plugins_url('/wp-sync-amocrm/assets/css/style.css?v=' . $version));
        wp_enqueue_script('jquery', 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js');
        wp_enqueue_script('wpsyncamo', plugins_url('/wp-sync-amocrm/assets/js/script.js?v=' . $version));
    }

    public function wpsyncamo_register_settings()
    {
        $this->wpsyncamo_register_settings_connect();
    }

    public function wpsyncamo_register_settings_connect()
    {
        if (isset($_POST['wpsyncamo_auth'])) {
            $file_auth = 'CLIENT_BASE_DOMAIN="' . esc_attr($_POST['wpsyncamo_auth']['client_base_domain']) . '"' . PHP_EOL;
            $file_auth .= 'CLIENT_SECRET="' . esc_attr($_POST['wpsyncamo_auth']['client_secret']) . '"' . PHP_EOL;
            $file_auth .= 'CLIENT_ID="' . esc_attr($_POST['wpsyncamo_auth']['client_id']) . '"' . PHP_EOL;
            $file_auth .= 'CLIENT_REDIRECT_URI="' . plugins_url('/wp-sync-amocrm/oauth2-amocrm.php') . '"';
            file_put_contents(WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env', $file_auth);
        }

        if (file_exists($file_auth = WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env')) {
            $dotenv = new Dotenv(false);
            $dotenv->load($file_auth);
        }

        register_setting('wpsyncamo-group-connect', 'wpsyncamo_auth');

        add_settings_section('wpsyncamo-section-connect', 'API Settings', array($this, 'wpsyncamo_section_connect_callback'), 'wpsyncamo-group-connect');

        add_settings_field('wpsyncamo_client_base_domain_field', 'Client Base Domain', array($this, 'wpsyncamo_client_base_domain_callback'), 'wpsyncamo-group-connect', 'wpsyncamo-section-connect');
        add_settings_field('wpsyncamo_client_secret_field', 'Client Secret', array($this, 'wpsyncamo_client_secret_callback'), 'wpsyncamo-group-connect', 'wpsyncamo-section-connect');
        add_settings_field('wpsyncamo_client_id_field', 'Client ID', array($this, 'wpsyncamo_client_id_callback'), 'wpsyncamo-group-connect', 'wpsyncamo-section-connect');
    }

    public function wpsyncamo_section_connect_callback()
    {
        echo 'Create a external integration in AmoCRM | Kommo using the URL: <b>' . plugins_url('/wp-sync-amocrm/oauth2-amocrm.php') . '</b><br>';
        echo 'Following the integration setup, you will receive confidential data that should be entered below:';
    }

    public function wpsyncamo_client_base_domain_callback()
    {
        $client_base_domain = isset($_ENV['CLIENT_BASE_DOMAIN']) ? esc_attr($_ENV['CLIENT_BASE_DOMAIN']) : '';
        $domains = [
            'www.amocrm.ru',
            'www.kommo.com',
        ];

        echo '<select name="wpsyncamo_auth[client_base_domain]" class="regular-text code">';
        foreach ($domains as $domain) {
            $selected = ($client_base_domain === $domain) ? ' selected' : '';
            echo '<option value="' . $domain . '" ' . $selected . '>' . $domain . '</option>';
        }
        echo '</select>';
    }

    public function wpsyncamo_client_secret_callback()
    {
        $client_secret = isset($_ENV['CLIENT_SECRET']) ? esc_attr($_ENV['CLIENT_SECRET']) : '';
        echo '<input type="password" name="wpsyncamo_auth[client_secret]" value="' . $client_secret . '" class="regular-text code" required />';
    }

    public function wpsyncamo_client_id_callback()
    {
        $client_id = isset($_ENV['CLIENT_ID']) ? esc_attr($_ENV['CLIENT_ID']) : '';
        echo '<input type="text" name="wpsyncamo_auth[client_id]" value="' . $client_id . '" class="regular-text code" required />';
    }

    public function wpsyncamo_render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('<div class="wrap"><h1>AmoCRM | Kommo Integration</h1><div class="notice notice-error"><p>Access is restricted due to the lack of administrative privileges for the module.</p></div></div>');
        }

        echo '<div class="wrap">';
        echo '<h1>AmoCRM | Kommo Integration</h1>';

        $file_auth = file_exists(WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env') ? true : false;
        $file_auth_token = file_exists(WPSYNCAMO_DIR_PLUGIN . 'secret/auth_token.json') ? true : false;

        if ($file_auth === false || $file_auth_token === false) {
            echo '<form method="post" action="">';
            settings_fields('wpsyncamo-group-connect');
            do_settings_sections('wpsyncamo-group-connect');
            submit_button();
            echo '</form><hr>';
        }

        if ($file_auth === true) {
            if ($file_auth_token === false) {
                echo '<p>Authorize the integration under the administrator account from AmoCRM:</p>
                <p><a href="' . plugins_url('/wp-sync-amocrm/oauth2-amocrm.php') . '" class="button button-secondary">Authorize with AmoCRM</a></p>';
            } else {
                $this->wpsyncamo_update_forms();
                $this->wpsyncamo_revoke_authorization();

                $tabs = [
                    'wpcf7' => 'Contact form 7',
                    'wpforms' => 'WPForms',
                    'woocommerce' => 'Woocommerce',
                    'elementor' => 'Elementor Forms'
                ];

                $current_tab = 'wpcf7';

                if (isset($_POST['wpsyncamo_selected_wpcf7'])) {
                    $current_tab = 'wpcf7';
                } elseif (isset($_POST['wpsyncamo_selected_wpforms'])) {
                    $current_tab = 'wpforms';
                } elseif (isset($_POST['wpsyncamo_forms'])) {
                    $tabs_keys = array_keys($_POST['wpsyncamo_forms']);

                    if (array_key_exists($tabs_keys[0], $tabs)) {
                        $current_tab = $tabs_keys[0];
                    }
                }

                echo '<ul class="tabs">';
                foreach ($tabs as $key => $label) {
                    $class = ($current_tab == $key) ? 'active' : '';
                    echo '<li class="tab-link ' . $class . '" data-tab="tab-' . $key . '">' . $label . ($key == 'elementor' ? '<span class="position-absolute top-0 start-100 badge rounded-pill" style="background-color:#dba617;transform:translate(calc(-100% - 10px),-50%);">pre-alpha</span>' : '') . '</li>';
                }
                echo '</ul>';

                foreach ($tabs as $key => $label) {
                    $class = ($current_tab == $key) ? 'active-tab' : '';
                    echo '<div id="tab-' . $key . '" class="tab-content ' . $class . '">';
                    if ($key == 'wpcf7') {
                        $this->wpsyncamo_display_wpcf7();
                    } elseif ($key == 'wpforms') {
                        $this->wpsyncamo_display_wpforms();
                    } elseif ($key == 'woocommerce') {
                        $this->wpsyncamo_display_woocommerce();
                    } elseif ($key == 'elementor') {
                        $this->wpsyncamo_display_elementor();
                    }
                    echo '</div>';
                }
            }
        }

        echo '</div>';
    }

    public function wpsyncamo_revoke_authorization()
    {
        if (isset($_POST['wpsyncamo_revoke_authorization'])) {
            unlink(WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env');
            unlink(WPSYNCAMO_DIR_PLUGIN . 'secret/auth_token.json');
            echo '<script>location.reload();</script>';
        }

        echo '<form method="post" action="">
                <input type="hidden" name="wpsyncamo_revoke_authorization" value="1" />
                <p class="submit"><input type="submit" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e;" value="Revoke Authorization" onclick="return confirm(\'Are you sure you want to delete the settings and token data?\');" /></p>
            </form><hr>';
    }

    public function wpsyncamo_display_wpcf7()
    {
        if (
            !file_exists(WP_PLUGIN_DIR . 'contact-form-7/wp-contact-form-7.php') &&
            !is_plugin_active('contact-form-7/wp-contact-form-7.php')
        ) {
            echo '<p style="margin-bottom:0">Please install and activate the Contact Form 7 plugin to access its features.</p>';
        } else {
            // Get list of active Contact Form 7 forms
            $active_forms = WPCF7_ContactForm::find();

            if (!empty($active_forms)) {
                echo '<p>Select a form to view its fields:</p><form method="post" action=""><select name="wpsyncamo_selected_wpcf7" style="margin-right:6px">';
                foreach ($active_forms as $form) {
                    if (
                        isset($_POST['wpsyncamo_selected_wpcf7']) && $_POST['wpsyncamo_selected_wpcf7'] == esc_attr($form->id()) ||
                        isset($_POST['wpsyncamo_forms']['wpcf7']) && $_POST['wpsyncamo_forms']['wpcf7']['form_id'] == esc_attr($form->id())
                    ) {
                        echo '<option value="' . esc_attr($form->id()) . '" selected>' . esc_html($form->title()) . '</option>';
                        continue;
                    }
                    echo '<option value="' . esc_attr($form->id()) . '">' . esc_html($form->title()) . '</option>';
                }
                echo '</select><input type="submit" class="button button-secondary" value="Edit" /></form>';

                // Display fields for the selected form
                if (isset($_POST['wpsyncamo_selected_wpcf7'])) {
                    $selected_form_id = sanitize_text_field($_POST['wpsyncamo_selected_wpcf7']);
                } elseif (isset($_POST['wpsyncamo_forms']['wpcf7']['form_id'])) {
                    $selected_form_id = sanitize_text_field($_POST['wpsyncamo_forms']['wpcf7']['form_id']);
                }

                if (isset($selected_form_id)) {
                    $selected_form = WPCF7_ContactForm::get_instance($selected_form_id);
                }

                if (isset($selected_form)) {
                    $forms_option = get_option('wpsyncamo_forms');
                    $pipelines_collection = $this->amocrm_get_pipelines() ?? [];
                    $users_collection = $this->amocrm_get_users() ?? [];

                    echo '<form method="post" action=""><table class="form-table" role="presentation"><tbody>';

                    echo '<input type="hidden" name="wpsyncamo_forms[wpcf7][form_id]" value="' . esc_attr($selected_form_id) . '" />';

                    $crm_pipeline = isset($forms_option['wpcf7'][$selected_form_id]['pipeline']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['pipeline']) : null;
                    echo '<tr><th scope="row">Pipeline Id*<p style="margin-bottom:0;font-weight:normal;">The "Pipeline" field indicates in which funnel the leads will end up in the CRM system.</p></th><td><select name="wpsyncamo_forms[wpcf7][pipeline]" class="regular-text code" id="pipeline">';
                    foreach ($pipelines_collection as $pipeline) {
                        if ($crm_pipeline == esc_attr($pipeline->getId())) {
                            echo '<option value="' . esc_attr($pipeline->getId()) . '" selected>' . esc_html($pipeline->getName()) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($pipeline->getId()) . '">' . esc_html($pipeline->getName()) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_status = isset($forms_option['wpcf7'][$selected_form_id]['status']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['status']) : null;
                    echo '<tr><th scope="row">Status Id*<p style="margin-bottom:0;font-weight:normal;">The "Status" field indicates the stage the lead will reach.</p></th><td><select name="wpsyncamo_forms[wpcf7][status]" class="regular-text code" id="status">';
                    foreach ($pipelines_collection as $pipeline) {
                        echo '<optgroup label="' . $pipeline->getName() . '" id="' . $pipeline->getId() . '">';
                        $statuses = $pipeline->getStatuses();

                        foreach ($statuses as $status) {
                            if ($crm_status == esc_attr($status->getId())) {
                                echo '<option value="' . esc_attr($status->getId()) . '" selected>' . esc_html($status->getName()) . '</option>';
                                continue;
                            }
                            echo '<option value="' . esc_attr($status->getId()) . '">' . esc_html($status->getName()) . '</option>';
                        }
                        echo '</optgroup>';
                    }
                    echo '</select></td></tr>';

                    $crm_user_responsible = isset($forms_option['wpcf7'][$selected_form_id]['user_responsible']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['user_responsible']) : null;
                    echo '<tr><th scope="row">User responsible Id*<p style="margin-bottom:0;font-weight:normal;">Person responsible for lead processing.</p></th><td><select name="wpsyncamo_forms[wpcf7][user_responsible]" class="regular-text code">';
                    foreach ($users_collection as $user) {
                        if ($crm_user_responsible == esc_attr($user->getId())) {
                            echo '<option value="' . esc_attr($user->getId()) . '" selected>' . esc_html($user->getName()) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($user->getId()) . '">' . esc_html($user->getName()) . '</option>';
                    }
                    echo '</select></td></tr>';


                    $crm_tags = isset($forms_option['wpcf7'][$selected_form_id]['tags']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['tags']) : '#site, #contactform';
                    echo '<tr><th scope="row">Tags<p style="margin-bottom:0;font-weight:normal;">If you need to specify multiple tags, use a comma.</p></th><td><input type="text" name="wpsyncamo_forms[wpcf7][tags]" value="' . $crm_tags . '" class="regular-text code" /></td></tr>';

                    $crm_name = isset($forms_option['wpcf7'][$selected_form_id]['name']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['name']) : null;
                    echo '<tr><th scope="row">Name</th><td><select name="wpsyncamo_forms[wpcf7][name]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_name == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_phone = isset($forms_option['wpcf7'][$selected_form_id]['phone']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['phone']) : null;
                    echo '<tr><th scope="row">Phone</th><td><select name="wpsyncamo_forms[wpcf7][phone]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_phone == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_email = isset($forms_option['wpcf7'][$selected_form_id]['email']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['email']) : null;
                    echo '<tr><th scope="row">Email</th><td><select name="wpsyncamo_forms[wpcf7][email]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_email == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_text = isset($forms_option['wpcf7'][$selected_form_id]['text']) ? esc_attr($forms_option['wpcf7'][$selected_form_id]['text']) : null;
                    echo '<tr><th scope="row">Comment</th><td><select name="wpsyncamo_forms[wpcf7][text]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_text == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    echo '<tr><td></td><td><div class="contain-custom__text"><button class="button button-secondary add-field" disabled>Add custom field<span style="display:block;font-size:12px;margin-top:-8px">(Available in the pro version)</span></button></div></td></tr>';

                    echo '<tbody></table><p class="mb-0"><input type="submit" name="submit" class="button button-primary" value="Save changes" /></p></form>';
                }
            } else {
                echo '<p style="margin-bottom:0">No active forms found.</p>';
            }
        }
    }

    public function wpsyncamo_display_wpforms()
    {
        if (
            !file_exists(WP_PLUGIN_DIR . 'wpforms/wpforms.php') &&
            !is_plugin_active('wpforms/wpforms.php')
        ) {
            echo '<p style="margin-bottom:0">Please install and activate a licensed version of the WPForms plugin to access its features.</p>';
        } else {
            // Get list of active WPForms
            $active_forms = wpforms()->form->get();

            if (!empty($active_forms)) {
                echo '<p>Select a form to view its fields:</p><form method="post" action=""><select name="wpsyncamo_selected_wpforms" style="margin-right:6px">';
                foreach ($active_forms as $form) {
                    if (
                        isset($_POST['wpsyncamo_selected_wpforms']) && $_POST['wpsyncamo_selected_wpforms'] == esc_attr($form->ID) ||
                        isset($_POST['wpsyncamo_forms']['wpforms']) && $_POST['wpsyncamo_forms']['wpforms']['form_id'] == esc_attr($form->ID)
                    ) {
                        echo '<option value="' . esc_attr($form->ID) . '" selected>' . esc_html($form->post_title) . '</option>';
                        continue;
                    }
                    echo '<option value="' . esc_attr($form->ID) . '">' . esc_html($form->post_title) . '</option>';
                }
                echo '</select><input type="submit" class="button button-secondary" value="Edit" /></form>';

                // Display fields for the selected form
                if (isset($_POST['wpsyncamo_selected_wpforms'])) {
                    $selected_form_id = sanitize_text_field($_POST['wpsyncamo_selected_wpforms']);
                } elseif (isset($_POST['wpsyncamo_forms']['wpforms']['form_id'])) {
                    $selected_form_id = sanitize_text_field($_POST['wpsyncamo_forms']['wpforms']['form_id']);
                }

                if (isset($selected_form_id)) {
                    $selected_form = wpforms()->form->get($selected_form_id);
                    $selected_form_fields = !empty($selected_form->post_content) ? wpforms_decode($selected_form->post_content)['fields'] : [];
                }

                if (isset($selected_form)) {
                    $forms_option = get_option('wpsyncamo_forms');
                    $pipelines_collection = $this->amocrm_get_pipelines() ?? [];
                    $users_collection = $this->amocrm_get_users() ?? [];

                    echo '<form method="post" action=""><table class="form-table" role="presentation"><tbody>';

                    echo '<input type="hidden" name="wpsyncamo_forms[wpforms][form_id]" value="' . esc_attr($selected_form_id) . '" />';

                    $crm_pipeline = isset($forms_option['wpforms'][$selected_form_id]['pipeline']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['pipeline']) : null;
                    echo '<tr><th scope="row">Pipeline Id*<p style="margin-bottom:0;font-weight:normal;">The "Pipeline" field indicates in which funnel the leads will end up in the CRM system.</p></th><td><select name="wpsyncamo_forms[wpforms][pipeline]" class="regular-text code" id="pipeline">';
                    foreach ($pipelines_collection as $pipeline) {
                        if ($crm_pipeline == esc_attr($pipeline->getId())) {
                            echo '<option value="' . esc_attr($pipeline->getId()) . '" selected>' . esc_html($pipeline->getName()) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($pipeline->getId()) . '">' . esc_html($pipeline->getName()) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_status = isset($forms_option['wpforms'][$selected_form_id]['status']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['status']) : null;
                    echo '<tr><th scope="row">Status Id*<p style="margin-bottom:0;font-weight:normal;">The "Status" field indicates the stage the lead will reach.</p></th><td><select name="wpsyncamo_forms[wpforms][status]" class="regular-text code" id="status">';
                    foreach ($pipelines_collection as $pipeline) {
                        echo '<optgroup label="' . $pipeline->getName() . '" id="' . $pipeline->getId() . '">';
                        $statuses = $pipeline->getStatuses();

                        foreach ($statuses as $status) {
                            if ($crm_status == esc_attr($status->getId())) {
                                echo '<option value="' . esc_attr($status->getId()) . '" selected>' . esc_html($status->getName()) . '</option>';
                                continue;
                            }
                            echo '<option value="' . esc_attr($status->getId()) . '">' . esc_html($status->getName()) . '</option>';
                        }
                        echo '</optgroup>';
                    }
                    echo '</select></td></tr>';

                    $crm_user_responsible = isset($forms_option['wpforms'][$selected_form_id]['user_responsible']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['user_responsible']) : null;
                    echo '<tr><th scope="row">User responsible Id*<p style="margin-bottom:0;font-weight:normal;">Person responsible for lead processing.</p></th><td><select name="wpsyncamo_forms[wpforms][user_responsible]" class="regular-text code">';
                    foreach ($users_collection as $user) {
                        if ($crm_user_responsible == esc_attr($user->getId())) {
                            echo '<option value="' . esc_attr($user->getId()) . '" selected>' . esc_html($user->getName()) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($user->getId()) . '">' . esc_html($user->getName()) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_tags = isset($forms_option['wpforms'][$selected_form_id]['tags']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['tags']) : '#site, #wpforms';
                    echo '<tr><th scope="row">Tags<p style="margin-bottom:0;font-weight:normal;">If you need to specify multiple tags, use a comma.</p></th><td><input type="text" name="wpsyncamo_forms[wpforms][tags]" value="' . $crm_tags . '" class="regular-text code" /></td></tr>';

                    $crm_name = isset($forms_option['wpforms'][$selected_form_id]['name']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['name']) : null;
                    echo '<tr><th scope="row">Name</th><td><select name="wpsyncamo_forms[wpforms][name]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form_fields as $field) {
                        if ($crm_name == esc_attr($field['id'])) {
                            echo '<option value="' . esc_attr($field['id']) . '" selected>' . esc_html($field['label']) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($field['id']) . '">' . esc_html($field['label']) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_phone = isset($forms_option['wpforms'][$selected_form_id]['phone']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['phone']) : null;
                    echo '<tr><th scope="row">Phone</th><td><select name="wpsyncamo_forms[wpforms][phone]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form_fields as $field) {
                        if ($crm_phone == esc_attr($field['id'])) {
                            echo '<option value="' . esc_attr($field['id']) . '" selected>' . esc_html($field['label']) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($field['id']) . '">' . esc_html($field['label']) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_email = isset($forms_option['wpforms'][$selected_form_id]['email']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['email']) : null;
                    echo '<tr><th scope="row">Email</th><td><select name="wpsyncamo_forms[wpforms][email]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form_fields as $field) {
                        if ($crm_email == esc_attr($field['id'])) {
                            echo '<option value="' . esc_attr($field['id']) . '" selected>' . esc_html($field['label']) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($field['id']) . '">' . esc_html($field['label']) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_text = isset($forms_option['wpforms'][$selected_form_id]['text']) ? esc_attr($forms_option['wpforms'][$selected_form_id]['text']) : null;
                    echo '<tr><th scope="row">Comment</th><td><select name="wpsyncamo_forms[wpforms][text]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form_fields as $field) {
                        if ($crm_text == esc_attr($field['id'])) {
                            echo '<option value="' . esc_attr($field['id']) . '" selected>' . esc_html($field['label']) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($field['id']) . '">' . esc_html($field['label']) . '</option>';
                    }
                    echo '</select></td></tr>';

                    echo '<tr><td></td><td><div class="contain-custom__text"><button class="button button-secondary add-field" disabled>Add custom field<span style="display:block;font-size:12px;margin-top:-8px">(Available in the pro version)</span></button></div></td></tr>';

                    echo '<tbody></table><p class="mb-0"><input type="submit" name="submit" class="button button-primary" value="Save changes" /></p></form>';
                }
            } else {
                echo '<p style="margin-bottom:0">No active forms found.</p>';
            }
        }
    }

    public function wpsyncamo_display_woocommerce()
    {
        echo '<p style="margin-bottom:0">The integration with WooCommerce is only available in the pro version of the module.</p>';
    }

    public function wpsyncamo_display_elementor()
    {
        echo '<p style="margin-bottom:0">The integration with Elementor Forms is only available in the pro version of the module.</p>';
    }

    public function wpsyncamo_update_forms()
    {
        if (isset($_POST['wpsyncamo_forms'])) {
            $plugin = array_keys($_POST['wpsyncamo_forms'])[0];
            $new_settings_forms = $_POST['wpsyncamo_forms'];
            $submit_selected_form_id = sanitize_text_field($new_settings_forms[$plugin]['form_id']);

            unset($new_settings_forms[$plugin]['form_id']);

            $forms_option = get_option('wpsyncamo_forms');

            if ($forms_option === false) {
                update_option('wpsyncamo_forms', [$plugin => [$submit_selected_form_id => $new_settings_forms[$plugin]]]);
            } else {
                $forms_option[$plugin][$submit_selected_form_id] = $new_settings_forms[$plugin];
                update_option('wpsyncamo_forms', $forms_option);
            }

            echo '<div class="notice notice-success"><p>Form settings have been saved.</p></div>';
        }
    }

    private static function amocrm_get_api_client()
    {
        if (self::$apiClient === null) {
            $file_auth = WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env';

            define('FILE_AUTH_TOKEN', WPSYNCAMO_DIR_PLUGIN . 'secret/auth_token.json');

            $dotenv = new Dotenv(false);
            $dotenv->load($file_auth);

            $api_client = new AmoCRMApiClient($_ENV['CLIENT_ID'], $_ENV['CLIENT_SECRET'], $_ENV['CLIENT_REDIRECT_URI']);
            $api_client->getOAuthClient()->setBaseDomain($_ENV['CLIENT_BASE_DOMAIN']);

            function getToken()
            {
                if (!file_exists(FILE_AUTH_TOKEN)) {
                    throw new \Exception('Authentication token is missing.');
                }

                $access_token = json_decode(file_get_contents(FILE_AUTH_TOKEN), true);

                return new AccessToken([
                    'access_token' => $access_token['accessToken'],
                    'refresh_token' => $access_token['refreshToken'],
                    'expires' => $access_token['expires'],
                    'baseDomain' => $access_token['baseDomain'],
                ]);
            }

            function saveToken($accessToken)
            {
                if (
                    isset($accessToken['accessToken'])
                    && isset($accessToken['refreshToken'])
                    && isset($accessToken['expires'])
                    && isset($accessToken['baseDomain'])
                ) {
                    $data = [
                        'accessToken' => $accessToken['accessToken'],
                        'refreshToken' => $accessToken['refreshToken'],
                        'expires' => $accessToken['expires'],
                        'baseDomain' => $accessToken['baseDomain'],
                    ];

                    file_put_contents(FILE_AUTH_TOKEN, json_encode($data));
                } else {
                    throw new \Exception('Invalid access token ' . var_export($accessToken, true));
                }
            }

            $access_token = getToken();
            $api_client->setAccessToken($access_token)
                ->setAccountBaseDomain($access_token->getValues()['baseDomain'])
                ->onAccessTokenRefresh(
                    function (AccessTokenInterface $accessToken, string $baseDomain) {
                        saveToken(
                            [
                                'accessToken' => $accessToken->getToken(),
                                'refreshToken' => $accessToken->getRefreshToken(),
                                'expires' => $accessToken->getExpires(),
                                'baseDomain' => $baseDomain,
                            ]
                        );
                    }
                );

            self::$apiClient = $api_client;
        }

        return self::$apiClient;
    }

    private function amocrm_get_pipelines()
    {
        $api_client = self::amocrm_get_api_client();

        try {
            $pipelines_service = $api_client->pipelines();
            $pipelines_collection = $pipelines_service->get();

            return $pipelines_collection;
        } catch (AmoCRMApiException $e) {
            trigger_error($e . PHP_EOL . $e->getMessage(), E_USER_WARNING);
        }

        return null;
    }

    private function amocrm_get_users()
    {
        $api_client = self::amocrm_get_api_client();

        try {
            $users_service = $api_client->users();
            $users_collection = $users_service->get();

            return $users_collection;
        } catch (AmoCRMApiException $e) {
            trigger_error($e . PHP_EOL . $e->getMessage(), E_USER_WARNING);
        }

        return null;
    }
}
