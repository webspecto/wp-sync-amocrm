<?php

/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023-2024 WebSpecto.
 */

use Symfony\Component\Dotenv\Dotenv;

defined('ABSPATH') or die('Access denied');

class WP_Sync_AmoCRM_Admin
{
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
        wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css');
        wp_enqueue_style('wpsyncamo', plugins_url('/wp-sync-amocrm/assets/css/style.css'));
        wp_enqueue_script('jquery', 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js');
        wp_enqueue_script('wpsyncamo', plugins_url('/wp-sync-amocrm/assets/js/script.js'));
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

        $file_auth = file_exists(WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env') ? true : false;
        $file_auth_token = file_exists(WPSYNCAMO_DIR_PLUGIN . 'secret/auth_token.json') ? true : false;

        echo '<div class="wrap">';
        echo '<h1>AmoCRM | Kommo Integration</h1>';

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
                <p><a href="' . plugins_url('/wp-sync-amocrm/oauth2-amocrm.php') . '" class="button button-secondary" target="_blank">Authorize with AmoCRM</a></p>';
            } else {
                $this->wpsyncamo_update_forms();
                $this->wpsyncamo_revoke_authorization();

                $current_tab = isset($_POST['wpsyncamo_forms']) && $_POST['wpsyncamo_forms']['form_id'] == 0 ? 'woocommerce' : 'wpcf7';

                $tabs = [
                    'wpcf7' => 'Contact form 7',
                    'woocommerce' => 'Woocommerce',
                    'elementor' => 'Elementor Forms'
                ];

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
            echo '<div class="notice notice-success"><p>Settings and token data have been deleted. Please refresh the page.</p></div>';
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
                        isset($_POST['wpsyncamo_forms']) && $_POST['wpsyncamo_forms']['form_id'] == esc_attr($form->id())
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
                } elseif (isset($_POST['wpsyncamo_forms']['form_id'])) {
                    $selected_form_id = sanitize_text_field($_POST['wpsyncamo_forms']['form_id']);
                }

                if (isset($selected_form_id)) {
                    $selected_form = WPCF7_ContactForm::get_instance($selected_form_id);
                }

                if (isset($selected_form)) {
                    $forms_option = get_option('wpsyncamo_forms');

                    echo '<form method="post" action=""><table class="form-table" role="presentation"><tbody>';

                    echo '<input type="hidden" name="wpsyncamo_forms[form_id]" value="' . esc_attr($selected_form_id) . '" />';

                    $crm_pipeline = isset($forms_option[$selected_form_id]['pipeline']) ? esc_attr($forms_option[$selected_form_id]['pipeline']) : null;
                    echo '<tr><th scope="row">Pipeline Id*<p style="margin-bottom:0;font-weight:normal;">The "Pipeline" field indicates in which funnel the leads will end up in the CRM system.</p></th><td><input type="number" name="wpsyncamo_forms[pipeline]" value="' . $crm_pipeline . '" min="0" class="regular-text code" required /></td></tr>';

                    $crm_status = isset($forms_option[$selected_form_id]['status']) ? esc_attr($forms_option[$selected_form_id]['status']) : null;
                    echo '<tr><th scope="row">Status Id*<p style="margin-bottom:0;font-weight:normal;">The "Status" field indicates the stage the lead will reach.</p></th><td><input type="number" name="wpsyncamo_forms[status]" value="' . $crm_status . '" min="0" class="regular-text code" required /></td></tr>';

                    $crm_user_responsible = isset($forms_option[$selected_form_id]['user_responsible']) ? esc_attr($forms_option[$selected_form_id]['user_responsible']) : null;
                    echo '<tr><th scope="row">User responsible Id*<p style="margin-bottom:0;font-weight:normal;">Person responsible for lead processing.</p></th><td><input type="number" name="wpsyncamo_forms[user_responsible]" value="' . $crm_user_responsible . '" min="0" class="regular-text code" required /></td></tr>';

                    $crm_tags = isset($forms_option[$selected_form_id]['tags']) ? esc_attr($forms_option[$selected_form_id]['tags']) : '#site, #contactform';
                    echo '<tr><th scope="row">Tags<p style="margin-bottom:0;font-weight:normal;">If you need to specify multiple tags, use a comma.</p></th><td><input type="text" name="wpsyncamo_forms[tags]" value="' . $crm_tags . '" class="regular-text code" /></td></tr>';

                    $crm_name = isset($forms_option[$selected_form_id]['name']) ? esc_attr($forms_option[$selected_form_id]['name']) : null;
                    echo '<tr><th scope="row">Name</th><td><select name="wpsyncamo_forms[name]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_name == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_phone = isset($forms_option[$selected_form_id]['phone']) ? esc_attr($forms_option[$selected_form_id]['phone']) : null;
                    echo '<tr><th scope="row">Phone</th><td><select name="wpsyncamo_forms[phone]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_phone == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_email = isset($forms_option[$selected_form_id]['email']) ? esc_attr($forms_option[$selected_form_id]['email']) : null;
                    echo '<tr><th scope="row">Email</th><td><select name="wpsyncamo_forms[email]" class="regular-text code">';
                    echo '<option value="">-</option>';
                    foreach ($selected_form->collect_mail_tags() as $tag) {
                        if ($crm_email == esc_attr($tag)) {
                            echo '<option value="' . esc_attr($tag) . '" selected>' . esc_html($tag) . '</option>';
                            continue;
                        }
                        echo '<option value="' . esc_attr($tag) . '">' . esc_html($tag) . '</option>';
                    }
                    echo '</select></td></tr>';

                    $crm_text = isset($forms_option[$selected_form_id]['text']) ? esc_attr($forms_option[$selected_form_id]['text']) : null;
                    echo '<tr><th scope="row">Comment</th><td><select name="wpsyncamo_forms[text]" class="regular-text code">';
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
            $new_settings_forms = $_POST['wpsyncamo_forms'];
            $submit_selected_form_id = sanitize_text_field($new_settings_forms['form_id']);
            unset($new_settings_forms['form_id']);

            $forms_option = get_option('wpsyncamo_forms');

            if ($forms_option === false) {
                update_option('wpsyncamo_forms', [$submit_selected_form_id => $new_settings_forms]);
            } else {
                $forms_option[$submit_selected_form_id] = $new_settings_forms;
                update_option('wpsyncamo_forms', $forms_option);
            }

            echo '<div class="notice notice-success"><p>Form settings have been saved.</p></div>';
        }
    }
}
