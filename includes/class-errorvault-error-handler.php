<?php
/**
 * Error Handler class
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_Error_Handler {

    /**
     * API instance
     */
    private $api;

    /**
     * Settings
     */
    private $settings;

    /**
     * Error buffer for batch sending
     */
    private $error_buffer = array();

    /**
     * Previous error handler
     */
    private $previous_error_handler;

    /**
     * Previous exception handler
     */
    private $previous_exception_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('errorvault_settings', array());
        $this->api = new ErrorVault_API();
    }

    /**
     * Register error handlers
     */
    public function register() {
        // Set custom error handler
        $this->previous_error_handler = set_error_handler(array($this, 'handle_error'));

        // Set custom exception handler
        $this->previous_exception_handler = set_exception_handler(array($this, 'handle_exception'));

        // Register shutdown function for fatal errors
        register_shutdown_function(array($this, 'handle_shutdown'));

        // Send buffered errors on shutdown
        add_action('shutdown', array($this, 'flush_buffer'));
    }

    /**
     * Handle PHP errors
     */
    public function handle_error($errno, $errstr, $errfile, $errline) {
        // Check if error reporting is turned off
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $severity = $this->get_severity($errno);

        // Check if we should log this severity level
        if (!$this->should_log($severity)) {
            // Call previous error handler
            if ($this->previous_error_handler) {
                return call_user_func($this->previous_error_handler, $errno, $errstr, $errfile, $errline);
            }
            return false;
        }

        // Check if message matches exclude patterns
        if ($this->is_excluded($errstr)) {
            return false;
        }

        $error_data = array(
            'message' => $errstr,
            'severity' => $severity,
            'file' => $errfile,
            'line' => $errline,
            'stack_trace' => $this->get_stack_trace(),
            'context' => $this->get_context(),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'url' => $this->get_current_url(),
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
        );

        $this->log_error($error_data);

        // Call previous error handler if exists
        if ($this->previous_error_handler) {
            return call_user_func($this->previous_error_handler, $errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    /**
     * Handle uncaught exceptions
     */
    public function handle_exception($exception) {
        $error_data = array(
            'message' => $exception->getMessage(),
            'severity' => 'critical',
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString(),
            'context' => $this->get_context(),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'url' => $this->get_current_url(),
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
        );

        $this->log_error($error_data);

        // Call previous exception handler
        if ($this->previous_exception_handler) {
            call_user_func($this->previous_exception_handler, $exception);
        }
    }

    /**
     * Handle shutdown (catch fatal errors)
     */
    public function handle_shutdown() {
        $error = error_get_last();

        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            $error_data = array(
                'message' => $error['message'],
                'severity' => 'fatal',
                'file' => $error['file'],
                'line' => $error['line'],
                'stack_trace' => null,
                'context' => $this->get_context(),
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'url' => $this->get_current_url(),
                'request_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            );

            // Send immediately for fatal errors
            $this->api->send_error($error_data);
        }
    }

    /**
     * Log error (add to buffer or send immediately)
     */
    private function log_error($error_data) {
        $send_immediately = !empty($this->settings['send_immediately']);

        if ($send_immediately) {
            $this->api->send_error($error_data);
        } else {
            $this->error_buffer[] = $error_data;

            // Check if buffer is full
            $batch_size = isset($this->settings['batch_size']) ? (int)$this->settings['batch_size'] : 10;
            if (count($this->error_buffer) >= $batch_size) {
                $this->flush_buffer();
            }
        }
    }

    /**
     * Flush error buffer
     */
    public function flush_buffer() {
        if (!empty($this->error_buffer)) {
            $this->api->send_batch($this->error_buffer);
            $this->error_buffer = array();
        }
    }

    /**
     * Get severity level from error number
     */
    private function get_severity($errno) {
        switch ($errno) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                return 'fatal';

            case E_RECOVERABLE_ERROR:
                return 'critical';

            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return 'warning';

            case E_NOTICE:
            case E_USER_NOTICE:
                return 'notice';

            case E_STRICT:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'notice';

            default:
                return 'error';
        }
    }

    /**
     * Check if we should log this severity level
     */
    private function should_log($severity) {
        $include_notices = !empty($this->settings['include_notices']);
        $include_warnings = !empty($this->settings['include_warnings']);

        switch ($severity) {
            case 'notice':
                return $include_notices;
            case 'warning':
                return $include_warnings;
            default:
                return true;
        }
    }

    /**
     * Check if error message matches exclude patterns
     */
    private function is_excluded($message) {
        $patterns = isset($this->settings['exclude_patterns']) ? $this->settings['exclude_patterns'] : array();

        foreach ($patterns as $pattern) {
            if (!empty($pattern) && stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get stack trace
     */
    private function get_stack_trace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Remove error handler frames
        $trace = array_slice($trace, 3);

        $output = array();
        foreach ($trace as $i => $frame) {
            $file = isset($frame['file']) ? $frame['file'] : '[internal]';
            $line = isset($frame['line']) ? $frame['line'] : 0;
            $class = isset($frame['class']) ? $frame['class'] . $frame['type'] : '';
            $function = isset($frame['function']) ? $frame['function'] : '';

            $output[] = "#{$i} {$file}({$line}): {$class}{$function}()";
        }

        return implode("\n", $output);
    }

    /**
     * Get additional context
     */
    private function get_context() {
        $context = array();

        // Current user
        if (function_exists('get_current_user_id') && get_current_user_id()) {
            $user = wp_get_current_user();
            $context['user'] = array(
                'id' => $user->ID,
                'login' => $user->user_login,
                'email' => $user->user_email,
            );
        }

        // Current page/post
        if (function_exists('get_queried_object') && $obj = get_queried_object()) {
            if (isset($obj->ID)) {
                $context['post_id'] = $obj->ID;
            }
            if (isset($obj->post_type)) {
                $context['post_type'] = $obj->post_type;
            }
        }

        // Memory usage and limits
        $context['memory'] = $this->get_memory_info();

        // CPU/Server load
        $context['server'] = $this->get_server_info();

        // Request data (sanitized)
        if (!empty($_GET)) {
            $context['get_params'] = array_keys($_GET);
        }
        if (!empty($_POST)) {
            $context['post_params'] = array_keys($_POST);
        }

        // Active plugins (useful for debugging)
        if (function_exists('get_option')) {
            $active_plugins = get_option('active_plugins', array());
            $context['active_plugins_count'] = count($active_plugins);
        }

        // Current theme
        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            $context['theme'] = array(
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
            );
        }

        return $context;
    }

    /**
     * Get memory usage information
     */
    private function get_memory_info() {
        $memory = array(
            'usage' => memory_get_usage(true),
            'usage_formatted' => $this->format_bytes(memory_get_usage(true)),
            'peak' => memory_get_peak_usage(true),
            'peak_formatted' => $this->format_bytes(memory_get_peak_usage(true)),
        );

        // Get PHP memory limit
        $memory_limit = ini_get('memory_limit');
        $memory['limit'] = $memory_limit;
        $memory['limit_bytes'] = $this->convert_to_bytes($memory_limit);

        // Calculate percentage used
        if ($memory['limit_bytes'] > 0) {
            $memory['usage_percent'] = round(($memory['usage'] / $memory['limit_bytes']) * 100, 2);
            $memory['peak_percent'] = round(($memory['peak'] / $memory['limit_bytes']) * 100, 2);
        }

        // WordPress specific memory limit
        if (defined('WP_MEMORY_LIMIT')) {
            $memory['wp_limit'] = WP_MEMORY_LIMIT;
            $memory['wp_limit_bytes'] = $this->convert_to_bytes(WP_MEMORY_LIMIT);
        }

        if (defined('WP_MAX_MEMORY_LIMIT')) {
            $memory['wp_max_limit'] = WP_MAX_MEMORY_LIMIT;
        }

        return $memory;
    }

    /**
     * Get server/CPU information
     */
    private function get_server_info() {
        $server = array();

        // Server load average (Linux/Unix only)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                $server['load_average'] = array(
                    '1min' => round($load[0], 2),
                    '5min' => round($load[1], 2),
                    '15min' => round($load[2], 2),
                );
            }
        }

        // CPU count (to contextualize load average)
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $server['cpu_cores'] = substr_count($cpuinfo, 'processor');
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $server['cpu_cores'] = (int) getenv('NUMBER_OF_PROCESSORS');
        }

        // PHP max execution time
        $server['max_execution_time'] = ini_get('max_execution_time');

        // Current execution time (approximate)
        if (defined('WP_START_TIMESTAMP')) {
            $server['execution_time'] = round(microtime(true) - WP_START_TIMESTAMP, 4);
        } elseif (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $server['execution_time'] = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4);
        }

        // PHP post max size and upload max
        $server['post_max_size'] = ini_get('post_max_size');
        $server['upload_max_filesize'] = ini_get('upload_max_filesize');

        // Server software
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $server['software'] = $_SERVER['SERVER_SOFTWARE'];
        }

        // PHP SAPI
        $server['php_sapi'] = PHP_SAPI;

        // Disk space (if allowed)
        if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
            $path = defined('ABSPATH') ? ABSPATH : '/';
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            if ($free !== false && $total !== false) {
                $server['disk'] = array(
                    'free' => $this->format_bytes($free),
                    'total' => $this->format_bytes($total),
                    'used_percent' => round((($total - $free) / $total) * 100, 2),
                );
            }
        }

        // Database queries (if Query Monitor style tracking is available)
        global $wpdb;
        if (isset($wpdb->num_queries)) {
            $server['db_queries'] = $wpdb->num_queries;
        }

        return $server;
    }

    /**
     * Convert memory string to bytes
     */
    private function convert_to_bytes($value) {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;

        switch ($unit) {
            case 'g':
                $bytes *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $bytes *= 1024 * 1024;
                break;
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
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
     * Get current URL
     */
    private function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        return $protocol . $host . $uri;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }
}
