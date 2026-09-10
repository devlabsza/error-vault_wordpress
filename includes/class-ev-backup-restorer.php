<?php
/**
 * One-click restore of Error-Vault backups, and undo.
 *
 * Designed so a failure part-way can't leave a half-restored site:
 *  1. Download the archive and verify its SHA-256, unzip it (unsafe paths refused).
 *  2. Import the database into temporary "evr_" tables. The live site is untouched.
 *  3. Maintenance mode on; swap wp-content folders (renames) and swap all tables
 *     in one atomic RENAME TABLE; the previous tables become "evo_" tables.
 *  4. The previous files/tables are kept for 7 days, so the restore can be undone.
 *
 * Always kept from the live site: Error-Vault itself and its settings, the site
 * URL, wp-config.php and WordPress core (update core afterwards if needed).
 *
 * Only archives this site recorded (backup id + SHA-256) when it made them are
 * restored remotely. Anything else needs an administrator's approval in wp-admin,
 * so a compromised portal can't push code onto the site.
 */

if (!defined('ABSPATH')) {
    exit;
}

class EV_Restore_Needs_Approval extends Exception {}

class EV_Backup_Restorer {

    const HISTORY_OPTION = 'errorvault_backup_history';
    const RESTORE_OPTION = 'errorvault_last_restore';
    const APPROVAL_OPTION = 'errorvault_pending_restore';
    const TMP_PREFIX = 'evr_';
    const OLD_PREFIX = 'evo_';
    const KEEP_DAYS = 7;

    /** wp-content entries a restore never replaces. */
    const PROTECTED_CONTENT = array('upgrade', 'upgrade-temp-backup', 'cache', 'wflogs', 'ai1wm-backups', 'updraft', 'backups-dup-lite');

    /** Options carried over from the live site into the restored database. */
    const PRESERVED_OPTIONS = array(
        'siteurl', 'home', 'errorvault_settings', 'errorvault_backup_history', 'errorvault_executed_actions',
        'errorvault_quarantine', 'errorvault_quarantine_dir', 'errorvault_security_last_scan', 'errorvault_last_restore',
    );

    /* ------------------------------------------------------------------
     * Backup history (what this site itself produced)
     * ------------------------------------------------------------------ */

    public static function record_backup($backup_id, $sha256) {
        $history = get_option(self::HISTORY_OPTION, array());
        $history[(int) $backup_id] = array('sha256' => strtolower((string) $sha256), 'time' => time());
        if (count($history) > 200) {
            ksort($history);
            $history = array_slice($history, -200, null, true);
        }
        update_option(self::HISTORY_OPTION, $history, false);
    }

    private static function recorded_checksum($backup_id) {
        $history = get_option(self::HISTORY_OPTION, array());
        return isset($history[(int) $backup_id]['sha256']) ? $history[(int) $backup_id]['sha256'] : null;
    }

    /* ------------------------------------------------------------------
     * Restore
     * ------------------------------------------------------------------ */

