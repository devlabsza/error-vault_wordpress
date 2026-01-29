<?php
/**
 * Main ErrorVault class
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault {

    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Settings
     */
    private $settings;

    /**
     * Error handler instance
     */
    private $error_handler;

    /**
     * Admin instance
     */
    private $admin;

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('errorvault_settings', array());
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Initialize admin
        if (is_admin()) {
            $this->admin = new ErrorVault_Admin();
        }

        // Initialize error handler if enabled
        if ($this->is_enabled()) {
            $this->error_handler = new ErrorVault_Error_Handler();
            $this->error_handler->register();
        }
    }

    /**
     * Check if error logging is enabled
     */
    public function is_enabled() {
        return !empty($this->settings['enabled']) && !empty($this->settings['api_token']);
    }

    /**
     * Get setting value
     */
    public function get_setting($key, $default = null) {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    /**
     * Update setting
     */
    public function update_setting($key, $value) {
        $this->settings[$key] = $value;
        update_option('errorvault_settings', $this->settings);
    }

    /**
     * Get all settings
     */
    public function get_settings() {
        return $this->settings;
    }
}
