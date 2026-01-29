<?php
/**
 * Health Monitor class for ErrorVault
 * Monitors server health and detects potential issues like DDoS, CPU overload, memory pressure
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_Health_Monitor {

    /**
     * Settings
     */
    private $settings;

    /**
     * API instance
     */
    private $api;

    /**
     * Transient keys
     */
    const REQUEST_COUNT_KEY = 'errorvault_request_count';
    const REQUEST_IPS_KEY = 'errorvault_request_ips';
    const REQUEST_URLS_KEY = 'errorvault_request_urls';
    const LAST_ALERT_KEY = 'errorvault_last_health_alert';
    const HEALTH_STATS_KEY = 'errorvault_health_stats';

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('errorvault_settings', array());
        $this->api = new ErrorVault_API();

        // Add custom cron interval (must be registered early, before scheduling)
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Register cron action handler (even if disabled, so scheduled events can be processed)
        add_action('errorvault_health_check_cron', array($this, 'send_periodic_health_report'));

        // AJAX handler for test health report (always register)
        add_action('wp_ajax_errorvault_test_health', array($this, 'ajax_test_health_report'));

        // Only run monitoring if health monitoring is enabled
        if (!$this->is_enabled()) {
            // Clear scheduled cron if disabled
            $timestamp = wp_next_scheduled('errorvault_health_check_cron');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'errorvault_health_check_cron');
            }
            return;
        }

        // Track every request
        add_action('init', array($this, 'track_request'), 1);

        // Run health checks
        add_action('init', array($this, 'run_health_checks'), 5);

        // Schedule periodic health report (every 5 minutes)
        if (!wp_next_scheduled('errorvault_health_check_cron')) {
            wp_schedule_event(time(), 'five_minutes', 'errorvault_health_check_cron');
        }
    }

    /**
     * Check if health monitoring is enabled
     */
    public function is_enabled() {
        return !empty($this->settings['health_monitoring_enabled'])
            && !empty($this->settings['api_endpoint'])
            && !empty($this->settings['api_token']);
    }

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'errorvault'),
        );
        return $schedules;
    }

    /**
     * Track incoming request
     */
    public function track_request() {
        // Don't track admin requests or AJAX calls (reduce noise)
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        $window = $this->get_time_window();
        $ip = $this->get_client_ip();

        // Increment request count
        $count_key = self::REQUEST_COUNT_KEY . '_' . $window;
        $count = (int) get_transient($count_key);
        set_transient($count_key, $count + 1, 120); // 2 minute expiry

        // Track unique IPs
        $ips_key = self::REQUEST_IPS_KEY . '_' . $window;
        $ips = get_transient($ips_key);
        if (!is_array($ips)) {
            $ips = array();
        }

        if (!isset($ips[$ip])) {
            $ips[$ip] = 0;
        }
        $ips[$ip]++;
        set_transient($ips_key, $ips, 120);

        // Track URLs
        $urls_key = self::REQUEST_URLS_KEY . '_' . $window;
        $urls = get_transient($urls_key);
        if (!is_array($urls)) {
            $urls = array();
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        // Normalize URL (remove query strings for grouping)
        $url_path = strtok($request_uri, '?');
        
        if (!isset($urls[$url_path])) {
            $urls[$url_path] = 0;
        }
        $urls[$url_path]++;
        set_transient($urls_key, $urls, 120);

        // Track request start time for response time calculation
        if (!defined('ERRORVAULT_REQUEST_START')) {
            define('ERRORVAULT_REQUEST_START', microtime(true));
        }
    }

    /**
     * Run health checks on each request
     */
    public function run_health_checks() {
        $alerts = array();

        // Check CPU load
        $cpu_alert = $this->check_cpu_load();
        if ($cpu_alert) {
            $alerts[] = $cpu_alert;
        }

        // Check memory usage
        $memory_alert = $this->check_memory_usage();
        if ($memory_alert) {
            $alerts[] = $memory_alert;
        }

        // Check request rate (potential DDoS)
        $ddos_alert = $this->check_request_rate();
        if ($ddos_alert) {
            $alerts[] = $ddos_alert;
        }

        // Send alerts if any (with rate limiting)
        if (!empty($alerts)) {
            $this->send_health_alerts($alerts);
        }
    }

    /**
     * Check CPU load
     */
    private function check_cpu_load() {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        if ($load === false) {
            return null;
        }

        $cpu_cores = $this->get_cpu_cores();
        $threshold = isset($this->settings['cpu_load_threshold'])
            ? (float) $this->settings['cpu_load_threshold']
            : 2.0; // Default: alert when load > 2x cores

        $load_ratio = $cpu_cores > 0 ? $load[0] / $cpu_cores : $load[0];

        if ($load_ratio >= $threshold) {
            return array(
                'type' => 'cpu_overload',
                'severity' => $load_ratio >= ($threshold * 1.5) ? 'critical' : 'warning',
                'message' => sprintf(
                    'High CPU load detected: %.2f (%.1f%% of capacity)',
                    $load[0],
                    $load_ratio * 100
                ),
                'data' => array(
                    'load_1min' => $load[0],
                    'load_5min' => $load[1],
                    'load_15min' => $load[2],
                    'cpu_cores' => $cpu_cores,
                    'load_ratio' => round($load_ratio, 2),
                    'threshold' => $threshold,
                ),
            );
        }

        return null;
    }

    /**
     * Check memory usage
     */
    private function check_memory_usage() {
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();

        if ($memory_limit <= 0) {
            return null; // Unlimited memory
        }

        $threshold = isset($this->settings['memory_threshold'])
            ? (int) $this->settings['memory_threshold']
            : 80; // Default: 80%

        $usage_percent = ($memory_usage / $memory_limit) * 100;

        if ($usage_percent >= $threshold) {
            return array(
                'type' => 'memory_pressure',
                'severity' => $usage_percent >= 95 ? 'critical' : 'warning',
                'message' => sprintf(
                    'High memory usage: %s of %s (%.1f%%)',
                    $this->format_bytes($memory_usage),
                    $this->format_bytes($memory_limit),
                    $usage_percent
                ),
                'data' => array(
                    'usage' => $memory_usage,
                    'usage_formatted' => $this->format_bytes($memory_usage),
                    'limit' => $memory_limit,
                    'limit_formatted' => $this->format_bytes($memory_limit),
                    'usage_percent' => round($usage_percent, 1),
                    'threshold' => $threshold,
                ),
            );
        }

        return null;
    }

    /**
     * Check request rate for potential DDoS
     */
    private function check_request_rate() {
        $window = $this->get_time_window();
        $prev_window = $this->get_time_window(-1);

        // Get current minute request count
        $current_count = (int) get_transient(self::REQUEST_COUNT_KEY . '_' . $window);
        $prev_count = (int) get_transient(self::REQUEST_COUNT_KEY . '_' . $prev_window);

        // Get thresholds from settings
        $rate_threshold = isset($this->settings['request_rate_threshold'])
            ? (int) $this->settings['request_rate_threshold']
            : 100; // Default: 100 requests per minute

        $spike_threshold = isset($this->settings['request_spike_threshold'])
            ? (float) $this->settings['request_spike_threshold']
            : 3.0; // Default: 3x normal rate

        // Check absolute threshold
        if ($current_count >= $rate_threshold) {
            // Get unique IPs
            $ips = get_transient(self::REQUEST_IPS_KEY . '_' . $window);
            $unique_ips = is_array($ips) ? count($ips) : 0;
            $top_ips = $this->get_top_ips($ips, 5);

            // Get top URLs
            $urls = get_transient(self::REQUEST_URLS_KEY . '_' . $window);
            $top_urls = $this->get_top_urls($urls, 10);

            return array(
                'type' => 'high_request_rate',
                'severity' => $current_count >= ($rate_threshold * 2) ? 'critical' : 'warning',
                'message' => sprintf(
                    'High request rate detected: %d requests/min from %d unique IPs',
                    $current_count,
                    $unique_ips
                ),
                'data' => array(
                    'requests_per_minute' => $current_count,
                    'unique_ips' => $unique_ips,
                    'top_ips' => $top_ips,
                    'top_urls' => $top_urls,
                    'threshold' => $rate_threshold,
                    'potential_ddos' => $unique_ips < 10 && $current_count > $rate_threshold * 2,
                ),
            );
        }

        // Check for sudden spike
        if ($prev_count > 10 && $current_count >= ($prev_count * $spike_threshold)) {
            $ips = get_transient(self::REQUEST_IPS_KEY . '_' . $window);
            $unique_ips = is_array($ips) ? count($ips) : 0;

            // Get top URLs
            $urls = get_transient(self::REQUEST_URLS_KEY . '_' . $window);
            $top_urls = $this->get_top_urls($urls, 10);

            return array(
                'type' => 'traffic_spike',
                'severity' => 'warning',
                'message' => sprintf(
                    'Traffic spike detected: %d requests (%.1fx increase)',
                    $current_count,
                    $current_count / $prev_count
                ),
                'data' => array(
                    'current_rate' => $current_count,
                    'previous_rate' => $prev_count,
                    'increase_factor' => round($current_count / $prev_count, 1),
                    'unique_ips' => $unique_ips,
                    'top_urls' => $top_urls,
                ),
            );
        }

        return null;
    }

    /**
     * Send health alerts to ErrorVault
     */
    private function send_health_alerts($alerts) {
        // Rate limit alerts (max 1 per type per 5 minutes)
        $last_alerts = get_transient(self::LAST_ALERT_KEY);
        if (!is_array($last_alerts)) {
            $last_alerts = array();
        }

        $alerts_to_send = array();
        $now = time();

        foreach ($alerts as $alert) {
            $type = $alert['type'];
            $cooldown = isset($this->settings['alert_cooldown'])
                ? (int) $this->settings['alert_cooldown']
                : 300; // 5 minutes default

            if (!isset($last_alerts[$type]) || ($now - $last_alerts[$type]) >= $cooldown) {
                $alerts_to_send[] = $alert;
                $last_alerts[$type] = $now;
            }
        }

        if (empty($alerts_to_send)) {
            return;
        }

        set_transient(self::LAST_ALERT_KEY, $last_alerts, 3600);

        // Send each alert
        foreach ($alerts_to_send as $alert) {
            $this->api->send_health_alert($alert);
        }
    }

    /**
     * Send periodic health report (even when everything is OK)
     */
    public function send_periodic_health_report() {
        if (!$this->is_enabled()) {
            return;
        }

        $health_data = $this->get_current_health_status();
        $this->api->send_health_report($health_data);
    }

    /**
     * Get current health status
     */
    public function get_current_health_status() {
        $window = $this->get_time_window();

        // CPU info
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : array(0, 0, 0);
        $cpu_cores = $this->get_cpu_cores();

        // Memory info
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();

        // Request info
        $request_count = (int) get_transient(self::REQUEST_COUNT_KEY . '_' . $window);
        $ips = get_transient(self::REQUEST_IPS_KEY . '_' . $window);
        $unique_ips = is_array($ips) ? count($ips) : 0;

        // Disk info
        $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : 0;
        $disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : 0;

        return array(
            'timestamp' => current_time('mysql'),
            'cpu' => array(
                'load_1min' => $load[0] ?? 0,
                'load_5min' => $load[1] ?? 0,
                'load_15min' => $load[2] ?? 0,
                'cores' => $cpu_cores,
                'load_percent' => $cpu_cores > 0 ? round(($load[0] / $cpu_cores) * 100, 1) : 0,
            ),
            'memory' => array(
                'usage' => $memory_usage,
                'usage_formatted' => $this->format_bytes($memory_usage),
                'peak' => $memory_peak,
                'peak_formatted' => $this->format_bytes($memory_peak),
                'limit' => $memory_limit,
                'limit_formatted' => $this->format_bytes($memory_limit),
                'usage_percent' => $memory_limit > 0 ? round(($memory_usage / $memory_limit) * 100, 1) : 0,
            ),
            'disk' => array(
                'free' => $disk_free,
                'free_formatted' => $this->format_bytes($disk_free),
                'total' => $disk_total,
                'total_formatted' => $this->format_bytes($disk_total),
                'used_percent' => $disk_total > 0 ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : 0,
            ),
            'traffic' => array(
                'requests_per_minute' => $request_count,
                'unique_ips' => $unique_ips,
            ),
            'wordpress' => array(
                'version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'active_plugins' => count(get_option('active_plugins', array())),
            ),
            'status' => $this->calculate_overall_status($load, $memory_usage, $memory_limit, $request_count),
        );
    }

    /**
     * Calculate overall health status
     */
    private function calculate_overall_status($load, $memory_usage, $memory_limit, $request_count) {
        $cpu_cores = $this->get_cpu_cores();
        $load_ratio = $cpu_cores > 0 ? $load[0] / $cpu_cores : $load[0];
        $memory_percent = $memory_limit > 0 ? ($memory_usage / $memory_limit) * 100 : 0;

        $rate_threshold = isset($this->settings['request_rate_threshold'])
            ? (int) $this->settings['request_rate_threshold']
            : 100;

        // Critical conditions
        if ($load_ratio >= 3 || $memory_percent >= 95 || $request_count >= ($rate_threshold * 3)) {
            return 'critical';
        }

        // Warning conditions
        if ($load_ratio >= 2 || $memory_percent >= 80 || $request_count >= $rate_threshold) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Get time window (minute-based)
     */
    private function get_time_window($offset = 0) {
        return floor(time() / 60) + $offset;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Get top IPs by request count
     */
    private function get_top_ips($ips, $limit = 5) {
        if (!is_array($ips) || empty($ips)) {
            return array();
        }

        arsort($ips);
        $top = array_slice($ips, 0, $limit, true);

        $result = array();
        foreach ($top as $ip => $count) {
            // Partially mask IP for privacy
            $masked_ip = $this->mask_ip($ip);
            $result[] = array(
                'ip' => $masked_ip,
                'requests' => $count,
            );
        }

        return $result;
    }

    /**
     * Mask IP address for privacy
     */
    private function mask_ip($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 10) . ':xxxx:xxxx';
        }
        return 'xxx.xxx.xxx.xxx';
    }

    /**
     * Get top URLs by request count
     */
    private function get_top_urls($urls, $limit = 10) {
        if (!is_array($urls) || empty($urls)) {
            return array();
        }

        arsort($urls);
        $top = array_slice($urls, 0, $limit, true);

        $result = array();
        foreach ($top as $url => $count) {
            $result[] = array(
                'url' => $url,
                'requests' => $count,
            );
        }

        return $result;
    }

    /**
     * Get number of CPU cores
     */
    private function get_cpu_cores() {
        static $cores = null;

        if ($cores !== null) {
            return $cores;
        }

        // Try /proc/cpuinfo (Linux)
        if (@is_file('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $cores = count($matches[0]);
                if ($cores > 0) {
                    return $cores;
                }
            }
        }

        // Try nproc command
        if (function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if ($nproc !== null && is_numeric(trim($nproc))) {
                $cores = (int) trim($nproc);
                return $cores;
            }
        }

        // Try sysctl (macOS)
        if (function_exists('shell_exec')) {
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($sysctl !== null && is_numeric(trim($sysctl))) {
                $cores = (int) trim($sysctl);
                return $cores;
            }
        }

        $cores = 1; // Default fallback
        return $cores;
    }

    /**
     * Get memory limit in bytes
     */
    private function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Format bytes to human readable
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * AJAX handler for test health report
     */
    public function ajax_test_health_report() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Refresh settings
        $this->settings = get_option('errorvault_settings', array());

        // Check if enabled
        if (empty($this->settings['health_monitoring_enabled'])) {
            wp_send_json_error('Health monitoring is not enabled. Please enable it and save settings first.');
        }

        if (empty($this->settings['api_endpoint']) || empty($this->settings['api_token'])) {
            wp_send_json_error('API endpoint and token are required.');
        }

        // Get health data
        $health_data = $this->get_current_health_status();

        // Send health report (with blocking=true for testing to get actual response)
        $result = $this->api->send_health_report($health_data, true);

        if ($result) {
            wp_send_json_success(array(
                'message' => 'Health report sent successfully!',
                'data' => $health_data,
            ));
        } else {
            wp_send_json_error('Failed to send health report. Check your API endpoint and token. Make sure the portal has the health tables migrated.');
        }
    }

    /**
     * Cleanup on deactivation
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('errorvault_health_check_cron');
    }
}
