<?php
/**
 * Backup Helper Functions for Error-Vault
 * Utility functions for backup operations and diagnostics
 */

if (!defined('ABSPATH')) {
    exit;
}

class EV_Backup_Helpers {

    const PRIVATE_DIR_OPTION = 'errorvault_private_dir';

    /** Folder name prefixes Error-Vault creates for itself inside wp-content. */
    const OWN_FOLDER_PREFIXES = array('errorvault-quarantine', 'errorvault-private-', 'errorvault-restore-');

    /**
     * Private folder for backup temp files and the backup log. Outside the web
     * root when possible, otherwise in wp-content under a random name with deny
     * rules; never under uploads, where database dumps were downloadable.
     *
     * @return string|null
     */
    public static function private_dir($create = true) {
        $dir = get_option(self::PRIVATE_DIR_OPTION);
        if (is_string($dir) && '' !== $dir && @is_dir($dir) && @wp_is_writable($dir)) {
            return $dir;
        }
        if (!$create) {
            return null;
        }

        $name = 'errorvault-private-' . strtolower(wp_generate_password(20, false));
        foreach (array(dirname(untrailingslashit(ABSPATH)), WP_CONTENT_DIR) as $base) {
            if (@is_dir($base) && @wp_is_writable($base) && wp_mkdir_p($base . '/' . $name)) {
                $dir = wp_normalize_path($base . '/' . $name);
                self::write_deny_files($dir);
                update_option(self::PRIVATE_DIR_OPTION, $dir, false);
                return $dir;
            }
        }

        return null;
    }

    /**
     * Refuse web access where the server honours it (Apache) and stop directory
     * listings. Folders using this also get random names, for servers that don't.
     */
    public static function write_deny_files($dir) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    }

    /**
     * Is this a folder Error-Vault manages itself (quarantine, private files,
     * restore undo points)? Backups and restores never touch these.
     */
    public static function is_own_folder($name) {
        foreach (self::OWN_FOLDER_PREFIXES as $own) {
            if (0 === strpos((string) $name, $own)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Older versions kept dumps, archives and the log in the public
     * uploads/errorvault-backups folder. Keep the log's recent history and
     * delete everything else.
     */
    public static function purge_legacy_public_files() {
        $legacy = wp_upload_dir(null, false)['basedir'] . '/errorvault-backups';
        if (!is_dir($legacy)) {
            return;
        }

        $old_log = $legacy . '/backup.log';
        $log = self::get_log_file_path();
        if ($log && is_file($old_log)) {
            // Keep recent history for the log viewer: the tail only, as this log was never rotated.
            $size = (int) @filesize($old_log);
            $tail = (string) @file_get_contents($old_log, false, null, max(0, $size - 262144));
            $current = file_exists($log) ? (string) @file_get_contents($log) : '';
            @file_put_contents($log, $tail . $current);
        }

        foreach (array_merge((array) glob($legacy . '/tmp/*'), (array) glob($legacy . '/*')) as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($legacy . '/tmp');
        @rmdir($legacy);
    }

    /**
     * Get backup log file path
     *
     * @return string|null
     */
    public static function get_log_file_path($create = true) {
        $dir = self::private_dir($create);
        return $dir ? $dir . '/backup.log' : null;
    }

    /**
     * Get backup temporary directory
     *
     * @return string|null
     */
    public static function get_temp_dir($create = true) {
        $dir = self::private_dir($create);
        return $dir ? $dir . '/tmp' : null;
    }

    /**
     * Get recent backup log entries
     */
    public static function get_recent_log_entries($lines = 50) {
        $log_file = self::get_log_file_path(false);

        if (!$log_file || !file_exists($log_file)) {
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
        $log_file = self::get_log_file_path(false);

        if ($log_file && file_exists($log_file)) {
            @unlink($log_file);
        }
    }

    /**
     * Clean up temporary files, optionally only those older than $min_age seconds
     * (leftovers of runs that were killed before they could clean up).
     */
    public static function cleanup_temp_files($min_age = 0) {
        $tmp_dir = self::get_temp_dir(false);

        if (!$tmp_dir || !is_dir($tmp_dir)) {
            return 0;
        }

        $files = glob($tmp_dir . '/*');
        $cleaned = 0;

        foreach ((array) $files as $file) {
            if (is_file($file) && 'index.php' !== basename($file) && time() - (int) @filemtime($file) >= $min_age) {
                @unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Prefixes of other WordPress installs that share this database and whose
     * table prefix starts with this site's, e.g. "wp_shop_" next to "wp_": any
     * "{prefix}{x}options" table that has a matching "{prefix}{x}posts" table.
     * On multisite, numeric "{prefix}{blog_id}_" subsite prefixes are this site's.
     *
     * @param string[] $tables table names starting with $prefix
     * @return string[]
     */
    public static function foreign_prefixes(array $tables, $prefix, $allow_subsites = false) {
        $names = array();
        foreach ($tables as $table) {
            if (is_string($table) && '' !== $table) {
                $names[$table] = true;
            }
        }

        $foreign = array();
        $pattern = '/^' . preg_quote($prefix, '/') . '(.+)options$/';
        foreach (array_keys($names) as $table) {
            if (!preg_match($pattern, (string) $table, $m) || !isset($names[$prefix . $m[1] . 'posts'])) {
                continue;
            }
            if ($allow_subsites && preg_match('/^\d+_$/', $m[1])) {
                continue;
            }
            $foreign[$prefix . $m[1]] = true;
        }

        return array_keys($foreign);
    }

    /**
     * Does this table belong to this site (not to another install sharing the database)?
     */
    public static function is_site_table($table, $prefix, array $foreign_prefixes) {
        if (0 !== strpos((string) $table, $prefix)) {
            return false;
        }
        foreach ($foreign_prefixes as $other) {
            if (0 === strpos((string) $table, $other)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get backup status information
     */
    public static function get_backup_status() {
        $temp_dir = self::get_temp_dir(false);
        $log_file = self::get_log_file_path(false);

        $status = array(
            'cron_scheduled' => wp_next_scheduled(EV_Cron::BACKUP_POLL_HOOK) !== false,
            'next_poll_time' => EV_Cron::get_next_poll_time(),
            'backup_in_progress' => get_transient('ev_backup_lock') !== false,
            'temp_dir_exists' => $temp_dir && is_dir($temp_dir),
            'log_file_exists' => $log_file && file_exists($log_file),
        );

        if ($status['next_poll_time']) {
            $status['next_poll_human'] = human_time_diff($status['next_poll_time'], current_time('timestamp'));
        }

        if ($status['log_file_exists']) {
            $status['log_file_size'] = filesize($log_file);
        }

        return $status;
    }

    /**
     * Check if system meets backup requirements
     */
    public static function check_requirements() {
        $requirements = array(
            'zip_available' => class_exists('ZipArchive'),
            'storage_writable' => null !== self::private_dir(false)
                || @wp_is_writable(dirname(untrailingslashit(ABSPATH)))
                || @wp_is_writable(WP_CONTENT_DIR),
            'api_configured' => false,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        );

        $settings = get_option('errorvault_settings', array());
        if (!empty($settings['api_token'])) {
            $requirements['api_configured'] = true;
        }

        $requirements['all_met'] = $requirements['zip_available'] &&
                                    $requirements['storage_writable'] &&
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
