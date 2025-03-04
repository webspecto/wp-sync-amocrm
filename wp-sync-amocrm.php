<?php

/**
 * @author            Iulian Ceapa <dev@webspecto.com>
 * @copyright         © 2023-2025 WebSpecto.
 * @license           GPL-2.0-or-later
 * @link              https://www.webspecto.com/plugins/wp-sync-amocrm
 * @version           0.8
 * 
 * @wordpress-plugin
 * Plugin Name:       Connector CRM for WordPress
 * Plugin URI:        https://www.webspecto.com/plugins/wp-sync-amocrm
 * Description:       Connects WordPress with AmoCRM | Kommo for data synchronization.
 * Version:           0.8
 * Requires at least: 6.1
 * Requires PHP:      8.0
 * Author:            Iulian Ceapa
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') or die('Access denied');

define('WPSYNCAMO_DIR_PLUGIN', plugin_dir_path(__FILE__));

require_once WPSYNCAMO_DIR_PLUGIN . 'vendor/autoload.php';

if (is_admin()) {
    add_filter('plugin_action_links', 'wpsyncamo_settings_link', 10, 2);

    function wpsyncamo_settings_link($actions, $plugin_file)
    {
        if (plugin_basename(__FILE__) == $plugin_file) {
            array_unshift($actions, '<a href="admin.php?page=wpsyncamo">Settings</a>');
            array_unshift($actions, '<a href="https://www.webspecto.com/plugins/wp-sync-amocrm"><span style="background-color: #3858E9; color: white; font-weight: bold; padding: 1px 6px 2px;">WebSpecto</span></a>');
        }
        return $actions;
    }

    add_action('plugins_loaded', 'wpsyncamo_load_admin');

    function wpsyncamo_load_admin()
    {
        require_once WPSYNCAMO_DIR_PLUGIN . 'admin/admin.php';
        new WP_Sync_AmoCRM_Admin();
    }
} else {
    add_action('wpcf7_before_send_mail', 'wpsyncamo_wpcf7');

    function wpsyncamo_wpcf7($contact_form)
    {
        require_once WPSYNCAMO_DIR_PLUGIN . 'inc/amocrm_wpcf7.php';
        new WP_Sync_AmoCRM_WPCF7($contact_form->id());
    }

    add_action('wpforms_process_complete', 'wpsyncamo_wpforms', 10, 4);

    function wpsyncamo_wpforms($fields, $entry, $form_data, $entry_id)
    {
        require_once WPSYNCAMO_DIR_PLUGIN . 'inc/amocrm_wpforms.php';
        new WP_Sync_AmoCRM_WPForms($form_data['id'], $entry['fields']);
    }
}
