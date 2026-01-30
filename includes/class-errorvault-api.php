<?php
/**
 * API class for ErrorVault
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_API {

    /**
     * Settings
     */
    private $settings;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('errorvault_settings', array());
    }

    /**
     * Get API endpoint
     */
    private function get_endpoint() {
        return isset($this->settings['api_endpoint']) ? $this->settings['api_endpoint'] : '';
    }

    /**
     * Get API token
     */
    private function get_token() {
        return isset($this->settings['api_token']) ? $this->settings['api_token'] : '';
    }

    /**
     * Send single error to API
     */
    public function send_error($error_data) {
        $endpoint = $this->get_endpoint();
        $token = $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return false;
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => 5,
            'blocking' => false, // Non-blocking request
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
            'body' => wp_json_encode($error_data),
        ));

        if (is_wp_error($response)) {
            // Log locally if API request fails
            error_log('[ErrorVault] Failed to send error: ' . $response->get_error_message());
            return false;
        }

        return true;
    }

    /**
     * Send batch of errors to API
     */
    public function send_batch($errors) {
        $endpoint = rtrim($this->get_endpoint(), '/') . '/batch';
        $token = $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return false;
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'blocking' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
            'body' => wp_json_encode(array('errors' => $errors)),
        ));

        if (is_wp_error($response)) {
            error_log('[ErrorVault] Failed to send batch: ' . $response->get_error_message());
            return false;
        }

        return true;
    }

    /**
     * Verify API token
     */
    public function verify_token($endpoint = null, $token = null) {
        $endpoint = $endpoint ?: $this->get_endpoint();
        $token = $token ?: $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return array(
                'success' => false,
                'error' => 'API endpoint and token are required.',
            );
        }

        // Build verify endpoint
        $verify_endpoint = str_replace('/errors', '/verify', rtrim($endpoint, '/'));

        $response = wp_remote_get($verify_endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200 && !empty($body['success'])) {
            return array(
                'success' => true,
                'data' => $body['data'],
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Invalid response from server.',
        );
    }

    /**
     * Get site statistics
     */
    public function get_stats() {
        $endpoint = str_replace('/errors', '/stats', rtrim($this->get_endpoint(), '/'));
        $token = $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return null;
        }

        $response = wp_remote_get($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['success'])) {
            return $body['data'];
        }

        return null;
    }

    /**
     * Send health alert to API
     */
    public function send_health_alert($alert_data) {
        $endpoint = str_replace('/errors', '/health/alert', rtrim($this->get_endpoint(), '/'));
        $token = $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return false;
        }

        // Add common context
        $alert_data['site_url'] = get_site_url();
        $alert_data['site_name'] = get_bloginfo('name');
        $alert_data['timestamp'] = current_time('mysql');
        $alert_data['wp_version'] = get_bloginfo('version');
        $alert_data['php_version'] = PHP_VERSION;
        $alert_data['plugin_version'] = ERRORVAULT_VERSION;

        $response = wp_remote_post($endpoint, array(
            'timeout' => 5,
            'blocking' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
            'body' => wp_json_encode($alert_data),
        ));

        if (is_wp_error($response)) {
            error_log('[ErrorVault] Failed to send health alert: ' . $response->get_error_message());
            return false;
        }

        return true;
    }

    /**
     * Send lightweight ping to update last_seen_at
     */
    public function send_ping() {
        $endpoint = str_replace('/errors', '/ping', rtrim($this->get_endpoint(), '/'));
        $token = $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return false;
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => 5,
            'blocking' => true, // Blocking to detect failures
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
            'body' => wp_json_encode(array(
                'timestamp' => time(),
                'site_url' => get_site_url(),
            )),
        ));

        if (is_wp_error($response)) {
            $this->log_connection_failure('ping', $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 300) {
            $this->clear_connection_failures();
            return true;
        }

        $this->log_connection_failure('ping', 'HTTP ' . $status_code);
        return false;
    }

    /**
     * Send periodic health report to API
     *
     * @param array $health_data The health data to send
     * @param bool $blocking Whether to wait for response (true for testing)
     * @return bool|array Returns true/false for non-blocking, or response data for blocking
     */
    public function send_health_report($health_data, $blocking = false) {
        $endpoint = str_replace('/errors', '/health/report', rtrim($this->get_endpoint(), '/'));
        $token = $this->get_token();

        if (empty($endpoint) || empty($token)) {
            return false;
        }

        // Add common context
        $health_data['site_url'] = get_site_url();
        $health_data['site_name'] = get_bloginfo('name');
        $health_data['plugin_version'] = ERRORVAULT_VERSION;

        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'blocking' => $blocking,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Token' => $token,
                'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
            ),
            'body' => wp_json_encode($health_data),
        ));

        if (is_wp_error($response)) {
            error_log('[ErrorVault] Failed to send health report: ' . $response->get_error_message());
            return false;
        }

        // For blocking requests, check response code
        if ($blocking) {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code >= 200 && $status_code < 300) {
                return true;
            }
            error_log('[ErrorVault] Health report failed with status: ' . $status_code);
            return false;
        }

        return true;
    }

    /**
     * Log connection failure for diagnostics
     */
    private function log_connection_failure($type, $message) {
        $failures = get_option('errorvault_connection_failures', array());
        
        $failures[] = array(
            'type' => $type,
            'message' => $message,
            'timestamp' => current_time('mysql'),
            'time' => time(),
        );

        // Keep only last 20 failures
        $failures = array_slice($failures, -20);
        update_option('errorvault_connection_failures', $failures);

        // Log to PHP error log
        error_log('[ErrorVault] Connection failure (' . $type . '): ' . $message);

        // Track consecutive failures
        $consecutive = (int) get_transient('errorvault_consecutive_failures');
        set_transient('errorvault_consecutive_failures', $consecutive + 1, 3600);

        // Send admin notification after 5 consecutive failures
        if ($consecutive >= 5) {
            $this->notify_admin_of_failures();
        }
    }

    /**
     * Clear connection failure tracking on success
     */
    private function clear_connection_failures() {
        delete_transient('errorvault_consecutive_failures');
    }

    /**
     * Get recent connection failures for diagnostics
     */
    public function get_connection_failures() {
        return get_option('errorvault_connection_failures', array());
    }

    /**
     * Clear connection failure log
     */
    public function clear_failure_log() {
        delete_option('errorvault_connection_failures');
        delete_transient('errorvault_consecutive_failures');
    }

    /**
     * Notify admin of repeated connection failures
     */
    private function notify_admin_of_failures() {
        // Only send once per day
        if (get_transient('errorvault_failure_notification_sent')) {
            return;
        }

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $failures = $this->get_connection_failures();
        $recent = array_slice($failures, -5);

        $message = "ErrorVault plugin on {$site_name} has experienced repeated connection failures.\n\n";
        $message .= "Recent failures:\n";
        foreach ($recent as $failure) {
            $message .= "- {$failure['timestamp']}: {$failure['type']} - {$failure['message']}\n";
        }
        $message .= "\nPlease check your ErrorVault settings and API endpoint.\n";
        $message .= "Settings: " . admin_url('options-general.php?page=errorvault');

        wp_mail(
            $admin_email,
            '[ErrorVault] Connection Issues Detected',
            $message
        );

        set_transient('errorvault_failure_notification_sent', true, DAY_IN_SECONDS);
    }
}