    public function restore($backup_id, $action_id = 0, $approved = false) {
        global $wpdb;

        if (is_multisite()) {
            throw new Exception('Restoring multisite networks automatically is not supported. Download the backup and restore it manually.');
        }
        if (!class_exists('ZipArchive')) {
            throw new Exception('The ZipArchive PHP extension is required to restore backups.');
        }
        if (!($wpdb->dbh instanceof mysqli)) {
            throw new Exception('Unsupported database driver for automatic restore.');
        }

        $source = $this->fetch_source($backup_id);
        $recorded = self::recorded_checksum($backup_id);

        if (!$recorded && !$approved) {
            update_option(self::APPROVAL_OPTION, array(
                'backup_id' => (int) $backup_id,
                'action_id' => (int) $action_id,
                'backup_created_at' => isset($source['created_at']) ? $source['created_at'] : null,
                'scope' => isset($source['scope']) ? $source['scope'] : 'database',
                'size' => isset($source['size']) ? (int) $source['size'] : 0,
                'requested_at' => time(),
            ), false);
            throw new EV_Restore_Needs_Approval('This backup was not made by this installation of Error-Vault 1.8+, so an administrator must approve the restore in WordPress (a notice is shown in wp-admin).');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);
        set_transient('ev_backup_lock', 1, 3 * HOUR_IN_SECONDS);

        $restore_id = gmdate('YmdHis');
        $work = wp_normalize_path(WP_CONTENT_DIR . '/upgrade/errorvault-restore-' . $restore_id);
        if (!wp_mkdir_p($work)) {
            throw new Exception('Could not create a working folder in wp-content/upgrade.');
        }
        @file_put_contents($work . '/index.php', "<?php\n// Silence is golden.\n");
        @file_put_contents($work . '/.htaccess', "Require all denied\nDeny from all\n");

        $size = isset($source['size']) ? (int) $source['size'] : 0;
        $free = @disk_free_space(WP_CONTENT_DIR);
        if ($size && false !== $free && $free < $size * 4 + 100 * MB_IN_BYTES) {
            $this->rrmdir($work);
            throw new Exception(sprintf('Not enough disk space to restore safely (need about %s free, have %s).', size_format($size * 4 + 100 * MB_IN_BYTES), size_format($free)));
        }

        try {
            // 1. Download + verify.
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $tmp = download_url($source['url'], 3600);
            if (is_wp_error($tmp)) {
                throw new Exception('Download failed: ' . $tmp->get_error_message());
            }
            $zip_path = $work . '/archive.zip';
            if (!@rename($tmp, $zip_path)) {
                @copy($tmp, $zip_path);
                @unlink($tmp);
            }
            $sha = hash_file('sha256', $zip_path);
            $expected = $recorded ? $recorded : (isset($source['checksum']) ? strtolower((string) $source['checksum']) : '');
            if (!$expected || !hash_equals($expected, $sha)) {
                throw new Exception('The downloaded archive does not match the checksum recorded when the backup was made, so it was not restored.');
            }

            // 2. Unpack.
            $extract = $work . '/files';
            $this->safe_extract($zip_path, $extract);
            @unlink($zip_path);

            $sql = $extract . '/database.sql';
            if (!is_file($sql)) {
                throw new Exception('The backup does not contain database.sql.');
            }
            $has_content = is_dir($extract . '/wp-content');
            $legacy_uploads = !$has_content && is_dir($extract . '/uploads');

            // 3. Database into temporary tables (live site untouched).
            $tables = $this->import_to_temp_tables($sql);
            if (!in_array($wpdb->prefix . 'options', $tables, true) || !in_array($wpdb->prefix . 'users', $tables, true)) {
                throw new Exception(sprintf('The backup has no WordPress tables with this site\'s prefix (%s), so nothing was restored.', $wpdb->prefix));
            }
        } catch (Exception $e) {
            $this->drop_tables_like(self::TMP_PREFIX . $wpdb->prefix);
            $this->rrmdir($work);
            delete_transient('ev_backup_lock');
            throw $e;
        }

        // 4. Switch over.
        $preserved = $this->read_preserved_options();
        $this->discard_undo_point();
        $this->maintenance(true);

        $moved = array();
        $swap = null;
        try {
            $rollback = $work . '/rollback';
            wp_mkdir_p($rollback);

            if ($has_content) {
                $moved = $this->swap_content($extract . '/wp-content', $rollback);
            } elseif ($legacy_uploads) {
                $moved[] = $this->swap_one($extract . '/uploads', WP_CONTENT_DIR . '/uploads', $rollback . '/uploads');
            }
            if (is_file($extract . '/root/.htaccess')) {
                $moved[] = $this->swap_one($extract . '/root/.htaccess', ABSPATH . '.htaccess', $rollback . '/.htaccess');
            }

            $swap = $this->swap_tables($tables);
            $this->write_preserved_options($preserved);
        } catch (Exception $e) {
            $this->revert_files($moved, $work);
            $this->drop_tables_like(self::TMP_PREFIX . $wpdb->prefix);
            $this->maintenance(false);
            $this->rrmdir($work);
            delete_transient('ev_backup_lock');
            throw new Exception('Restore failed and was rolled back: ' . $e->getMessage());
        }

        $this->maintenance(false);
        wp_cache_flush();

        $record = array(
            'restore_id' => $restore_id,
            'backup_id' => (int) $backup_id,
            'time' => time(),
            'work' => $work,
            'moved' => $moved,
            'tables' => $swap['tables'],
            'had_old' => $swap['had_old'],
            'prefix' => $wpdb->prefix,
        );
        update_option(self::RESTORE_OPTION, $record, false);

        // Leftovers of the unpacked archive (database.sql, manifest, root/wp-config.php...).
        $this->rrmdir($extract);
        delete_transient('ev_backup_lock');

        return array(
            'message' => sprintf(
                'Restored backup #%d: %d database tables%s. The previous state is kept for %d days and can be undone. Anyone logged in may need to log in again.',
                $backup_id,
                count($swap['tables']),
                $has_content ? ' and wp-content (themes, plugins' . (is_dir(WP_CONTENT_DIR . '/uploads') ? ', uploads' : '') . ')' : ($legacy_uploads ? ' and uploads' : ''),
                self::KEEP_DAYS
            ),
            'restore_id' => $restore_id,
            'tables' => count($swap['tables']),
            'files_restored' => count($moved),
        );
    }

