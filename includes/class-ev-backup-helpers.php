<?php
/**
 * Backup Helper Functions for ErrorVault
 * Utility functions for backup operations and diagnostics
 */

if (!defined('ABSPATH')) {
    exit;
}

class EV_Backup_Helpers {

    /**
     * Get backup log file path
     */
    public static function get_log_file_path() {
        return wp_upload_dir()['basedir'] . '/errorvault-backups/backup.log';
    }

    /**
     * Get backup temporary directory
     */
    public static function get_temp_dir() {
        return wp_upload_dir()['basedir'] . '/errorvault-backups/tmp';
    }

    /**
     * Get recent backup log entries
     */
    public static function get_recent_log_entries($lines = 50) {
        $log_file = self::get_log_file_path();
        
        if (!file_exists($log_file)) {
            return array();
        }

        $content = file_get_contents($log_file);
        $all_lines = explode("\n", $content);
        $recent_lines = array_slice($all_lines, -$lines);
        
        return array_filter($recent_lines);
    }

    /**
     * Clear backup log
     */
    public static function clear_log() {
        $log_file = self::get_log_file_path();
        
        if (file_exists($log_file)) {
            @unlink($log_file);
        }
    }

    /**
     * Clean up temporary files
     */
    public static function cleanup_temp_files() {
        $tmp_dir = self::get_temp_dir();
        
        if (!is_dir($tmp_dir)) {
            return;
        }

        $files = glob($tmp_dir . '/*');
        $cleaned = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get backup status information
     */
    public static function get_backup_status() {
        $status = array(
            'cron_scheduled' => wp_next_scheduled(EV_Cron::BACKUP_POLL_HOOK) !== false,
            'next_poll_time' => EV_Cron::get_next_poll_time(),
            'backup_in_progress' => get_transient('ev_backup_lock') !== false,
            'temp_dir_exists' => is_dir(self::get_temp_dir()),
            'log_file_exists' => file_exists(self::get_log_file_path()),
        );

        if ($status['next_poll_time']) {
            $status['next_poll_human'] = human_time_diff($status['next_poll_time'], current_time('timestamp'));
        }

        if ($status['log_file_exists']) {
            $status['log_file_size'] = filesize(self::get_log_file_path());
        }

        return $status;
    }

    /**
     * Check if system meets backup requirements
     */
    public static function check_requirements() {
        $requirements = array(
            'zip_available' => class_exists('ZipArchive'),
            'uploads_writable' => wp_is_writable(wp_upload_dir()['basedir']),
            'api_configured' => false,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        );

        $settings = get_option('errorvault_settings', array());
        if (!empty($settings['api_endpoint']) && !empty($settings['api_token'])) {
            $requirements['api_configured'] = true;
        }

        $requirements['all_met'] = $requirements['zip_available'] && 
                                    $requirements['uploads_writable'] && 
                                    $requirements['api_configured'];

        return $requirements;
    }

    /**
     * Format file size for display
     */
    public static function format_file_size($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Estimate backup size
     */
    public static function estimate_backup_size($include_uploads = false) {
        global $wpdb;
        
        $db_size = 0;
        $tables = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        
        foreach ($tables as $table) {
            $db_size += $table['Data_length'] + $table['Index_length'];
        }

        $total_size = $db_size;

        if ($include_uploads) {
            $uploads_dir = WP_CONTENT_DIR . '/uploads';
            if (is_dir($uploads_dir)) {
                $uploads_size = self::get_directory_size($uploads_dir);
                $total_size += $uploads_size;
            }
        }

        return array(
            'database_size' => $db_size,
            'database_size_formatted' => self::format_file_size($db_size),
            'uploads_size' => isset($uploads_size) ? $uploads_size : 0,
            'uploads_size_formatted' => isset($uploads_size) ? self::format_file_size($uploads_size) : '0 B',
            'total_size' => $total_size,
            'total_size_formatted' => self::format_file_size($total_size),
            'within_limit' => $total_size < (512000 * 1024),
        );
    }

    /**
     * Get directory size recursively
     */
    private static function get_directory_size($directory) {
        $size = 0;
        
        if (!is_dir($directory)) {
            return 0;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Trigger manual backup poll (for testing/debugging)
     */
    public static function trigger_manual_poll() {
        if (!current_user_can('manage_options')) {
            return array(
                'success' => false,
                'error' => 'Insufficient permissions',
            );
        }

        EV_Cron::trigger_poll_now();

        return array(
            'success' => true,
            'message' => 'Backup poll triggered manually',
        );
    }
}
