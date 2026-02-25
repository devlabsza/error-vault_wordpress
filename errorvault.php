<?php
/**
 * Plugin Name: ErrorVault
 * Plugin URI: https://error-vault.com
 * Description: Send PHP errors to your ErrorVault dashboard for centralized error monitoring.
 * Version: 1.4.2
 * Author: ErrorVault
 * Author URI: https://error-vault.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: errorvault
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ERRORVAULT_VERSION', '1.4.2');
define('ERRORVAULT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ERRORVAULT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ERRORVAULT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-admin.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-error-handler.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-api.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-health-monitor.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-updater.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-ev-cron.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-ev-backup-manager.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-ev-db-exporter.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-ev-backup-helpers.php';

/**
 * Initialize the plugin
 */
function errorvault_init() {
    $plugin = new ErrorVault();
    $plugin->init();

    // Initialize health monitor
    new ErrorVault_Health_Monitor();
    
    // Initialize GitHub updater
    new ErrorVault_Updater(__FILE__);
    
    // Initialize backup cron
    EV_Cron::init();
}
add_action('plugins_loaded', 'errorvault_init');

/**
 * Add custom cron interval for 5 minutes
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'errorvault'),
    );
    return $schedules;
});

/**
 * Schedule heartbeat every 5 minutes
 */
add_action('errorvault_heartbeat', function() {
    $settings = get_option('errorvault_settings', array());
    
    // Only send if health monitoring is enabled
    if (empty($settings['health_monitoring_enabled']) || empty($settings['api_endpoint']) || empty($settings['api_token'])) {
        return;
    }
    
    $api_endpoint = str_replace('/errors', '/ping', rtrim($settings['api_endpoint'], '/'));
    
    wp_remote_post($api_endpoint, array(
        'headers' => array('X-API-Token' => $settings['api_token']),
        'timeout' => 5,
        'blocking' => false, // Non-blocking for performance
    ));
});

if (!wp_next_scheduled('errorvault_heartbeat')) {
    wp_schedule_event(time(), 'five_minutes', 'errorvault_heartbeat');
}

/**
 * Activation hook
 */
function errorvault_activate() {
    // Set default options
    $defaults = array(
        'api_endpoint' => 'https://your-errorvault-portal.com/api/v1/errors',
        'api_token' => '',
        'enabled' => false,
        'log_levels' => array('error', 'critical', 'fatal'),
        'include_notices' => false,
        'include_warnings' => true,
        'send_immediately' => true,
        'batch_size' => 10,
        'exclude_patterns' => array(),
    );

    if (!get_option('errorvault_settings')) {
        add_option('errorvault_settings', $defaults);
    }
}
register_activation_hook(__FILE__, 'errorvault_activate');

/**
 * Deactivation hook
 */
function errorvault_deactivate() {
    // Restore default error handler
    restore_error_handler();
    restore_exception_handler();

    // Clean up health monitor cron
    ErrorVault_Health_Monitor::deactivate();
    
    // Clean up heartbeat cron
    wp_clear_scheduled_hook('errorvault_heartbeat');
    
    // Clean up backup cron
    EV_Cron::unschedule_backup_poll();
}
register_deactivation_hook(__FILE__, 'errorvault_deactivate');

/**
 * Add settings link on plugin page
 */
function errorvault_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=errorvault">' . __('Settings', 'errorvault') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . ERRORVAULT_PLUGIN_BASENAME, 'errorvault_settings_link');
