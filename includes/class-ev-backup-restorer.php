<?php
/**
 * One-click restore of Error-Vault backups, and undo.
 *
 * Designed so a failure part-way can't leave a half-restored site:
 *  1. Download the archive and verify its SHA-256, unzip it (unsafe paths refused).
 *  2. Import the database into temporary "evr_" tables. The live site is untouched:
 *     each statement is checked against the exact shapes mysqldump and the plugin's
 *     exporter emit, so only this site's table data and session settings from the
 *     dump are run (see plan_statement()); anything else is skipped.
 *  3. Maintenance mode on; swap wp-content folders (renames) and swap all tables
 *     in one atomic RENAME TABLE; the previous tables become "evo_" tables. If
 *     anything fails, every folder already swapped is put back.
 *  4. The previous files/tables are kept for 7 days, so the restore can be undone.
 *     They are kept in wp-content under a random name, not in wp-content/upgrade
 *     (WordPress empties that before every update). An earlier undo point is only
 *     dropped once the new restore has succeeded.
 *
 * Always kept from the live site: Error-Vault itself and its settings, the site
 * URL, wp-config.php and WordPress core (update core afterwards if needed), and
 * what backups leave out (symlinks, .git / node_modules folders, unreadable files).
 * Tables of another install sharing the database (e.g. "wp_shop_" next to "wp_")
 * are never imported, swapped or dropped.
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
    const PARK_PREFIX = 'evp_';
    const KEEP_DAYS = 7;

    /** wp-content entries a restore never replaces. */
    const PROTECTED_CONTENT = array('upgrade', 'upgrade-temp-backup', 'cache', 'wflogs', 'ai1wm-backups', 'updraft', 'backups-dup-lite');

    /** Folders backups leave out wherever they are, so a restore keeps the live ones. */
    const CARRIED_NAMES = array('.git', 'node_modules');

    /** Options carried over from the live site into the restored database. */
    const PRESERVED_OPTIONS = array(
        'siteurl', 'home', 'errorvault_settings', 'errorvault_backup_history', 'errorvault_executed_actions',
        'errorvault_quarantine', 'errorvault_quarantine_dir', 'errorvault_security_last_scan', 'errorvault_last_restore',
        'errorvault_private_dir',
    );

    /** Live table names whose evr_ copy this run created. */
    private $imported = array();

    /** Prefixes of other installs sharing the database (EV_Backup_Helpers::foreign_prefixes()). */
    private $foreign = null;

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

        if (get_transient('ev_backup_lock')) {
            throw new Exception('A backup or restore is already running on this site. Try again once it has finished.');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);

        $size = isset($source['size']) ? (int) $source['size'] : 0;
        $free = @disk_free_space(WP_CONTENT_DIR);
        if ($size && false !== $free && $free < $size * 4 + 100 * MB_IN_BYTES) {
            throw new Exception(sprintf('Not enough disk space to restore safely (need about %s free, have %s).', size_format($size * 4 + 100 * MB_IN_BYTES), size_format($free)));
        }

        // Not under wp-content/upgrade: WordPress empties that folder before every
        // update, which would wipe the undo point. The random part keeps the saved
        // (possibly infected) files unreachable on servers that ignore .htaccess.
        $restore_id = gmdate('YmdHis');
        $work = wp_normalize_path(WP_CONTENT_DIR . '/errorvault-restore-' . $restore_id . '-' . strtolower(wp_generate_password(16, false)));
        if (!wp_mkdir_p($work)) {
            throw new Exception('Could not create a working folder in wp-content.');
        }
        EV_Backup_Helpers::write_deny_files($work);
        set_transient('ev_backup_lock', 1, 3 * HOUR_IN_SECONDS);

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
            $sha = is_file($zip_path) ? hash_file('sha256', $zip_path) : false;
            $expected = $recorded ? $recorded : (isset($source['checksum']) ? strtolower((string) $source['checksum']) : '');
            if (!$expected || !is_string($sha) || !hash_equals($expected, $sha)) {
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
            // Older archives can contain the old public backup folder, including a copy
            // of the database dump. Never put that back on the site.
            $this->rrmdir($extract . '/uploads/errorvault-backups');
            $this->rrmdir($extract . '/wp-content/uploads/errorvault-backups');

            $has_content = is_dir($extract . '/wp-content');
            $legacy_uploads = !$has_content && is_dir($extract . '/uploads');
            $restores_uploads = $has_content ? is_dir($extract . '/wp-content/uploads') : $legacy_uploads;

            // 3. Database into temporary tables (live site untouched).
            $tables = $this->import_to_temp_tables($sql);
            if (!in_array($wpdb->prefix . 'options', $tables, true) || !in_array($wpdb->prefix . 'users', $tables, true)) {
                throw new Exception(sprintf('The backup has no WordPress tables with this site\'s prefix (%s), so nothing was restored.', $wpdb->prefix));
            }
        } catch (Throwable $e) {
            $this->drop_tables($this->prefixed(self::TMP_PREFIX, array_keys($this->imported)));
            $this->rrmdir($work);
            delete_transient('ev_backup_lock');
            throw $e;
        }

        // 4. Switch over. The previous undo point is set aside rather than dropped,
        // so a restore that fails from here on leaves it usable.
        $previous = get_option(self::RESTORE_OPTION);
        $preserved = $this->read_preserved_options();
        $this->maintenance(true);

        $parked = array();
        $moved = array();
        try {
            $parked = $this->park_undo_tables();

            $rollback = $work . '/rollback';
            wp_mkdir_p($rollback);

            if ($has_content) {
                $this->swap_content($extract . '/wp-content', $rollback, $moved);
            } elseif ($legacy_uploads) {
                $moved[] = $this->swap_one($extract . '/uploads', WP_CONTENT_DIR . '/uploads', $rollback . '/uploads');
                $this->carry_over_excluded($rollback . '/uploads', WP_CONTENT_DIR . '/uploads', $moved);
            }
            if (is_file($extract . '/root/.htaccess')) {
                // Kept under rollback/root: rollback/.htaccess is wp-content's own .htaccess.
                wp_mkdir_p($rollback . '/root');
                $moved[] = $this->swap_one($extract . '/root/.htaccess', ABSPATH . '.htaccess', $rollback . '/root/.htaccess');
            }

            $swap = $this->swap_tables($tables);
        } catch (Throwable $e) {
            $problems = array();
            $kept = array();
            try {
                $kept = $this->revert_files($moved, $work);
            } catch (Throwable $cleanup) {
                $problems[] = $cleanup->getMessage();
                $kept[] = 'wp-content';
            }
            try {
                $this->drop_tables($this->prefixed(self::TMP_PREFIX, $tables));
            } catch (Throwable $cleanup) {
                $problems[] = $cleanup->getMessage();
            }
            try {
                $this->unpark_undo_tables($parked);
            } catch (Throwable $cleanup) {
                $problems[] = $cleanup->getMessage();
            }
            $this->maintenance(false);
            if ($kept) {
                // Never delete the saved copy of anything that couldn't be put back.
                $problems[] = 'could not put back ' . implode(', ', array_map('basename', $kept)) . '; the saved copy was kept in a wp-content/errorvault-restore-* folder';
            } else {
                $this->rrmdir($work);
            }
            delete_transient('ev_backup_lock');
            throw new Exception(($kept ? 'Restore failed and could not be fully rolled back: ' : 'Restore failed and was rolled back: ') . $e->getMessage() . ($problems ? ' (' . implode('; ', $problems) . ')' : ''));
        }

        try {
            $this->write_preserved_options($preserved);
        } catch (Throwable $e) {
            error_log('[ErrorVault Restore] Could not carry settings over into the restored database: ' . $e->getMessage());
        }
        $this->maintenance(false);
        wp_cache_flush();

        // Only now replace the previous undo point with this one.
        $this->drop_tables($this->prefixed(self::PARK_PREFIX, $parked));
        if (is_array($previous) && !empty($previous['work']) && $previous['work'] !== $work) {
            $this->rrmdir($previous['work']);
        }

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
                $has_content ? ' and wp-content (themes, plugins' . ($restores_uploads ? ', uploads' : '') . ')' : ($legacy_uploads ? ' and uploads' : ''),
                self::KEEP_DAYS
            ),
            'restore_id' => $restore_id,
            'tables' => count($swap['tables']),
            'files_restored' => count(array_filter($moved, function ($item) {
                return empty($item['carried']);
            })),
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
        if (get_transient('ev_backup_lock')) {
            throw new Exception('A backup or restore is running on this site. Try again once it has finished.');
        }

        // Check everything is still there before changing anything: undoing the
        // database without the files (or the other way round) would mix two states.
        foreach ((array) $record['moved'] as $item) {
            if (empty($item['carried']) && !empty($item['rollback']) && !$this->path_exists($item['rollback'])) {
                throw new Exception(sprintf('The files saved before that restore (%s) are missing, so it can\'t be undone safely. Nothing was changed.', basename($item['live'])));
            }
        }
        foreach ((array) $record['had_old'] as $table) {
            if (!$this->table_exists(self::OLD_PREFIX . $table)) {
                throw new Exception('The database tables saved before that restore are missing, so it can\'t be undone safely. Nothing was changed.');
            }
        }

        set_transient('ev_backup_lock', 1, 3 * HOUR_IN_SECONDS);
        $preserved = $this->read_preserved_options();
        $this->maintenance(true);

        try {
            $this->drop_tables($this->prefixed(self::TMP_PREFIX, $this->tables_with_prefix(self::TMP_PREFIX)));
            $this->unswap_tables((array) $record['tables'], (array) $record['had_old']);
        } catch (Throwable $e) {
            $this->maintenance(false);
            delete_transient('ev_backup_lock');
            throw $e;
        }

        $this->drop_tables($this->prefixed(self::TMP_PREFIX, (array) $record['tables']));
        $kept = $this->revert_files((array) $record['moved'], $record['work']);

        unset($preserved['errorvault_last_restore']);
        $this->write_preserved_options($preserved);
        $wpdb->delete($wpdb->options, array('option_name' => self::RESTORE_OPTION));

        $this->maintenance(false);
        wp_cache_flush();
        if (!$kept) {
            $this->rrmdir($record['work']);
        }
        delete_transient('ev_backup_lock');

        if ($kept) {
            return array(
                'message' => sprintf('Undid the restore of the database, but could not put back %s; the saved copy was kept in a wp-content/errorvault-restore-* folder.', implode(', ', array_map('basename', $kept))),
                'restore_id' => $restore_id,
            );
        }
        return array('message' => 'Undid the restore: the database and files are back to how they were before it.', 'restore_id' => $restore_id);
    }

    /**
     * Drop the undo point once it's older than KEEP_DAYS (called on each poll).
     * Never throws: a problem here must not stop the check-in.
     */
    public static function cleanup_expired() {
        try {
            $record = get_option(self::RESTORE_OPTION);
            if (is_array($record) && time() - (int) $record['time'] > self::KEEP_DAYS * DAY_IN_SECONDS && !get_transient('ev_backup_lock')) {
                (new self())->discard_undo_point($record);
            }
        } catch (Throwable $e) {
            error_log('[ErrorVault Restore] Could not remove the expired undo point: ' . $e->getMessage());
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
        $foreign = $this->foreign_prefixes();

        $this->drop_tables($this->prefixed(self::TMP_PREFIX, $this->tables_with_prefix(self::TMP_PREFIX)));
        $placeholder = $this->legacy_percent_placeholder($sql_path);

        $handle = fopen($sql_path, 'r');
        if (!$handle) {
            throw new Exception('Could not read database.sql.');
        }

        // The dump's SET statements run on WordPress's own connection; put its
        // session settings back afterwards.
        $session = array();
        $result = mysqli_query($dbh, 'SELECT @@SESSION.sql_mode, @@SESSION.time_zone, @@SESSION.character_set_client, @@SESSION.character_set_results, @@SESSION.collation_connection');
        if ($result instanceof mysqli_result) {
            $row = mysqli_fetch_row($result);
            mysqli_free_result($result);
            foreach (array('sql_mode', 'time_zone', 'character_set_client', 'character_set_results', 'collation_connection') as $i => $name) {
                if (isset($row[$i])) {
                    $session[] = $name . " = '" . mysqli_real_escape_string($dbh, $row[$i]) . "'";
                }
            }
        }
        mysqli_query($dbh, 'SET FOREIGN_KEY_CHECKS = 0');

        $tables = array();
        $statement = '';

        try {
            while (false !== ($line = fgets($handle))) {
                if ('' === $statement) {
                    $trimmed = ltrim($line);
                    if ($this->is_noise_line($trimmed)) {
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

                // Only the exact statement forms mysqldump (MySQL and MariaDB) and the
                // plugin's own exporter emit for table data and session setup are run;
                // anything else (GTID and binary-log settings, views, routines, locks,
                // multi-table drops, INSERT ... SELECT, GLOBAL variables, ...) is skipped,
                // so a crafted or tampered dump can't reach the live database or server.
                $plan = $this->plan_statement($query, $prefix, $foreign);
                if ('skip' === $plan['action']) {
                    continue;
                }
                $query = $plan['query'];

                if ('table' === $plan['action']) {
                    $table = $plan['table'];
                    if (strlen(self::OLD_PREFIX . $table) > 64) {
                        throw new Exception('Table name too long to restore safely: ' . $table);
                    }
                    if ($plan['is_create']) {
                        $query = $this->strip_foreign_keys($query);
                        $tables[$table] = true;
                        $this->imported[$table] = true;
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
            if ($session) {
                mysqli_query($dbh, 'SET SESSION ' . implode(', ', $session));
            }
        }

        return array_keys($tables);
    }

    /**
     * A line that begins no statement of its own while assembling the dump: a blank
     * line, a "--" comment, a mysqldump progress/warning line, or a whole-line
     * version-guard comment that opens with "/*!" (or MariaDB's "/*M!") and closes
     * on the same line with no trailing ";" - such as MySQL's and MariaDB's "enable
     * the sandbox mode" line, which would otherwise merge with the next statement.
     */
    private function is_noise_line($trimmed) {
        return '' === $trimmed
            || 0 === strpos($trimmed, '--')
            || 0 === strpos($trimmed, 'mysqldump:')
            || 0 === strpos($trimmed, 'Warning:')
            || (bool) preg_match('#^/\*[!M].*\*/\s*$#', rtrim($trimmed));
    }

    private function plan_statement($query, $prefix, array $foreign) {
        // A table keyword followed by the first backticked table name. The rest of
        // the statement (the "tail") is then checked so only the safe shape of each
        // keyword is accepted.
        $head = '/^(\s*(?:DROP TABLE IF EXISTS|DROP TABLE|CREATE TABLE(?: IF NOT EXISTS)?|INSERT(?: IGNORE)? INTO|REPLACE INTO|\/\*!\d+\s+ALTER TABLE|ALTER TABLE)\s+)`([^`]+)`/i';
        if (preg_match($head, $query, $m)) {
            $table = $m[2];
            if (!EV_Backup_Helpers::is_site_table($table, $prefix, $foreign)) {
                return array('action' => 'skip'); // another install's table in a shared database
            }
            $keyword = $this->head_keyword($m[1]);
            $tail = substr($query, strlen($m[0]));
            if (!$this->table_tail_is_allowed($keyword, $tail)) {
                // e.g. "DROP TABLE `a`, `b`", "CREATE TABLE `t` SELECT ...",
                // "INSERT INTO `t` SELECT ...", "ALTER TABLE `t` RENAME TO ...".
                return array('action' => 'skip');
            }
            return array(
                'action' => 'table',
                'query' => $m[1] . '`' . self::TMP_PREFIX . $table . '`' . $tail,
                'table' => $table,
                'is_create' => ('CREATE' === $keyword),
            );
        }

        if ($this->is_allowed_set($query)) {
            return array('action' => 'session', 'query' => $query);
        }

        return array('action' => 'skip');
    }

    /** Which table keyword the head of a matched statement began with. */
    private function head_keyword($head) {
        if (false !== stripos($head, 'CREATE TABLE')) { return 'CREATE'; }
        if (false !== stripos($head, 'ALTER TABLE')) { return 'ALTER'; }
        if (false !== stripos($head, 'REPLACE')) { return 'REPLACE'; }
        if (false !== stripos($head, 'INSERT')) { return 'INSERT'; }
        return 'DROP';
    }

    /**
     * The part of a table statement after "KEYWORD `table`" must match the narrow
     * shape mysqldump and the plugin exporter produce, and nothing wider:
     *   DROP           only the terminator (a single table, never "`a`, `b`")
     *   CREATE TABLE   a "(" column-definition list (never SELECT / LIKE / AS)
     *   INSERT/REPLACE an optional "(`col`, ...)" list then VALUES (never SELECT)
     *   ALTER TABLE    only DISABLE KEYS / ENABLE KEYS, optionally version-guarded
     */
    private function table_tail_is_allowed($keyword, $tail) {
        switch ($keyword) {
            case 'DROP':
                return (bool) preg_match('/^\s*;\s*$/', $tail);
            case 'CREATE':
                return $this->create_tail_is_allowed($tail);
            case 'INSERT':
            case 'REPLACE':
                return $this->insert_tail_is_allowed($tail);
            case 'ALTER':
                $core = preg_replace('/;\s*$/', '', trim($tail)); // trailing ";"
                $core = preg_replace('#\s*\*/$#', '', $core);     // close of a /*! ... */ wrapper
                return (bool) preg_match('/^(?:DISABLE|ENABLE)\s+KEYS$/i', trim((string) $core));
        }
        return false;
    }

    /**
     * CREATE must contain one complete parenthesised definition and must not turn
     * into CREATE ... AS SELECT / SELECT after it. Quoted comments and table
     * comments are masked before checking the trailing table options.
     */
    private function create_tail_is_allowed($tail) {
        $i = $this->skip_space($tail, 0);
        if (!isset($tail[$i]) || '(' !== $tail[$i]) {
            return false;
        }
        $end = $this->parenthesized_end($tail, $i);
        if (false === $end) {
            return false;
        }
        $options = substr($tail, $end);
        if (!preg_match('/;\s*$/', $options)) {
            return false;
        }
        $visible = $this->mask_sql_literals_and_comments($options);
        return !preg_match('/\b(?:AS\s+)?SELECT\b|\bLIKE\b/i', $visible);
    }

    /**
     * INSERT/REPLACE may have a backticked column list followed by VALUES, then
     * one or more tuples made only of dump literals. Expressions, variables and
     * subqueries are refused even when hidden inside a VALUES tuple.
     */
    private function insert_tail_is_allowed($tail) {
        $len = strlen($tail);
        $i = $this->skip_space($tail, 0);

        if ($i < $len && '(' === $tail[$i]) {
            $end = $this->parenthesized_end($tail, $i);
            if (false === $end) {
                return false;
            }
            $columns = substr($tail, $i + 1, $end - $i - 2);
            if (!preg_match('/^\s*`(?:``|[^`])+`(?:\s*,\s*`(?:``|[^`])+`)*\s*$/', $columns)) {
                return false;
            }
            $i = $this->skip_space($tail, $end);
        }

        if (!preg_match('/\GVALUES\b/i', $tail, $m, 0, $i)) {
            return false;
        }
        $i += strlen($m[0]);

        while (true) {
            $i = $this->skip_space($tail, $i);
            if ($i >= $len || '(' !== $tail[$i]) {
                return false;
            }
            $i++;

            while (true) {
                $i = $this->skip_space($tail, $i);
                $i = $this->dump_literal_end($tail, $i);
                if (false === $i) {
                    return false;
                }
                $i = $this->skip_space($tail, $i);
                if ($i < $len && ',' === $tail[$i]) {
                    $i++;
                    continue;
                }
                if ($i >= $len || ')' !== $tail[$i]) {
                    return false;
                }
                $i++;
                break;
            }

            $i = $this->skip_space($tail, $i);
            if ($i < $len && ',' === $tail[$i]) {
                $i++;
                continue;
            }
            return $i < $len && ';' === $tail[$i] && '' === trim(substr($tail, $i + 1));
        }
    }

    /** End offset of one literal emitted by mysqldump or the PHP exporter. */
    private function dump_literal_end($s, $i) {
        $rest = substr($s, $i);
        if (preg_match('/^(?:NULL|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|0x[0-9a-f]+)\b/i', $rest, $m)) {
            return $i + strlen($m[0]);
        }
        if (preg_match('/^(?:_binary\s*)?/i', $rest, $m)) {
            $q = $i + strlen($m[0]);
            if (isset($s[$q]) && ('\'' === $s[$q] || '"' === $s[$q])) {
                return $this->skip_quoted($s, $q);
            }
        }
        if (preg_match('/^[bBxX]/', $rest) && isset($s[$i + 1]) && '\'' === $s[$i + 1]) {
            $end = $this->skip_quoted($s, $i + 1);
            if (false === $end) {
                return false;
            }
            $digits = substr($s, $i + 2, $end - $i - 3);
            return preg_match('/^[bB]/', $rest) ? (preg_match('/^[01]*$/', $digits) ? $end : false) : (preg_match('/^[0-9a-f]*$/i', $digits) ? $end : false);
        }
        return false;
    }

    private function skip_space($s, $i) {
        $len = strlen($s);
        while ($i < $len && ctype_space($s[$i])) { $i++; }
        return $i;
    }

    /** Offset after the matching closing parenthesis, or false. */
    private function parenthesized_end($s, $i) {
        $len = strlen($s);
        $depth = 0;
        for (; $i < $len; $i++) {
            $c = $s[$i];
            if ('\'' === $c || '"' === $c || '`' === $c) {
                $end = $this->skip_quoted($s, $i);
                if (false === $end) { return false; }
                $i = $end - 1;
            } elseif ('(' === $c) {
                $depth++;
            } elseif (')' === $c && 0 === --$depth) {
                return $i + 1;
            }
        }
        return false;
    }

    /** Hide quoted strings/identifiers and comments while preserving keywords outside them. */
    private function mask_sql_literals_and_comments($s) {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ('\'' === $s[$i] || '"' === $s[$i] || '`' === $s[$i]) {
                $end = $this->skip_quoted($s, $i);
                if (false === $end) { return $s; }
                $out .= str_repeat(' ', $end - $i);
                $i = $end - 1;
            } elseif ('/' === $s[$i] && isset($s[$i + 1]) && '*' === $s[$i + 1]
                && (!isset($s[$i + 2]) || ('!' !== $s[$i + 2] && 'M' !== $s[$i + 2]))) {
                $end = strpos($s, '*/', $i + 2);
                if (false === $end) { return $s; }
                $end += 2;
                $out .= str_repeat(' ', $end - $i);
                $i = $end - 1;
            } else {
                $out .= $s[$i];
            }
        }
        return $out;
    }

    /**
     * A SET statement is allowed only when it is "SET NAMES ...", "SET CHARACTER
     * SET ...", or a comma-separated list of assignments that each target a user
     * variable (@name) or one of a fixed set of session variables — never a GLOBAL,
     * @@GLOBAL or PERSIST variable. This puts the dump's own session setup back
     * without letting it change the live server. Commas, keywords and quotes inside
     * string values are ignored by splitting at the top level only.
     */
    private function is_allowed_set($query) {
        $s = preg_replace('/;\s*$/', '', trim($query));
        if (preg_match('#^/\*!\d+\s+(.*?)\s*\*/$#s', $s, $m)) {
            $s = $m[1]; // unwrap a /*!NNNNN ... */ version guard
        }
        if (!preg_match('/^SET\s+(.*)$/is', $s, $m)) {
            return false;
        }
        $args = trim($m[1]);

        $charset = '(?:\'[^\']*\'|"[^"]*"|`[^`]*`|[A-Za-z0-9_]+)';
        if (preg_match('/^NAMES\s+' . $charset . '(?:\s+COLLATE\s+' . $charset . ')?$/i', $args)
            || preg_match('/^CHARACTER\s+SET\s+' . $charset . '$/i', $args)) {
            return true;
        }

        foreach ($this->split_top_level($args) as $assignment) {
            if (!$this->set_assignment_is_allowed($assignment)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate one "target = value" assignment from a SET. The target must be a
     * user variable or an allowed session variable (optionally "SESSION"/"@@"-
     * qualified, never GLOBAL / @@GLOBAL / PERSIST). The value must be a quoted
     * string, a number, a user or session variable read, or a bare identifier
     * (a charset/collation name).
     */
    private function set_assignment_is_allowed($assignment) {
        $a = trim($assignment);
        if ('' === $a) {
            return false;
        }

        // The first top-level "=" splits target from value; a target never contains one.
        $len = strlen($a);
        $eq = -1;
        for ($i = 0; $i < $len; $i++) {
            $c = $a[$i];
            if ('\'' === $c || '"' === $c || '`' === $c) {
                $end = $this->skip_quoted($a, $i);
                if (false === $end) { return false; }
                $i = $end - 1;
            } elseif ('=' === $c) {
                $eq = $i;
                break;
            }
        }
        if ($eq < 0) {
            return false;
        }
        $target = trim(substr($a, 0, $eq));
        $value = trim(substr($a, $eq + 1));

        $session_vars = 'FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_MODE|SQL_NOTES|TIME_ZONE|CHARACTER_SET_CLIENT|CHARACTER_SET_RESULTS|COLLATION_CONNECTION';
        $target_ok = preg_match('/^@[A-Za-z0-9_$]+$/', $target)
            || preg_match('/^(?:SESSION\s+|@@(?:SESSION\.)?)?(?:' . $session_vars . ')$/i', $target);
        if (!$target_ok) {
            return false;
        }

        if ('' !== $value && ('\'' === $value[0] || '"' === $value[0])) {
            // Exactly one complete quoted string, nothing trailing after the close.
            $end = $this->skip_quoted($value, 0);
            return false !== $end && $end === strlen($value);
        }
        return (bool) (
            preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $value)     // number
            || preg_match('/^@[A-Za-z0-9_$]+$/', $value)          // @user_var
            || preg_match('/^@@[A-Za-z_][A-Za-z0-9_]*$/', $value) // @@session_read (no dot, so not @@GLOBAL.x)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)   // bare charset/collation name
        );
    }

    /**
     * Split a SET argument list on its top-level commas only, so a comma inside a
     * quoted string, backtick identifier or parentheses stays with its value.
     *
     * @return string[]
     */
    private function split_top_level($s) {
        $parts = array();
        $len = strlen($s);
        $depth = 0;
        $start = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ('\'' === $c || '"' === $c || '`' === $c) {
                $end = $this->skip_quoted($s, $i);
                if (false === $end) { return array(''); }
                $i = $end - 1;
            } elseif ('(' === $c) {
                $depth++;
            } elseif (')' === $c) {
                if ($depth > 0) { $depth--; }
            } elseif (',' === $c && 0 === $depth) {
                $parts[] = substr($s, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($s, $start);
        return $parts;
    }

    /**
     * Offset just past the string or backtick identifier that opens at $i. Doubled
     * delimiters ('' "" ``) and, inside '...'/"..." only, backslash escapes are
     * treated as part of the value, the way MySQL parses a dump. An unterminated
     * quote returns false.
     */
    private function skip_quoted($s, $i) {
        $q = $s[$i];
        $len = strlen($s);
        for ($i++; $i < $len; $i++) {
            $c = $s[$i];
            if ('\\' === $c && '`' !== $q) {
                $i++; // skip the escaped character
                continue;
            }
            if ($c === $q) {
                if ($i + 1 < $len && $s[$i + 1] === $q) {
                    $i++; // a doubled delimiter is an escaped delimiter, not the end
                    continue;
                }
                return $i + 1;
            }
        }
        return false;
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
            if ($this->table_exists($table)) {
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

    /**
     * Reverse swap_tables(): restored tables back to evr_, evo_ tables back live.
     */
    private function unswap_tables(array $tables, array $had_old) {
        global $wpdb;
        $pairs = array();
        foreach ($tables as $table) {
            $pairs[] = '`' . $table . '` TO `' . self::TMP_PREFIX . $table . '`';
            if (in_array($table, $had_old, true)) {
                $pairs[] = '`' . self::OLD_PREFIX . $table . '` TO `' . $table . '`';
            }
        }
        if ($pairs && false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
            throw new Exception('Could not switch the database back: ' . $wpdb->last_error);
        }
    }

    /**
     * Rename the previous undo point's evo_ tables to evp_, so this restore can use
     * the evo_ names while the old ones stay recoverable. Returns the live table
     * names that were set aside.
     */
    private function park_undo_tables() {
        global $wpdb;

        // Tables set aside by a restore that was killed part-way: back to evo_,
        // unless evo_ has been taken since.
        $pairs = array();
        foreach ($this->tables_with_prefix(self::PARK_PREFIX) as $table) {
            if ($this->table_exists(self::OLD_PREFIX . $table)) {
                $this->drop_tables(array(self::PARK_PREFIX . $table));
            } else {
                $pairs[] = '`' . self::PARK_PREFIX . $table . '` TO `' . self::OLD_PREFIX . $table . '`';
            }
        }
        if ($pairs && false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
            throw new Exception('Could not recover the previous undo point: ' . $wpdb->last_error);
        }

        $parked = $this->tables_with_prefix(self::OLD_PREFIX);
        $pairs = array();
        foreach ($parked as $table) {
            $pairs[] = '`' . self::OLD_PREFIX . $table . '` TO `' . self::PARK_PREFIX . $table . '`';
        }
        if ($pairs && false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
            throw new Exception('Could not set the previous undo point aside: ' . $wpdb->last_error);
        }
        return $parked;
    }

    private function unpark_undo_tables(array $parked) {
        global $wpdb;
        $pairs = array();
        foreach ($parked as $table) {
            $pairs[] = '`' . self::PARK_PREFIX . $table . '` TO `' . self::OLD_PREFIX . $table . '`';
        }
        if ($pairs && false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
            throw new Exception('Could not bring back the previous undo point: ' . $wpdb->last_error);
        }
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

    /**
     * Swap each top-level entry of the restored wp-content into place. $moved is
     * filled as it goes, so if a later entry fails the earlier ones can be put back.
     */
    private function swap_content($backup_content, $rollback, array &$moved) {
        $entries = @scandir($backup_content);
        if (false === $entries) {
            throw new Exception('Could not read the restored wp-content folder.');
        }
        foreach ($entries as $name) {
            if ('.' === $name || '..' === $name || in_array($name, self::PROTECTED_CONTENT, true) || EV_Backup_Helpers::is_own_folder($name)) {
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

            $this->carry_over_excluded($rollback . '/' . $name, WP_CONTENT_DIR . '/' . $name, $moved);
        }
    }

    private function swap_one($source, $live, $rollback_path) {
        $had_live = $this->path_exists($live);
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

    /**
     * Backups leave out symlinks, .git / node_modules folders and unreadable files,
     * so the restored folder doesn't have them. Move the live ones (now in the saved
     * copy) into the restored folder wherever that path is free, recorded as
     * "carried" so an undo or rollback moves them back first.
     */
    private function carry_over_excluded($saved_root, $live_root, array &$moved) {
        if (!is_dir($saved_root) || is_link($saved_root) || !is_dir($live_root) || is_link($live_root)) {
            return;
        }
        $pending = array('');
        $visited = 0;
        while ($pending) {
            $relative = array_pop($pending);
            $entries = @scandir('' === $relative ? $saved_root : $saved_root . '/' . $relative);
            if (false === $entries) {
                continue;
            }
            foreach ($entries as $name) {
                if ('.' === $name || '..' === $name) {
                    continue;
                }
                if (++$visited > 250000) {
                    error_log('[ErrorVault Restore] Stopped looking for symlinks, .git and node_modules after 250,000 entries in ' . basename($live_root) . '.');
                    return;
                }
                $child = '' === $relative ? $name : $relative . '/' . $name;
                $from = $saved_root . '/' . $child;
                $to = $live_root . '/' . $child;
                $is_link = is_link($from);
                $is_dir = !$is_link && is_dir($from);

                if (!$is_link && !($is_dir && in_array($name, self::CARRIED_NAMES, true)) && ($is_dir || is_readable($from))) {
                    // An ordinary entry: look inside folders the restored copy also has.
                    if ($is_dir && is_dir($to) && !is_link($to)) {
                        $pending[] = $child;
                    }
                    continue;
                }
                if ($this->path_exists($to) || !is_dir(dirname($to))) {
                    continue;
                }
                if (@rename($from, $to)) {
                    $moved[] = array('live' => wp_normalize_path($to), 'rollback' => wp_normalize_path($from), 'carried' => true);
                }
            }
        }
    }

    /**
     * Put swapped files back. Returns the live paths that could not be put back;
     * their saved copies are left where they are.
     */
    private function revert_files(array $moved, $work) {
        $failed = array();
        foreach (array_reverse($moved, true) as $i => $item) {
            $live = $item['live'];
            $rollback = $item['rollback'];

            if (!empty($item['carried'])) {
                // Return it to the saved copy of the folder it came from.
                if ($this->path_exists($live) && !$this->path_exists($rollback) && !@rename($live, $rollback)) {
                    $failed[] = $live;
                }
                continue;
            }
            if ($rollback && !$this->path_exists($rollback)) {
                // The saved copy is gone: keep what's live rather than delete it.
                $failed[] = $live;
                continue;
            }
            if ($this->path_exists($live)) {
                $trash = $work . '/trash-' . $i;
                if (@rename($live, $trash)) {
                    $this->rrmdir($trash);
                } else {
                    $this->rrmdir($live);
                }
            }
            if ($rollback && !@rename($rollback, $live)) {
                $failed[] = $live;
            }
        }
        return $failed;
    }

    private function discard_undo_point($record) {
        $this->drop_tables($this->prefixed(self::OLD_PREFIX, $this->tables_with_prefix(self::OLD_PREFIX)));
        if (is_array($record) && !empty($record['work'])) {
            $this->rrmdir($record['work']);
        }
        delete_option(self::RESTORE_OPTION);
    }

    /**
     * Live table names X for which a "{$p}X" table exists, leaving out tables of
     * other installs that share the database.
     */
    private function tables_with_prefix($p) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $foreign = $this->foreign_prefixes();
        $names = array();
        foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($p . $prefix) . '%')) as $table) {
            $name = substr((string) $table, strlen($p));
            if (EV_Backup_Helpers::is_site_table($name, $prefix, $foreign)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function foreign_prefixes() {
        global $wpdb;
        if (null === $this->foreign) {
            $live = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
            $this->foreign = EV_Backup_Helpers::foreign_prefixes($live, $wpdb->prefix);
        }
        return $this->foreign;
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private function prefixed($p, array $tables) {
        return array_map(function ($table) use ($p) {
            return $p . $table;
        }, $tables);
    }

    private function drop_tables(array $tables) {
        global $wpdb;
        foreach ($tables as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
        }
    }

    private function path_exists($path) {
        return file_exists($path) || is_link($path);
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
        try {
            // CATCH_GET_CHILD: a subfolder that can't be opened is skipped instead of
            // throwing (an exception here used to stop every later check-in).
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($it as $file) {
                ($file->isDir() && !$file->isLink()) ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        } catch (Exception $e) {
            // The folder itself can't be opened; leave it.
        }
        @rmdir($path);
    }
}
