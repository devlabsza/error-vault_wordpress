<?php
/**
 * Plugin Name: ErrorVault
 * Plugin URI: https://errorvault.com
 * Description: Send PHP errors to your ErrorVault dashboard for centralized error monitoring.
 * Version: 1.2.2
 * Author: ErrorVault
 * Author URI: https://errorvault.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: errorvault
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ERRORVAULT_VERSION', '1.2.1');
define('ERRORVAULT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ERRORVAULT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ERRORVAULT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-admin.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-error-handler.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-api.php';
require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-errorvault-health-monitor.php';

/**
 * Initialize the plugin
 */
function errorvault_init() {
    $plugin = new ErrorVault();
    $plugin->init();

    // Initialize health monitor
    new ErrorVault_Health_Monitor();
}
add_action('plugins_loaded', 'errorvault_init');

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