    /* ------------------------------------------------------------------
     * Undo
     * ------------------------------------------------------------------ */

    public function undo($restore_id) {
        global $wpdb;

        $record = get_option(self::RESTORE_OPTION);
        if (!is_array($record) || (string) $record['restore_id'] !== (string) $restore_id) {
            throw new Exception('That restore can no longer be undone (only the most recent restore can).');
        }
        if (time() - (int) $record['time'] > self::KEEP_DAYS * DAY_IN_SECONDS) {
            throw new Exception('The undo point for that restore has expired.');
        }

        $preserved = $this->read_preserved_options();
        $this->maintenance(true);

        try {
            $pairs = array();
            foreach ($record['tables'] as $table) {
                $pairs[] = '`' . $table . '` TO `' . self::TMP_PREFIX . $table . '`';
                if (in_array($table, $record['had_old'], true)) {
                    $pairs[] = '`' . self::OLD_PREFIX . $table . '` TO `' . $table . '`';
                }
            }
            if (false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
                throw new Exception('Could not switch the database back: ' . $wpdb->last_error);
            }
            $this->drop_tables_like(self::TMP_PREFIX . $record['prefix']);
            $this->revert_files($record['moved'], $record['work']);

            unset($preserved['errorvault_last_restore']);
            $this->write_preserved_options($preserved);
            $wpdb->delete($wpdb->options, array('option_name' => self::RESTORE_OPTION));
        } catch (Exception $e) {
            $this->maintenance(false);
            throw $e;
        }

        $this->maintenance(false);
        wp_cache_flush();
        $this->rrmdir($record['work']);

        return array('message' => 'Undid the restore: the database and files are back to how they were before it.', 'restore_id' => $restore_id);
    }

    /**
     * Drop the undo point once it's older than KEEP_DAYS (called on each poll).
     */
    public static function cleanup_expired() {
        $record = get_option(self::RESTORE_OPTION);
        if (is_array($record) && time() - (int) $record['time'] > self::KEEP_DAYS * DAY_IN_SECONDS) {
            (new self())->discard_undo_point();
        }
    }

    /* ------------------------------------------------------------------
     * Admin approval for archives this install didn't record
     * ------------------------------------------------------------------ */

    public static function init_admin() {
        add_action('admin_notices', array(__CLASS__, 'approval_notice'));
        add_action('admin_post_errorvault_restore_decision', array(__CLASS__, 'handle_decision'));
        add_action('errorvault_run_approved_restore', array(__CLASS__, 'run_approved'), 10, 2);
    }

    public static function approval_notice() {
        $pending = get_option(self::APPROVAL_OPTION);
        if (!is_array($pending) || !current_user_can('manage_options')) {
            return;
        }
        $url = function ($decision) {
            return wp_nonce_url(admin_url('admin-post.php?action=errorvault_restore_decision&decision=' . $decision), 'errorvault_restore_decision');
        };
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Error-Vault: restore waiting for your approval.', 'errorvault') . '</strong> ';
        printf(
            esc_html__('A restore of backup #%1$d (%2$s, made %3$s) was requested from the Error-Vault dashboard. Restoring replaces the database and site files with the backup\'s. The current state is kept for 7 days and can be undone.', 'errorvault'),
            (int) $pending['backup_id'],
            esc_html('full' === $pending['scope'] ? __('database + files', 'errorvault') : __('database', 'errorvault')),
            esc_html($pending['backup_created_at']
                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($pending['backup_created_at']))
                : __('unknown date', 'errorvault'))
        );
        echo '</p><p><a class="button button-primary" href="' . esc_url($url('approve')) . '">' . esc_html__('Approve restore', 'errorvault') . '</a> ';
        echo '<a class="button" href="' . esc_url($url('reject')) . '">' . esc_html__('Reject', 'errorvault') . '</a></p></div>';
    }

    public static function handle_decision() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'errorvault'));
        }
        check_admin_referer('errorvault_restore_decision');

        $pending = get_option(self::APPROVAL_OPTION);
        delete_option(self::APPROVAL_OPTION);

        if (is_array($pending)) {
            if (isset($_GET['decision']) && 'approve' === $_GET['decision']) {
                wp_schedule_single_event(time(), 'errorvault_run_approved_restore', array((int) $pending['backup_id'], (int) $pending['action_id']));
                spawn_cron();
            } elseif (!empty($pending['action_id'])) {
                ErrorVault_Security_Actions::report_result((int) $pending['action_id'], 'failed', 'Rejected by an administrator in WordPress.', array());
            }
        }

        wp_safe_redirect(admin_url('options-general.php?page=errorvault'));
        exit;
    }

    public static function run_approved($backup_id, $action_id) {
        try {
            $result = (new self())->restore($backup_id, $action_id, true);
            $message = $result['message'];
            unset($result['message']);
            ErrorVault_Security_Actions::report_result($action_id, 'completed', $message, $result);
        } catch (Throwable $e) {
            ErrorVault_Security_Actions::report_result($action_id, 'failed', $e->getMessage(), array());
        }
    }

    /* ------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function fetch_source($backup_id) {
        $response = wp_remote_get(ErrorVault_Security_Scanner::api_base() . '/backups/' . (int) $backup_id . '/source', array(
            'timeout' => 30,
            'headers' => ErrorVault_Security_Scanner::headers(),
        ));
        if (is_wp_error($response)) {
            throw new Exception('Could not reach Error-Vault: ' . $response->get_error_message());
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== (int) wp_remote_retrieve_response_code($response) || empty($body['data']['url'])) {
            throw new Exception(isset($body['error']) ? $body['error'] : 'Error-Vault did not return a download for that backup.');
        }
        return $body['data'];
    }

    private function safe_extract($zip_path, $dest) {
        $zip = new ZipArchive();
        if (true !== $zip->open($zip_path)) {
            throw new Exception('The backup archive could not be opened.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ('' === $name || '/' === $name[0] || false !== strpos($name, '..') || false !== strpos($name, "\0") || preg_match('#^[A-Za-z]:#', $name)) {
                $zip->close();
                throw new Exception('The backup archive contains an unsafe path and was not restored.');
            }
        }
        if (!wp_mkdir_p($dest) || !$zip->extractTo($dest)) {
            $zip->close();
            throw new Exception('The backup archive could not be extracted (disk space or permissions).');
        }
        $zip->close();
    }

    /**
     * Stream database.sql into evr_-prefixed copies of this site's tables.
     *
     * @return string[] live table names that were imported
     */
    private function import_to_temp_tables($sql_path) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $dbh = $wpdb->dbh;

        $this->drop_tables_like(self::TMP_PREFIX . $prefix);
        $placeholder = $this->legacy_percent_placeholder($sql_path);

        mysqli_query($dbh, 'SET FOREIGN_KEY_CHECKS = 0');

        $handle = fopen($sql_path, 'r');
        if (!$handle) {
            throw new Exception('Could not read database.sql.');
        }

        $tables = array();
        $statement = '';
        $head = '/^(\s*(?:DROP TABLE IF EXISTS|DROP TABLE|CREATE TABLE(?: IF NOT EXISTS)?|INSERT(?: IGNORE)? INTO|REPLACE INTO|\/\*!\d+\s+ALTER TABLE|ALTER TABLE)\s+)`([^`]+)`/i';

        try {
            while (false !== ($line = fgets($handle))) {
                if ('' === $statement) {
                    $trimmed = ltrim($line);
                    if ('' === $trimmed || 0 === strpos($trimmed, '--') || 0 === strpos($trimmed, 'mysqldump:') || 0 === strpos($trimmed, 'Warning:')) {
                        continue;
                    }
                    if (0 === stripos($trimmed, 'DELIMITER')) {
                        throw new Exception('The backup contains stored procedures or triggers, which can\'t be restored automatically.');
                    }
                }

                $statement .= $line;
                if (!preg_match('/;\s*$/', $line)) {
                    continue;
                }

                $query = $statement;
                $statement = '';

                if (preg_match('/^\s*(?:LOCK TABLES|UNLOCK TABLES)\b/i', $query) || preg_match('/^\s*(?:\/\*!\d+\s+)?CREATE\s+(?:ALGORITHM|DEFINER|VIEW|TRIGGER|FUNCTION|PROCEDURE)/i', $query)) {
                    continue;
                }

                if (preg_match($head, $query, $m)) {
                    $table = $m[2];
                    if (0 !== strpos($table, $prefix)) {
                        continue; // another install's table in a shared database
                    }
                    if (strlen(self::OLD_PREFIX . $table) > 64) {
                        throw new Exception('Table name too long to restore safely: ' . $table);
                    }
                    $query = $m[1] . '`' . self::TMP_PREFIX . $table . '`' . substr($query, strlen($m[0]));
                    if (false !== stripos($m[1], 'CREATE TABLE')) {
                        $query = $this->strip_foreign_keys($query);
                        $tables[$table] = true;
                    }
                }

                if ($placeholder) {
                    $query = str_replace($placeholder, '%', $query);
                }

                if (false === mysqli_query($dbh, $query)) {
                    throw new Exception('Database import failed: ' . mysqli_error($dbh));
                }
            }
        } finally {
            fclose($handle);
            mysqli_query($dbh, 'SET FOREIGN_KEY_CHECKS = 1');
        }

        return array_keys($tables);
    }

    /**
     * Backups made by the plugin's PHP exporter before 1.8.0 have every "%"
     * replaced by one random {64-hex} placeholder. Find it so it can be undone.
     */
    private function legacy_percent_placeholder($sql_path) {
        $handle = fopen($sql_path, 'r');
        if (!$handle) {
            return null;
        }
        $header = (string) fread($handle, 4096);
        if (false === stripos($header, 'WordPress Database Backup') || false !== stripos($header, 'export format: 2')) {
            fclose($handle);
            return null; // mysqldump output and fixed exports never had the bug
        }
        $found = null;
        while (!feof($handle) && !$found) {
            $chunk = fread($handle, 1048576) . (string) fread($handle, 70);
            if (preg_match('/\{[0-9a-f]{64}\}/', $chunk, $m)) {
                $found = $m[0];
            }
        }
        fclose($handle);
        return $found;
    }

    private function strip_foreign_keys($create) {
        $lines = explode("\n", $create);
        $lines = array_values(array_filter($lines, function ($line) {
            return !preg_match('/^\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY/i', $line);
        }));
        $create = implode("\n", $lines);
        return preg_replace('/,(\s*\n\s*\))/', '$1', $create);
    }

    private function swap_tables(array $tables) {
        global $wpdb;
        $pairs = array();
        $had_old = array();
        foreach ($tables as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
                $pairs[] = '`' . $table . '` TO `' . self::OLD_PREFIX . $table . '`';
                $had_old[] = $table;
            }
            $pairs[] = '`' . self::TMP_PREFIX . $table . '` TO `' . $table . '`';
        }
        // A single RENAME TABLE is atomic: either every table switches or none does.
        if (false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
            throw new Exception('Could not switch to the restored database: ' . $wpdb->last_error);
        }
        return array('tables' => array_values($tables), 'had_old' => $had_old);
    }

    private function read_preserved_options() {
        global $wpdb;
        $values = array();
        foreach (self::PRESERVED_OPTIONS as $name) {
            $values[$name] = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name), ARRAY_A);
        }
        $values['__active_plugin'] = ERRORVAULT_PLUGIN_BASENAME;
        return $values;
    }

    private function write_preserved_options(array $values) {
        global $wpdb;
        $plugin = $values['__active_plugin'];
        unset($values['__active_plugin']);

        foreach ($values as $name => $row) {
            if (is_array($row)) {
                $wpdb->replace($wpdb->options, array('option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload']));
            } else {
                $wpdb->delete($wpdb->options, array('option_name' => $name));
            }
        }

        // Keep Error-Vault active whatever the backup's plugin list says.
        $active = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins')));
        $active = is_array($active) ? $active : array();
        if (!in_array($plugin, $active, true)) {
            $active[] = $plugin;
            $wpdb->replace($wpdb->options, array('option_name' => 'active_plugins', 'option_value' => serialize(array_values($active)), 'autoload' => 'yes'));
        }
        wp_cache_flush();
    }

    private function swap_content($backup_content, $rollback) {
        $moved = array();
        foreach ((array) scandir($backup_content) as $name) {
            if ('.' === $name || '..' === $name || in_array($name, self::PROTECTED_CONTENT, true) || 0 === strpos($name, 'errorvault-quarantine')) {
                continue;
            }
            $moved[] = $this->swap_one($backup_content . '/' . $name, WP_CONTENT_DIR . '/' . $name, $rollback . '/' . $name);

            // Keep the running Error-Vault version rather than the backup's copy.
            if ('plugins' === $name) {
                $self_dir = basename(untrailingslashit(ERRORVAULT_PLUGIN_DIR));
                $restored_copy = WP_CONTENT_DIR . '/plugins/' . $self_dir;
                if (file_exists($restored_copy)) {
                    $this->rrmdir($restored_copy);
                }
                $this->copy_dir($rollback . '/plugins/' . $self_dir, $restored_copy);
            }
        }
        return $moved;
    }

    private function swap_one($source, $live, $rollback_path) {
        $had_live = file_exists($live);
        if ($had_live && !@rename($live, $rollback_path)) {
            throw new Exception('Could not move ' . basename($live) . ' aside (permissions?).');
        }
        if (!@rename($source, $live)) {
            if ($had_live) {
                @rename($rollback_path, $live);
            }
            throw new Exception('Could not move the restored ' . basename($live) . ' into place.');
        }
        return array('live' => wp_normalize_path($live), 'rollback' => $had_live ? wp_normalize_path($rollback_path) : null);
    }

    private function revert_files(array $moved, $work) {
        foreach (array_reverse($moved) as $i => $item) {
            if (file_exists($item['live'])) {
                $trash = $work . '/trash-' . $i;
                if (@rename($item['live'], $trash)) {
                    $this->rrmdir($trash);
                } else {
                    $this->rrmdir($item['live']);
                }
            }
            if ($item['rollback'] && file_exists($item['rollback'])) {
                @rename($item['rollback'], $item['live']);
            }
        }
    }

    private function discard_undo_point() {
        global $wpdb;
        $record = get_option(self::RESTORE_OPTION);
        $this->drop_tables_like(self::OLD_PREFIX . $wpdb->prefix);
        if (is_array($record) && !empty($record['work'])) {
            $this->rrmdir($record['work']);
        }
        delete_option(self::RESTORE_OPTION);
    }

    private function drop_tables_like($prefix) {
        global $wpdb;
        foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')) as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
        }
    }

    private function maintenance($on) {
        $file = ABSPATH . '.maintenance';
        if ($on) {
            @file_put_contents($file, '<?php $upgrading = ' . time() . '; ?>');
        } elseif (file_exists($file)) {
            @unlink($file);
        }
    }

    private function copy_dir($from, $to) {
        if (!is_dir($from)) {
            return;
        }
        wp_mkdir_p($to);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $target = $to . '/' . substr(wp_normalize_path($file->getPathname()), strlen(wp_normalize_path($from)) + 1);
            $file->isDir() ? wp_mkdir_p($target) : @copy($file->getPathname(), $target);
        }
    }

    private function rrmdir($path) {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            ($file->isDir() && !$file->isLink()) ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
