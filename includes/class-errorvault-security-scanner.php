<?php
/**
 * On-site security scanner for Error-Vault.
 *
 * Collects what only code running inside WordPress can see and reports it to
 * the Error-Vault portal, which does the final analysis:
 *
 *  - core files vs the official WordPress.org checksums
 *  - administrator accounts, read straight from the database so malware that
 *    filters the Users screen can't hide them
 *  - installed plugins, verified against WordPress.org plugin checksums
 *  - webshell / backdoor signatures, known wp2shell file hashes and folders
 *  - PHP in uploads, .htaccess / .user.ini tampering, risky settings
 *
 * Also ships a stop-gap "virtual patch" for wp2shell (CVE-2026-63030): on
 * affected core versions, anonymous requests to the REST batch endpoint are
 * refused until WordPress is updated.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-errorvault-security-evidence.php';

class ErrorVault_Security_Scanner {

    const POLL_HOOK = 'errorvault_security_poll';
    const RUN_NOW_HOOK = 'errorvault_security_run_now';
    const LOCK_KEY = 'errorvault_security_scan_lock';
    const BACKOFF_KEY = 'errorvault_security_backoff';
    const LAST_SCAN_OPTION = 'errorvault_security_last_scan';
    const LAST_LOGIN_META = 'errorvault_last_login';
    const API_BASE = 'https://error-vault.com/api/v1';

    const MAX_ENTRIES = 150000;
    const MAX_READ_BYTES = 2097152; // 2 MB
    const MAX_LIST = 100;

    private $iocs;
    private $deadline;
    private $truncated = false;
    private $entries = 0;
    private $files_scanned = 0;
    private $core_checksums = null;
    private $core_status = 'unavailable';
    private $plugin_checksums = array();   // slug => [relative path => md5|md5[]]
    private $plugin_modified = array();    // slug => [relative paths]
    private $plugin_unexpected = array();  // "slug/path" list
    private $ioc_hash_set = array();
    private $ioc_hash_hits = array();
    private $ioc_dir_hits = array();
    private $signature_hits = array('critical' => array(), 'warning' => array());
    private $php_in_uploads = array();
    private $htaccess_uploads = array();
    private $recent_php = array();
    private $findings = array();
    private $coverage_gaps = array();
    private $recognized_files = array();
    private $wp_cli_checksums = null;
    private $inspected_paths = array();

    /* ------------------------------------------------------------------
     * Bootstrapping
     * ------------------------------------------------------------------ */

    public static function init() {
        add_action(self::POLL_HOOK, array(__CLASS__, 'poll'));
        add_action(self::RUN_NOW_HOOK, array(__CLASS__, 'run_manual'));
        add_action('wp_ajax_errorvault_run_security_scan', array(__CLASS__, 'ajax_run_scan'));
        add_action('wp_login', array(__CLASS__, 'record_login'), 10, 2);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'block_wp2shell_batch'), 1, 3);
        add_action('admin_notices', array(__CLASS__, 'vulnerable_core_notice'));

        $settings = get_option('errorvault_settings', array());
        if (!empty($settings['api_token']) && !wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + 120, 'five_minutes', self::POLL_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::POLL_HOOK);
        wp_clear_scheduled_hook(self::RUN_NOW_HOOK);
    }

    /* ------------------------------------------------------------------
     * wp2shell virtual patch
     * ------------------------------------------------------------------ */

    public static function normalize_version($version) {
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', (string) $version, $m)) {
            return '0.0.0';
        }
        return (int) $m[1] . '.' . (isset($m[2]) ? (int) $m[2] : 0) . '.' . (isset($m[3]) ? (int) $m[3] : 0);
    }

    /**
     * Core version as installed on disk. get_bloginfo('version') reflects the
     * code loaded into this request, which is stale right after a core update.
     */
    public static function installed_wp_version() {
        $contents = @file_get_contents(ABSPATH . WPINC . '/version.php');
        if ($contents && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $contents, $m)) {
            return $m[1];
        }
        return get_bloginfo('version');
    }

    /**
     * Language of the installed WordPress package (e.g. en_ZA), from
     * $wp_local_package in version.php. This, not the site's display language,
     * decides which files are on disk, and it's what core uses for checksums.
     */
    public static function installed_wp_package_locale() {
        $contents = @file_get_contents(ABSPATH . WPINC . '/version.php');
        if ($contents && preg_match('/\$wp_local_package\s*=\s*[\'"]([A-Za-z_]+)[\'"]/', $contents, $m)) {
            return $m[1];
        }
        return 'en_US';
    }

    /**
     * version.php legitimately differs between packages/hosts. Accept it as long
     * as it contains nothing but variable assignments of plain values; anything
     * else (function calls, includes, eval...) means it was tampered with.
     */
    public static function version_php_is_benign($path) {
        $code = @file_get_contents($path);
        if (!$code || !function_exists('token_get_all')) {
            return false;
        }

        $allowed = array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_ARRAY, T_DOUBLE_ARROW);
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (!in_array($token[0], $allowed, true)) {
                    return false;
                }
            } elseif (!in_array($token, array('=', ';', ',', '(', ')', '[', ']'), true)) {
                return false;
            }
        }
        return true;
    }

    public static function is_wp2shell_vulnerable($version = null) {
        $v = self::normalize_version($version ? $version : get_bloginfo('version'));

        return (version_compare($v, '6.9.0', '>=') && version_compare($v, '6.9.4', '<='))
            || (version_compare($v, '7.0.0', '>=') && version_compare($v, '7.0.1', '<='));
    }

    public static function virtual_patch_active() {
        return self::is_wp2shell_vulnerable() && apply_filters('errorvault_wp2shell_virtual_patch', true);
    }

    /**
     * Refuse anonymous REST batch requests on wp2shell-affected versions.
     * Logged-in editors (the block editor uses the batch API) are unaffected.
     */
    public static function block_wp2shell_batch($result, $server, $request) {
        if (null !== $result || !self::virtual_patch_active() || is_user_logged_in()) {
            return $result;
        }

        // REST routes match case-insensitively and tolerate extra leading slashes.
        $route = strtolower(ltrim((string) $request->get_route(), '/'));
        if (strpos($route, 'batch/v1') === 0) {
            return new WP_Error(
                'errorvault_batch_blocked',
                __('The REST batch API is disabled for anonymous requests until WordPress is updated.', 'errorvault'),
                array('status' => 403)
            );
        }

        return $result;
    }

    public static function vulnerable_core_notice() {
        if (!current_user_can('update_core') || !self::is_wp2shell_vulnerable()) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Error-Vault security warning:', 'errorvault') . '</strong> ';
        printf(
            /* translators: %s: WordPress version */
            esc_html__('WordPress %s is affected by wp2shell (CVE-2026-63030), a vulnerability that is being actively exploited to take over sites. Update WordPress now.', 'errorvault'),
            esc_html(get_bloginfo('version'))
        );
        if (self::virtual_patch_active()) {
            echo ' ' . esc_html__('Until then, Error-Vault is blocking anonymous requests to the REST batch endpoint.', 'errorvault');
        }
        echo ' <a href="' . esc_url(admin_url('update-core.php')) . '">' . esc_html__('Go to updates', 'errorvault') . '</a></p></div>';
    }

    /* ------------------------------------------------------------------
     * Scheduling / transport
     * ------------------------------------------------------------------ */

    public static function record_login($user_login, $user) {
        if ($user instanceof WP_User && user_can($user, 'manage_options')) {
            update_user_meta($user->ID, self::LAST_LOGIN_META, array(
                'time' => time(),
                'ip' => self::client_ip(),
            ));
        }
    }

    private static function client_ip() {
        foreach (array('HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key]) && filter_var(wp_unslash($_SERVER[$key]), FILTER_VALIDATE_IP)) {
                return sanitize_text_field(wp_unslash($_SERVER[$key]));
            }
        }
        return '';
    }

    private static function api_token() {
        $settings = get_option('errorvault_settings', array());
        return isset($settings['api_token']) ? $settings['api_token'] : '';
    }

    /**
     * Portal API base. Override with ERRORVAULT_API_BASE in wp-config.php
     * only for development against a local portal.
     */
    public static function api_base() {
        return defined('ERRORVAULT_API_BASE') ? rtrim(ERRORVAULT_API_BASE, '/') : self::API_BASE;
    }

    public static function headers() {
        return array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-API-Token' => self::api_token(),
            'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
        );
    }

    /**
     * Cron (every 5 minutes): ask the portal whether a scan is due or requested.
     */
    public static function poll() {
        if (!self::api_token() || get_transient(self::LOCK_KEY)) {
            return;
        }

        // Re-send any action results that failed to reach the portal last time.
        ErrorVault_Security_Actions::flush_unreported();

        // Drop restore undo points (old tables/files) once they're over a week old.
        EV_Backup_Restorer::cleanup_expired();

        $data = self::fetch_pending();
        if (null === $data) {
            return;
        }

        $iocs = isset($data['iocs']) && is_array($data['iocs']) ? $data['iocs'] : array();

        // Cleanup actions queued in the portal run first; then rescan so the
        // portal sees the result of the cleanup straight away.
        if (!empty($data['actions']) && is_array($data['actions'])) {
            set_transient(self::LOCK_KEY, time(), 30 * MINUTE_IN_SECONDS);
            try {
                $completed = ErrorVault_Security_Actions::process($data['actions']);
            } finally {
                delete_transient(self::LOCK_KEY);
            }
            delete_transient(self::BACKOFF_KEY);

            // After a core update this request still runs the old core code,
            // so rescan from a fresh request a minute from now instead.
            if (in_array('update_core', $completed, true)) {
                wp_schedule_single_event(time() + 60, self::RUN_NOW_HOOK);
                return;
            }
            self::run_and_report($iocs, 'requested');
            return;
        }

        if (!empty($data['scan']) && !get_transient(self::BACKOFF_KEY)) {
            self::run_and_report($iocs, isset($data['trigger']) ? $data['trigger'] : 'scheduled');
        }
    }

    private static function fetch_pending() {
        $response = wp_remote_get(self::api_base() . '/security/pending', array(
            'timeout' => 15,
            'headers' => self::headers(),
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return isset($body['data']) && is_array($body['data']) ? $body['data'] : null;
    }

    public static function run_manual() {
        self::run_and_report(array(), 'manual');
    }

    /**
     * Admin "Run scan now": hand off to a one-off cron event so the scan
     * isn't cut short by the AJAX request's timeout.
     */
    public static function ajax_run_scan() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!self::api_token()) {
            wp_send_json_error(__('Add your API token first.', 'errorvault'));
        }
        if (get_transient(self::LOCK_KEY)) {
            wp_send_json_error(__('A security scan is already running.', 'errorvault'));
        }

        delete_transient(self::BACKOFF_KEY);
        wp_schedule_single_event(time(), self::RUN_NOW_HOOK);
        spawn_cron();

        wp_send_json_success(__('Security scan started. Results appear in Error-Vault in a few minutes.', 'errorvault'));
    }

    public static function run_and_report($iocs = array(), $trigger = 'manual') {
        if (get_transient(self::LOCK_KEY)) {
            return new WP_Error('errorvault_scan_locked', 'A security scan is already running.');
        }
        set_transient(self::LOCK_KEY, time(), 15 * MINUTE_IN_SECONDS);

        $started = time();
        try {
            $scanner = new self($iocs);
            $report = $scanner->scan();
            $report['trigger'] = in_array($trigger, array('scheduled', 'requested', 'manual'), true) ? $trigger : 'manual';
            $result = self::send_report($report);
        } catch (Throwable $e) {
            $result = new WP_Error('errorvault_scan_failed', $e->getMessage());
        }

        delete_transient(self::LOCK_KEY);

        $summary = array('time' => $started, 'status' => null, 'url' => null, 'error' => null);
        if (is_wp_error($result)) {
            $summary['error'] = $result->get_error_message();
            // Don't hammer the portal (or the server) every 5 minutes if reporting keeps failing.
            set_transient(self::BACKOFF_KEY, 1, HOUR_IN_SECONDS);
            error_log('[ErrorVault Security] Scan/report failed: ' . $summary['error']);
        } else {
            $summary['status'] = isset($result['status']) ? $result['status'] : null;
            $summary['url'] = isset($result['url']) ? $result['url'] : null;
        }
        update_option(self::LAST_SCAN_OPTION, $summary, false);

        return $result;
    }

    private static function send_report(array $report) {
        $response = wp_remote_post(self::api_base() . '/security/report', array(
            'timeout' => 45,
            'headers' => self::headers(),
            'body' => wp_json_encode($report),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['success'])) {
            $message = isset($body['error']) ? $body['error'] : 'HTTP ' . $code;
            return new WP_Error('errorvault_report_rejected', $message);
        }

        return isset($body['data']) ? $body['data'] : array();
    }

    /* ------------------------------------------------------------------
     * The scan
     * ------------------------------------------------------------------ */

    public function __construct($iocs = array()) {
        $defaults = self::default_iocs();
        $this->iocs = array();
        foreach ($defaults as $key => $values) {
            $extra = isset($iocs[$key]) && is_array($iocs[$key]) ? $iocs[$key] : array();
            $this->iocs[$key] = array_values(array_unique(array_merge($values, $extra)));
        }
        $this->ioc_hash_set = array_fill_keys(array_map('strtolower', $this->iocs['sha256']), true);
    }

    /**
     * Built-in IOCs, used even if the portal can't be reached. The portal
     * sends its (possibly newer) list with every scan request.
     */
    public static function default_iocs() {
        return array(
            'admin_login_patterns' => array('^w2s_[0-9a-f]{6,}$', '^wpenginebot$'),
            'admin_email_patterns' => array('^wpenginebot@wpengine\.com$'),
            'plugin_dir_patterns' => array('^fun-proof-[0-9a-f]{6,}$'),
            'content_dir_patterns' => array('^fun-[0-9a-f]{8,}$', '^fun-proof-[0-9a-f]{6,}$'),
            'sha256' => array(
                'd3e34d9306106aca15b1deb6dcfbe169c5f0df470bd22095845d553a60cbfd1e',
                '5588eb0d473bbc104ecb7d41037a747280eba03956a3d4c11368e4f0bd427ead',
                'd4cf7b5d8722236de52e9bad855b4ed4d99f18a561d192aec33525ec89557a7c',
                'd8dfdab3a4358dbcd0eb129494d9e64b8392ef564bdc4cbe73e238c6d3ba51cd',
                '9c1bf6681ca94ab703d4f393fbfdd0acfb081285cd47ea4cf718ff9b71835722',
                '37d86716edcb5b481d5d34b38b3bb4b522fcabdf0baf9b0523a0a61957620fad',
                '4f4dc354dfa3ab9df33107b02106424d9940119363ceaff3399aefa8b14859dc',
                '67ce5c125611078c2a6294faacd378b7dccbfb490641a0ca0822b071a22f759c',
                'ee8395666b9367967749757da27784922fdc18dc3e85db864d30fa703eb9db18',
                '12de8ce21bc534a968c327c00f2aa933b9034bc39b367ad1659f5aaa8be07744',
            ),
        );
    }

    public function scan() {
        $started = microtime(true);
        $this->deadline = $started + (int) apply_filters('errorvault_security_scan_time_limit', 150);

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ignore_user_abort(true);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $wp_version = self::installed_wp_version();

        $admins = $this->collect_admins();
        $plugins = $this->collect_plugins();
        $mu_plugins = $this->collect_mu_plugins();
        $this->check_core_files($wp_version);
        $this->walk_content();
        $uploads = wp_upload_dir(null, false);
        $upload_base = isset($uploads['basedir']) ? rtrim(wp_normalize_path($uploads['basedir']), '/') : '';
        if ($upload_base && $upload_base !== rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') && 0 !== strpos($upload_base, rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') . '/')) {
            if (is_dir($upload_base) && !is_link($upload_base)) { $this->walk_content($upload_base); }
            else { $this->coverage_gap('Custom uploads directory unavailable or linked'); }
        }
        $this->check_root_files();
        $this->check_config();
        $this->check_database();
        $this->check_scheduled_tasks();
        $this->build_file_findings();
        $this->limit_findings();

        foreach ($plugins as &$plugin) {
            if (!empty($this->plugin_modified[$plugin['slug']])) {
                $plugin['modified_files'] = array_slice($this->plugin_modified[$plugin['slug']], 0, 200);
            }
        }
        unset($plugin);

        return array(
            'wp_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'plugin_version' => ERRORVAULT_VERSION,
            'multisite' => is_multisite(),
            'virtual_patch_active' => self::virtual_patch_active(),
            'admins' => $admins,
            'plugins' => $plugins,
            'mu_plugins' => $mu_plugins,
            'findings' => $this->findings,
            'stats' => array(
                'files_scanned' => $this->files_scanned,
                'entries_walked' => $this->entries,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'truncated' => $this->truncated,
                'coverage_gaps' => $this->coverage_gaps,
                'system_cron_scope' => 'readable /etc/crontab and /etc/cron.d only; user crontabs, systemd timers and hosting schedulers not inspected',
                'core_checksums' => $this->core_status,
                'plugins_verified' => count($this->plugin_checksums),
                'remote_actions' => ErrorVault_Security_Actions::enabled(),
            ),
        );
    }

    private function out_of_time() {
        if (microtime(true) > $this->deadline) {
            $this->truncated = true;
            return true;
        }
        return false;
    }

    private function add_finding($key, $category, $check, $status, $indicator, $message, $recommendation = null, $details = null) {
        $finding = array(
            'key' => $key,
            'category' => $category,
            'check' => $check,
            'status' => $status,
            'indicator' => (bool) $indicator,
            'message' => $message,
        );
        if ($recommendation) {
            $finding['recommendation'] = $recommendation;
        }
        if ($details) {
            $finding['details'] = $details;
        }
        foreach ($this->findings as $index => $existing) {
            if ($existing['key'] === $key) {
                $rank = array('critical' => 0, 'warning' => 1, 'info' => 2, 'pass' => 3);
                if ($rank[$status] < $rank[$existing['status']]) { $this->findings[$index] = $finding; }
                return;
            }
        }
        $this->findings[] = $finding;
    }

    /** The report API accepts at most 500 findings; keep strongest evidence first. */
    private function limit_findings() {
        if (count($this->findings) <= 500) { return; }
        $this->coverage_gap('Finding list exceeded report limit; strongest 499 retained');
        $this->findings = array_values(array_filter($this->findings, function ($finding) { return 'files:truncated' !== $finding['key'] && 'files:clean' !== $finding['key']; }));
        $rank = array('critical' => 0, 'warning' => 1, 'info' => 2, 'pass' => 3);
        usort($this->findings, function ($a, $b) use ($rank) { return $rank[$a['status']] <=> $rank[$b['status']]; });
        $this->findings = array_slice($this->findings, 0, 499);
        $this->add_finding('files:truncated', 'files', 'Scan report was limited', 'warning', false,
            'The report reached its finding limit. Strongest findings are retained; further findings require server-side investigation.', null, array('reasons' => $this->coverage_gaps));
    }

    private function relative($path) {
        $path = wp_normalize_path($path);
        $root = wp_normalize_path(ABSPATH);
        return strpos($path, $root) === 0 ? ltrim(substr($path, strlen($root)), '/') : $path;
    }

    private static function matches_any($value, array $patterns) {
        foreach ($patterns as $pattern) {
            $regex = '~' . str_replace('~', '\~', $pattern) . '~i';
            if ('' !== $value && 1 === @preg_match($regex, $value)) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------- Users ------------------------------ */

    /**
     * Everyone with admin-level capabilities. Queried directly so that
     * pre_user_query / users_list_table filters can't hide accounts.
     */
    private function collect_admins() {
        global $wpdb;

        $prefix = is_multisite() ? $wpdb->get_blog_prefix(get_main_site_id()) : $wpdb->prefix;
        $like = function ($cap) use ($wpdb) {
            return '%' . $wpdb->esc_like('"' . $cap . '"') . '%';
        };

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login, u.user_email, u.user_registered, m.meta_value
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
             WHERE m.meta_key = %s AND (m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s)
             ORDER BY u.ID LIMIT 500",
            $prefix . 'capabilities',
            $like('administrator'),
            $like('manage_options'),
            $like('install_plugins')
        ));

        $admins = array();
        $role_admin_ids = array();
        foreach ((array) $rows as $row) {
            $caps = maybe_unserialize($row->meta_value);
            if (!is_array($caps) || (empty($caps['administrator']) && empty($caps['manage_options']) && empty($caps['install_plugins']))) {
                continue;
            }
            if (!empty($caps['administrator'])) {
                $role_admin_ids[] = (int) $row->ID;
            }
            $admins[(int) $row->ID] = $this->admin_entry($row->ID, $row->user_login, $row->user_email, $row->user_registered);
        }

        if (is_multisite()) {
            foreach ((array) get_super_admins() as $login) {
                $user = get_user_by('login', $login);
                if ($user && !isset($admins[$user->ID])) {
                    $admins[$user->ID] = $this->admin_entry($user->ID, $user->user_login, $user->user_email, $user->user_registered);
                }
            }
        }

        // Admins present in the database but missing from a normal user query are being hidden.
        $visible = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1000, 'blog_id' => is_multisite() ? get_main_site_id() : get_current_blog_id()));
        $hidden = array_diff($role_admin_ids, array_map('intval', (array) $visible));
        if (!empty($hidden)) {
            $logins = array();
            foreach ($hidden as $id) {
                $logins[] = $admins[$id]['login'];
            }
            $this->add_finding(
                'users:hidden_admins',
                'users',
                'Administrator accounts hidden from the Users screen',
                'critical',
                true,
                sprintf('%d administrator(s) exist in the database but are filtered out of the normal user list: %s. Legitimate plugins do not do this.', count($hidden), implode(', ', $logins)),
                'Delete these accounts with WP-CLI or phpMyAdmin, then find and remove the code that hides them (usually a mu-plugin or theme functions.php).'
            );
        }

        return array_values($admins);
    }

    private function admin_entry($id, $login, $email, $registered) {
        $last_login = null;
        $last_ip = null;

        $meta = get_user_meta($id, self::LAST_LOGIN_META, true);
        if (is_array($meta) && !empty($meta['time'])) {
            $last_login = (int) $meta['time'];
            $last_ip = isset($meta['ip']) ? $meta['ip'] : null;
        }

        $sessions = get_user_meta($id, 'session_tokens', true);
        if (is_array($sessions)) {
            foreach ($sessions as $session) {
                if (!empty($session['login']) && (int) $session['login'] > (int) $last_login) {
                    $last_login = (int) $session['login'];
                    $last_ip = isset($session['ip']) ? $session['ip'] : $last_ip;
                }
            }
        }

        return array(
            'id' => (int) $id,
            'login' => (string) $login,
            'email' => (string) $email,
            'registered' => (string) $registered, // stored in UTC
            'last_login' => $last_login ? gmdate('Y-m-d H:i:s', $last_login) : null,
            'last_login_ip' => $last_ip ? (string) $last_ip : null,
        );
    }

    /* --------------------------- Plugins ----------------------------- */

    private function collect_plugins() {
        $all = get_plugins();
        $active = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }

        $plugins = array();
        $known_dirs = array();
        $fetch_budget = microtime(true) + ($this->deadline - microtime(true)) * 0.35;

        foreach ($all as $file => $data) {
            $dir = dirname($file);
            $single = ('.' === $dir);
            $slug = $single ? basename($file, '.php') : $dir;
            $path = WP_PLUGIN_DIR . '/' . ($single ? $file : $dir);
            $known_dirs[$single ? $file : $dir] = true;

            $entry = array(
                'slug' => $slug,
                'file' => $file,
                'name' => wp_strip_all_tags($data['Name']),
                'version' => (string) $data['Version'],
                'active' => in_array($file, $active, true),
                'installed_at' => @filemtime($path) ? gmdate('Y-m-d H:i:s', filemtime($path)) : null,
                'wporg' => null,
            );

            if (!$single && microtime(true) < $fetch_budget) {
                $entry['wporg'] = $this->load_plugin_checksums($slug, $entry['version']);
            }

            $plugins[] = $entry;
        }

        // Folders in wp-content/plugins that WordPress doesn't consider a plugin.
        $orphans = array();
        foreach ((array) @scandir(WP_PLUGIN_DIR) as $name) {
            if ('.' === $name || '..' === $name || 'index.php' === $name || isset($known_dirs[$name])) {
                continue;
            }
            $full = WP_PLUGIN_DIR . '/' . $name;
            if ((is_dir($full) && $this->dir_has_php($full)) || (is_file($full) && preg_match('/\.ph(p\d?|tml|ar)$/i', $name))) {
                $orphans[] = 'wp-content/plugins/' . $name;
            }
        }
        if (!empty($orphans)) {
            $this->add_finding(
                'plugins:orphans:' . md5(implode('|', $orphans)),
                'plugins',
                'PHP code in the plugins folder that is not a plugin',
                'warning',
                true,
                sprintf('%d item(s) in wp-content/plugins contain PHP but have no plugin header, so they never appear under Plugins. Backdoors often hide this way.', count($orphans)),
                'Inspect and delete anything you do not recognise.',
                array('files' => array_slice($orphans, 0, self::MAX_LIST))
            );
        }

        return $plugins;
    }

    private function dir_has_php($dir) {
        $count = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (++$count > 2000) {
                    return true;
                }
                if (preg_match('/\.ph(p\d?|tml|ar)$/i', $file->getFilename())) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }

    /**
     * Fetch official checksums for a WordPress.org plugin release.
     * Returns true (on .org), false (not on .org) or null (unknown).
     */
    private function load_plugin_checksums($slug, $version) {
        if (!preg_match('/^[a-z0-9._-]+$/i', $slug) || !preg_match('/^[0-9a-z._-]+$/i', $version)) {
            return null;
        }

        $response = wp_remote_get(
            'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($slug) . '/' . rawurlencode($version) . '.json',
            array('timeout' => 10)
        );

        if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['files']) && is_array($body['files'])) {
                $map = array();
                foreach ($body['files'] as $path => $hashes) {
                    $map[$path] = isset($hashes['md5']) ? (array) $hashes['md5'] : array();
                }
                $this->plugin_checksums[$slug] = $map;
                return true;
            }
        }

        // No checksums for this version; is the plugin on WordPress.org at all?
        $cache_key = 'ev_wporg_' . md5($slug);
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return 'yes' === $cached;
        }

        $info = wp_remote_get(
            'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode($slug) . '&request[fields][sections]=0',
            array('timeout' => 10)
        );
        if (is_wp_error($info)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($info), true);
        if (!is_array($data)) {
            return null;
        }
        $on_org = !empty($data['slug']) && empty($data['error']);
        set_transient($cache_key, $on_org ? 'yes' : 'no', WEEK_IN_SECONDS);

        return $on_org;
    }

    private function collect_mu_plugins() {
        $mu = array();
        if (!is_dir(WPMU_PLUGIN_DIR)) {
            return $mu;
        }

        $headers = get_mu_plugins();
        foreach ((array) @scandir(WPMU_PLUGIN_DIR) as $name) {
            $full = WPMU_PLUGIN_DIR . '/' . $name;
            if ('.' === $name || '..' === $name || !is_file($full) || !preg_match('/\.php$/i', $name)) {
                continue;
            }
            $mu[] = array(
                'file' => $name,
                'name' => isset($headers[$name]['Name']) ? wp_strip_all_tags($headers[$name]['Name']) : '',
                'installed_at' => gmdate('Y-m-d H:i:s', (int) @filemtime($full)),
            );
        }
        return $mu;
    }

    /* ----------------------------- Core ------------------------------ */

    private function check_core_files($wp_version) {
        $locale = self::installed_wp_package_locale();
        $checksums = get_core_checksums($wp_version, $locale);
        if (!is_array($checksums) && 'en_US' !== $locale) {
            $checksums = get_core_checksums($wp_version, 'en_US');
        }

        if (!is_array($checksums) || empty($checksums)) {
            $this->core_status = 'unavailable';
            $this->coverage_gap('Official core checksums unavailable');
            $this->add_finding('core:checksums_unavailable', 'core', 'Core file verification', 'info', false,
                'Could not download official checksums for WordPress ' . $wp_version . ' from api.wordpress.org, so core files were not verified.');
            return;
        }

        $this->core_checksums = $checksums;
        $optional = array('readme.html', 'license.txt', 'wp-config-sample.php', 'licencia.txt', 'liesmich.html');
        $modified = array();
        $missing = array();
        $checked = 0;

        foreach ($checksums as $file => $md5) {
            // Bundled themes/plugins are handled separately and may legitimately be removed or updated.
            if (0 === strpos($file, 'wp-content/')) {
                continue;
            }
            $full = ABSPATH . $file;
            if (!file_exists($full)) {
                if (!in_array($file, $optional, true)) {
                    $missing[] = $file;
                }
                continue;
            }
            $hash = @md5_file($full);
            if (false === $hash) {
                $this->coverage_gap('Core file unreadable: ' . $file);
                continue;
            }
            $checked++;
            if (!in_array($hash, (array) $md5, true)) {
                // version.php differs between language packages; only flag real code in it.
                if ('wp-includes/version.php' === $file && self::version_php_is_benign($full)) {
                    continue;
                }
                $modified[] = $file;
            }
            if (0 === $checked % 50 && $this->out_of_time()) {
                break;
            }
        }
        $this->files_scanned += $checked;

        // Unknown PHP files inside wp-admin and wp-includes.
        $unknown = array();
        foreach (array('wp-admin', WPINC) as $core_dir) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH . $core_dir, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if (++$this->entries > self::MAX_ENTRIES || $this->out_of_time()) { $this->coverage_gap('Core directory walk limit reached'); break 2; }
                    if ($file->isLink()) { $this->coverage_gap('Core symlink not followed'); continue; }
                    if (!self::php_filename($file->getFilename())) {
                        continue;
                    }
                    $rel = $this->relative($file->getPathname());
                    if (!isset($checksums[$rel])) {
                        $unknown[] = $rel;
                        $this->inspect_file($file->getPathname(), $rel, $file->getSize(), false);
                    }
                }
            } catch (Exception $e) {
                $this->coverage_gap('Core directory unreadable: ' . $core_dir);
            }
        }

        if ($this->truncated && 'unavailable' === $this->core_status) { $this->core_status = 'partial'; }
        if (!empty($modified)) {
            $this->core_status = 'mismatch';
            $this->add_finding('core:modified', 'core', 'Modified WordPress core files', 'critical', true,
                sprintf('%d core file(s) differ from the official WordPress %s release. Attackers modify core files to hide backdoors.', count($modified), $wp_version),
                'Reinstall WordPress core (Dashboard, Updates, Re-install version) and check the listed files for injected code first.',
                array('files' => array_slice($modified, 0, self::MAX_LIST)));
        } elseif (!$this->truncated && empty($missing)) {
            $this->core_status = 'verified';
            $this->add_finding('core:verified', 'core', 'Core files match official checksums', 'pass', false,
                sprintf('%s core files verified against WordPress.org.', number_format_i18n($checked)));
        }

        if (!empty($unknown)) {
            $this->add_finding('core:unknown:' . md5(implode('|', $unknown)), 'core', 'Unknown PHP files in wp-admin / wp-includes', 'warning', true,
                sprintf('%d PHP file(s) in core folders are not part of WordPress %s. These are often backdoors named to look like core files.', count($unknown), $wp_version),
                'Delete these files unless your host put them there, then reinstall core.',
                array('files' => array_slice($unknown, 0, self::MAX_LIST)));
        }

        if (!empty($missing)) {
            $this->coverage_gap('Required core files missing');
            $this->add_finding('core:missing', 'core', 'Missing WordPress core files', 'info', false,
                sprintf('%d required core file(s) are missing. Restore them from the official release and investigate unexpected deletion.', count($missing)),
                null, array('files' => array_slice($missing, 0, self::MAX_LIST)));
        }
    }

    private function check_root_files() {
        $unknown = array();
        $names = @scandir(ABSPATH);
        if (false === $names) { $this->coverage_gap('WordPress root unreadable'); return; }
        foreach ($names as $name) {
            if ($this->out_of_time()) { break; }
            if (!self::php_filename($name)) { continue; }
            $full = ABSPATH . $name;
            if (!is_file($full)) { continue; }
            if (is_link($full)) { $this->coverage_gap('Root symlink not followed: ' . $name); continue; }
            $size = (int) @filesize($full);
            $recognized = $this->recognized_root_file($full, $name, $size);
            // Known root names (including firewall/config files) never bypass inspection.
            $core_verified = isset($this->core_checksums[$name]) && in_array(@md5_file($full), (array) $this->core_checksums[$name], true);
            if (!$recognized && !$core_verified) { $this->inspect_file($full, $name, $size, true); }
            $core = is_array($this->core_checksums) && isset($this->core_checksums[$name]);
            $config = in_array($name, array('wp-config.php', 'local-config.php', 'wp-config-local.php'), true);
            if (!$recognized && !$core && !$config) { $unknown[] = $name; }
        }
        if (!empty($unknown)) {
            $this->add_finding('root:unknown:' . md5(implode('|', $unknown)), 'files', 'Unverified PHP files in the WordPress root', 'warning', true,
                sprintf('%d root file(s) are not verified as WordPress core or a recognized tool. Custom loaders can be legitimate; their names alone do not establish safety.', count($unknown)),
                'Review contents and provenance before changing files. Removing a configured firewall bootstrap can take the site offline.',
                array('files' => array_slice($unknown, 0, self::MAX_LIST)));
        }
    }

    private static function php_filename($name) {
        return (bool) preg_match('/\.(?:php[0-9]*|phtml|phar|pht|inc)(?:\.|$)/i', $name);
    }

    private function coverage_gap($reason) {
        $this->truncated = true;
        if (count($this->coverage_gaps) < self::MAX_LIST && !in_array($reason, $this->coverage_gaps, true)) {
            $this->coverage_gaps[] = $reason;
        }
    }

    private function recognized_root_file($path, $name, $size) {
        if (isset($this->recognized_files[$path])) { return $this->recognized_files[$path]; }
        $recognized = false;
        $verification_reason = null;
        if (in_array($name, array('aios-bootstrap.php', 'aios.bootstrap.php'), true) && $size <= 16384) {
            $uploads = wp_upload_dir(null, false);
            $target = 'classes/firewall/wp-security-firewall.php';
            $hashes = isset($this->plugin_checksums['all-in-one-wp-security-and-firewall'][$target])
                ? $this->plugin_checksums['all-in-one-wp-security-and-firewall'][$target] : array();
            $target_path = WP_PLUGIN_DIR . '/all-in-one-wp-security-and-firewall/' . $target;
            $content = @file_get_contents($path);
            $verification_reason = empty($hashes) ? 'Official checksums for the installed firewall plugin are unavailable.' : 'The loader template or firewall target does not match the supported official version.';
            $recognized = false !== $content && !is_link($target_path) && is_file($target_path)
                && in_array(@md5_file($target_path), $hashes, true)
                && ErrorVault_Security_Evidence::aios_bootstrap($content, wp_normalize_path(ABSPATH), wp_normalize_path(WP_PLUGIN_DIR), wp_normalize_path($uploads['basedir']));
        } elseif ('wordfence-waf.php' === $name && $size <= 16384) {
            $target = 'waf/bootstrap.php';
            $hashes = isset($this->plugin_checksums['wordfence'][$target]) ? $this->plugin_checksums['wordfence'][$target] : array();
            $target_path = WP_PLUGIN_DIR . '/wordfence/' . $target;
            $content = @file_get_contents($path);
            $verification_reason = empty($hashes) ? 'Official checksums for the installed firewall plugin are unavailable.' : 'The loader template or firewall target does not match the supported official version.';
            $recognized = false !== $content && !is_link($target_path) && is_file($target_path)
                && in_array(@md5_file($target_path), $hashes, true)
                && ErrorVault_Security_Evidence::wordfence_bootstrap($content, wp_normalize_path(ABSPATH), wp_normalize_path(WP_PLUGIN_DIR), wp_normalize_path(WP_CONTENT_DIR));
        } elseif ('wp-cli.phar' === $name && $size > 0 && $size <= 32 * 1024 * 1024) {
            // Full-file digest from the official release channel. Never execute/open a PHAR.
            if (null === $this->wp_cli_checksums) {
                $this->wp_cli_checksums = array_values(ErrorVault_Security_Evidence::wp_cli_release_hashes());
                $cached = get_transient('errorvault_wp_cli_sha512');
                if (is_string($cached) && preg_match('/^[a-f0-9]{128}$/D', $cached)) {
                    $this->wp_cli_checksums[] = $cached;
                } else {
                    $response = wp_remote_get('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.sha512', array('timeout' => 10, 'redirection' => 0, 'limit_response_size' => 1024));
                    if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
                        $checksum = trim(wp_remote_retrieve_body($response));
                        if (preg_match('/^[a-f0-9]{128}$/D', $checksum)) {
                            $this->wp_cli_checksums[] = $checksum;
                            set_transient('errorvault_wp_cli_sha512', $checksum, DAY_IN_SECONDS);
                        }
                    }
                }
            }
            $digest = @hash_file('sha512', $path);
            $recognized = in_array($digest, $this->wp_cli_checksums, true);
            $verification_reason = $digest ? 'The full-file SHA-512 does not match the supported official releases. SHA-512: ' . $digest : 'The file could not be read to calculate its SHA-512.';
        }
        if (!$recognized && $verification_reason) {
            $this->add_finding('files:verification:' . md5($path), 'files', 'Tool verification incomplete: ' . $name, 'info', false,
                $verification_reason . ' This is not proof of malware.', 'Compare with the original tool or plugin package before marking this file as recognised.');
        }
        $this->recognized_files[$path] = $recognized;
        if ($recognized) {
            $this->files_scanned++;
            $this->add_finding('files:recognized:' . md5($path), 'files', 'Verified tool: ' . $name, 'info', false,
                'Verified against the official WP-CLI checksum or a complete firewall loader template with a checksum-verified firewall target. This verifies this file, not the whole site.');
        }
        return $recognized;
    }

    /* ------------------------- wp-content walk ----------------------- */

    private function walk_content($base = null) {
        $base = null === $base ? WP_CONTENT_DIR : $base;
        $skip = apply_filters('errorvault_security_scan_skip_dirs', array(
            'node_modules', '.git', 'upgrade-temp-backup', 'updraft', 'ai1wm-backups', 'backups-dup-lite', 'errorvault-backups',
        ));
        $uploads = wp_upload_dir(null, false);
        $uploads_base = wp_normalize_path(isset($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads');
        $plugins_base = wp_normalize_path(WP_PLUGIN_DIR);
        $content_patterns = $this->iocs['content_dir_patterns'];

        try {
            $dir = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) use ($skip) {
                if (!$current->isDir()) {
                    return true;
                }
                if ($current->isLink() || !is_readable($current->getPathname())) { $this->coverage_gap('Directory unreadable or linked: ' . $this->relative($current->getPathname())); return false; }
                $name = $current->getFilename();
                $skipped = in_array($name, $skip, true) || 0 === strpos($name, 'errorvault-quarantine');
                if ($skipped) { $this->coverage_gap('Excluded directory: ' . $this->relative($current->getPathname())); }
                return !$skipped;
            });
            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        } catch (Exception $e) {
            $this->coverage_gap('Content directory unreadable');
            return;
        }

        foreach ($it as $file) {
            if (++$this->entries > self::MAX_ENTRIES || (0 === $this->entries % 200 && $this->out_of_time())) {
                $this->truncated = true;
                break;
            }

            $path = wp_normalize_path($file->getPathname());

            if ($file->isDir()) {
                if (self::matches_any($file->getFilename(), $content_patterns)) {
                    $this->ioc_dir_hits[] = $this->relative($path);
                }
                continue;
            }

            if ($file->isLink()) { $this->coverage_gap('Symlink not followed: ' . $this->relative($path)); continue; }

            $name = $file->getFilename();
            $rel = $this->relative($path);
            $size = (int) $file->getSize();
            $is_php = self::php_filename($name);
            $in_uploads = 0 === strpos($path, $uploads_base . '/');

            if ($in_uploads && '.htaccess' === strtolower($name) && $size < 65536) {
                $htaccess = (string) @file_get_contents($path);
                if (preg_match('/^\s*(?:AddHandler|SetHandler|AddType)\b[^\n]*(?:php|x-httpd)/im', $htaccess)) {
                    $this->htaccess_uploads[] = $rel;
                }
                continue;
            }

            // Verify files of WordPress.org plugins against their official checksums.
            $verified = false;
            if (0 === strpos($path, $plugins_base . '/')) {
                $inner = substr($path, strlen($plugins_base) + 1);
                $slash = strpos($inner, '/');
                if (false !== $slash) {
                    $slug = substr($inner, 0, $slash);
                    $plugin_rel = substr($inner, $slash + 1);
                    if (isset($this->plugin_checksums[$slug])) {
                        $this->files_scanned++;
                        if (isset($this->plugin_checksums[$slug][$plugin_rel])) {
                            $hash = @md5_file($path);
                            if (false !== $hash && in_array($hash, $this->plugin_checksums[$slug][$plugin_rel], true)) {
                                $verified = true;
                            } elseif (false !== $hash) {
                                $this->plugin_modified[$slug][] = $plugin_rel;
                            }
                        } elseif ($is_php) {
                            $this->plugin_unexpected[] = $rel;
                        }
                    }
                }
            } elseif (is_array($this->core_checksums) && isset($this->core_checksums[$rel])) {
                // Bundled default themes / Akismet / Hello Dolly.
                $verified = in_array(md5_file($path), (array) $this->core_checksums[$rel], true);
            }

            if (!$is_php) {
                continue;
            }

            if ($file->getMTime() > time() - 7 * DAY_IN_SECONDS && count($this->recent_php) < self::MAX_LIST) {
                $this->recent_php[] = $rel;
            }

            $upload_data = $in_uploads && $size <= self::MAX_READ_BYTES
                && ErrorVault_Security_Evidence::generated_upload_data((string) @file_get_contents($path), substr($path, strlen($uploads_base) + 1));
            if ($in_uploads && !$upload_data && !$this->is_silence_index($path, $name, $size)) {
                if (count($this->php_in_uploads) < self::MAX_LIST) { $this->php_in_uploads[] = $rel; }
                else { $this->coverage_gap('Uploads finding list capped'); }
            }

            if (!$verified) {
                $this->inspect_file($path, $rel, $size, true);
            }
        }
    }

    private function is_silence_index($path, $name, $size) {
        if ($size > self::MAX_READ_BYTES) { return false; }
        $content = @file_get_contents($path);
        return false !== $content && ErrorVault_Security_Evidence::inert_php($content);
    }

    /**
     * Hash + signature check for a single PHP file.
     */
    private function inspect_file($path, $rel, $size, $count = true) {
        if (isset($this->inspected_paths[$path])) { return; }
        $this->inspected_paths[$path] = true;
        if ($size > self::MAX_READ_BYTES) {
            $this->coverage_gap('File exceeds content scan limit: ' . $rel);
            return;
        }

        $content = @file_get_contents($path, false, null, 0, self::MAX_READ_BYTES + 1);
        if (false === $content) { $this->coverage_gap('File unreadable: ' . $rel); return; }
        if (strlen($content) > self::MAX_READ_BYTES) { $this->coverage_gap('File grew beyond content scan limit: ' . $rel); return; }
        if ($count) { $this->files_scanned++; }
        if ('' === $content) { return; }

        if (isset($this->ioc_hash_set[hash('sha256', $content)])) {
            $this->ioc_hash_hits[] = $rel;
        }

        $hit = self::match_signatures($content, $size);
        if ($hit && count($this->signature_hits[$hit[0]]) >= self::MAX_LIST) { $this->coverage_gap('Signature finding list capped'); }
        if ($hit && count($this->signature_hits[$hit[0]]) < self::MAX_LIST) {
            $this->signature_hits[$hit[0]][] = array('path' => $rel, 'reason' => $hit[1]);
        }
    }

    /**
     * Conservative webshell / backdoor signatures.
     * Returns array(severity, reason) or null.
     */
    public static function match_signatures($content, $size) {
        if (ErrorVault_Security_Evidence::inert_php($content)) { return null; }
        $executable = ErrorVault_Security_Evidence::executable_text($content);
        $critical = array(
            'eval() of a decoded payload' => '/\beval\s*\(\s*(?:@\s*)?(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode)\s*\(/i',
            'runs shell commands from request input' => '/\b(?:system|exec|passthru|shell_exec|popen|proc_open|pcntl_exec)\s*\(\s*(?:@\s*)?(?:(?:stripslashes|base64_decode|trim|urldecode)\s*\(\s*)?\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b/i',
            'evaluates request input as code' => '/\b(?:eval|assert)\s*\(\s*(?:@\s*)?(?:(?:stripslashes|base64_decode|urldecode)\s*\(\s*)?\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b/i',
            'calls a function named in the request' => '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]{1,64}\]\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i',
            'create_function() with request input' => '/create_function\s*\([^)]{0,60}\$_(?:GET|POST|REQUEST|COOKIE)/i',
        );
        foreach ($critical as $reason => $regex) {
            $subject = $executable;
            if (@preg_match($regex, $subject)) {
                return array('critical', $reason);
            }
        }

        $tokens = ErrorVault_Security_Evidence::tokens($content);
        foreach ($tokens as $index => $token) {
            if (is_array($token) && in_array($token[0], array(T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE), true)) {
                for ($j = $index + 1; $j < min(count($tokens), $index + 16); $j++) {
                    $next = $tokens[$j];
                    if (';' === $next) { break; }
                    if (!is_array($next) || T_CONSTANT_ENCAPSED_STRING !== $next[0]) { continue; }
                    if (preg_match('/(?:\\\\x[0-9a-fA-F]{2}){4,}/', $next[1])) { return array('warning', 'includes a hex-encoded path'); }
                    if (preg_match('/\.(?:ico|png|jpe?g|gif|txt|log)["\']$/i', $next[1])) { return array('warning', 'includes a non-PHP file (image/text)'); }
                }
            }
        }
        $comment_free = '';
        foreach ($tokens as $token) { $comment_free .= is_array($token) ? $token[1] : $token; }
        $warning = array(
            'webshell marker (may be a security signature database)' => '/\b(?:FilesMan|WSOsetcookie|b374k|r57shell|c99shell|IndoXploit|AnonymousFox)\b/i',
            'long hex-escaped string' => '/(?:\\\\x[0-9a-fA-F]{2}){40,}/',
        );
        foreach ($warning as $reason => $regex) {
            $subject = 0 === strpos($reason, 'webshell marker') ? $executable : $comment_free;
            if (@preg_match($regex, $subject)) {
                return array('warning', $reason);
            }
        }

        if (preg_match('/\b(?:eval|assert|create_function)\s*\(/i', $executable) && preg_match('/[A-Za-z0-9+\/=]{3000,}/', $content)) {
            return array('warning', 'eval() alongside a large encoded blob');
        }

        if ($size < 6000 && false !== stripos($executable, 'move_uploaded_file') && false !== strpos($executable, '$_FILES') && false === strpos($content, 'wp_')) {
            return array('warning', 'standalone file uploader');
        }

        return null;
    }

    /* ------------------------- Configuration ------------------------- */

    private function check_config() {
        // auto_prepend_file in .user.ini / php.ini / .htaccess is a classic persistence trick.
        foreach (array('.user.ini', 'php.ini', '.htaccess') as $name) {
            $full = ABSPATH . $name;
            if (!is_file($full) || filesize($full) > 262144) {
                continue;
            }
            $content = (string) @file_get_contents($full);
            $content = preg_replace('/^\s*[;#].*$/m', '', $content);
            if (preg_match_all('/(auto_(?:prepend|append)_file)\s*[= ]\s*["\']?([^"\'\r\n]+)/i', $content, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $target = trim($match[2]);
                    $candidate = $target;
                    if (false === strpos($candidate, '://') && !preg_match('~^(?:/|[A-Za-z]:[\\/])~', $candidate)) { $candidate = ABSPATH . $candidate; }
                    $real = realpath($candidate);
                    $is_benign = $real && in_array(basename($candidate), array('aios-bootstrap.php', 'aios.bootstrap.php', 'wordfence-waf.php'), true)
                        && $real === realpath(ABSPATH . basename($candidate))
                        && $this->recognized_root_file($real, basename($candidate), (int) @filesize($real));
                    if ('none' === strtolower($target) || '' === $target) {
                        continue;
                    }
                    $this->add_finding('config:prepend:' . md5($name . $target), 'config', $match[1] . ' set in ' . $name,
                        $is_benign ? 'info' : 'warning', !$is_benign,
                        sprintf('%s makes PHP load "%s" for every request.%s', $name, $target, $is_benign ? ' The complete firewall loader and its target were verified.' : ' Malware uses this to survive cleanup.'),
                        $is_benign ? null : 'Review the target contents and plugin provenance. A firewall name is not proof of safety; do not remove a bootstrap without checking its configuration.');
                }
            }
        }

        // wp-config.php should never contain obfuscated code.
        $config = file_exists(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(ABSPATH) . '/wp-config.php';
        if (is_file($config) && filesize($config) < self::MAX_READ_BYTES) {
            $hit = self::match_signatures((string) @file_get_contents($config), filesize($config));
            if ($hit) {
                $this->add_finding('config:wpconfig', 'config', 'Suspicious code in wp-config.php', 'critical' === $hit[0] ? 'critical' : 'warning', true,
                    'wp-config.php contains code that ' . $hit[1] . '.',
                    'Compare wp-config.php with a known-good copy and remove the injected code, then rotate the database password and salts.');
            }
        }

        // Open registration straight into an admin-level role.
        $default_role = get_option('default_role');
        $role = $default_role ? get_role($default_role) : null;
        if (get_option('users_can_register') && $role && ($role->has_cap('manage_options') || $role->has_cap('install_plugins') || $role->has_cap('edit_users'))) {
            $this->add_finding('config:open_admin_registration', 'config', 'Anyone can register as ' . $default_role, 'critical', true,
                'Registration is open and new users get the "' . $default_role . '" role, which can manage the site. Attackers flip these settings to keep access.',
                'Settings, General: untick "Anyone can register" and set the default role to Subscriber.');
        }

        // Core auto-updates: the forced wp2shell update failed on sites that disabled them.
        $core_updates_off = (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED)
            || (defined('WP_AUTO_UPDATE_CORE') && false === WP_AUTO_UPDATE_CORE);
        if ($core_updates_off) {
            $this->add_finding('config:auto_updates_off', 'config', 'Core security auto-updates are disabled', 'warning', false,
                'AUTOMATIC_UPDATER_DISABLED or WP_AUTO_UPDATE_CORE turns off automatic security releases, including the emergency wp2shell fix.',
                "Remove the constant from wp-config.php, or set WP_AUTO_UPDATE_CORE to 'minor'.");
        }

        if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
            $this->add_finding('config:file_edit', 'config', 'Theme/plugin file editor is enabled', 'info', false,
                'Anyone with an admin login can edit PHP files from wp-admin, which turns a stolen password into code execution.',
                "Add define('DISALLOW_FILE_EDIT', true); to wp-config.php.");
        }
    }

    /* --------------------------- Database ---------------------------- */

    /**
     * Injected scripts in posts/options, hijacked active_plugins entries and
     * cron events carrying code. Reported only; content is cleaned by hand.
     */
    private function check_database() {
        global $wpdb;

        // active_plugins entries pointing outside wp-content/plugins load arbitrary PHP on every request.
        $missing = array();
        $hijacked = array();
        foreach ((array) get_option('active_plugins', array()) as $entry) {
            $entry = (string) $entry;
            if (false !== strpos($entry, '..') || !preg_match('/\.php$/i', $entry)) {
                $hijacked[] = $entry;
            } elseif (!file_exists(WP_PLUGIN_DIR . '/' . $entry)) {
                $missing[] = $entry;
            }
        }
        if (!empty($hijacked)) {
            $this->add_finding('db:active_plugins:' . md5(implode('|', $hijacked)), 'database', 'Hijacked active_plugins setting', 'critical', true,
                'The active_plugins option loads files from outside wp-content/plugins, a known trick for running a backdoor on every request.',
                'Remove these entries from the active_plugins option (wp option get active_plugins) and delete the files they point to.',
                array('files' => $hijacked));
        }
        if (!empty($missing)) {
            $this->add_finding('db:active_plugins_missing', 'database', 'Active plugins that no longer exist', 'info', false,
                'These entries point at plugins that were deleted. Harmless; WordPress clears them when you open the Plugins screen.',
                null, array('files' => $missing));
        }

        $js = '/eval\s*\(\s*(?:atob|unescape|decodeURIComponent|String\.fromCharCode)|document\.write\s*\(\s*(?:unescape|atob)|String\.fromCharCode\s*\((?:\s*\d+\s*,){30,}|<script[^>]+src=["\']?https?:\/\/\d{1,3}(?:\.\d{1,3}){3}|\beval\s*\(\s*(?:base64_decode|gzinflate)/i';
        $items = array();

        $posts = $wpdb->get_results(
            "SELECT ID, post_type, post_title, post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','private','future','pending')
               AND post_type NOT IN ('revision','customize_changeset','oembed_cache')
               AND (post_content LIKE '%<script%' OR post_content LIKE '%eval(%' OR post_content LIKE '%fromCharCode%')
             LIMIT 500"
        );
        if (null === $posts || count($posts) >= 500) { $this->coverage_gap('Database post scan unavailable or capped'); }
        foreach ((array) $posts as $post) {
            if (count($items) < self::MAX_LIST && preg_match($js, $post->post_content, $m)) {
                $items[] = array('type' => 'post', 'id' => (int) $post->ID, 'title' => wp_strip_all_tags($post->post_title) . ' (' . $post->post_type . ')', 'reason' => substr($m[0], 0, 80));
            }
        }

        $options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name <> 'cron' AND option_name NOT LIKE '%\\_transient\\_%'
               AND (option_value LIKE '%<script%' OR option_value LIKE '%eval(%' OR option_value LIKE '%fromCharCode%' OR option_value LIKE '%base64_decode%')
             LIMIT 500"
        );
        if (null === $options || count($options) >= 500) { $this->coverage_gap('Database option scan unavailable or capped'); }
        foreach ((array) $options as $option) {
            if (count($items) < self::MAX_LIST && preg_match($js, $option->option_value, $m)) {
                $items[] = array('type' => 'option', 'id' => $option->option_name, 'title' => $option->option_name, 'reason' => substr($m[0], 0, 80));
            }
        }

        if (!empty($items)) {
            $this->add_finding('db:injected:' . md5(wp_json_encode($items)), 'database', 'Review script patterns in the database', 'warning', true,
                sprintf('%d post(s)/setting(s) contain obfuscated JavaScript or PHP typical of malware injections (visitor redirects, spam, card skimmers).', count($items)),
                'Review each item in context. Code examples and security-plugin data can match these patterns; remove only confirmed injections.',
                array('items' => $items));
        }

    }

    /** Inspect scheduled metadata and callbacks without invoking them. */
    private function check_scheduled_tasks($system_paths = null) {
        global $wp_filter;
        $count = 0;
        $events_checked = 0; $callbacks_checked = 0; $system_files_checked = 0;
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                foreach ((array) $events as $event) {
                    if (++$count > 2000 || $this->out_of_time()) { $this->coverage_gap('WP-Cron inspection limit reached'); break 3; }
                    $events_checked++;
                    $reason = ErrorVault_Security_Evidence::cron_argument_reason(isset($event['args']) ? $event['args'] : array());
                    if ($reason) {
                        $this->add_finding('cron:args:' . md5($hook), 'database', 'Review scheduled task: ' . substr($hook, 0, 160), 'warning', true,
                            'WP-Cron ' . $reason . '. Stored text can be legitimate job data; this alone does not prove code execution.',
                            'Review the scheduling plugin, callback and arguments before removing the event.', array('hook' => substr($hook, 0, 160), 'next_run' => (int) $timestamp, 'reason' => $reason));
                    }
                }
                $callbacks = isset($wp_filter[$hook]) && isset($wp_filter[$hook]->callbacks) ? $wp_filter[$hook]->callbacks : array();
                foreach ($callbacks as $group) {
                    foreach ($group as $entry) {
                        if (++$count > 4000 || $this->out_of_time()) { $this->coverage_gap('WP-Cron callback limit reached'); break 3; }
                        $callbacks_checked++;
                        $callback = isset($entry['function']) ? $entry['function'] : null;
                        $reason = null;
                        if (is_string($callback) && in_array(strtolower(ltrim($callback, '\\')), array('system', 'exec', 'shell_exec', 'passthru', 'assert', 'unserialize'), true)) {
                            $reason = 'directly invokes a command/code or deserialization function';
                        }
                        try {
                            if (is_array($callback) && count($callback) === 2) {
                                // Do not autoload classes during inspection.
                                $class = $callback[0];
                                $ref = (is_object($class) || (is_string($class) && class_exists($class, false))) ? new ReflectionMethod($class, $callback[1]) : null;
                            } elseif (is_string($callback) && false !== strpos($callback, '::')) {
                                list($class, $method) = explode('::', $callback, 2);
                                $ref = class_exists($class, false) ? new ReflectionMethod($class, $method) : null;
                            } elseif (is_object($callback) && !($callback instanceof Closure) && method_exists($callback, '__invoke')) {
                                $ref = new ReflectionMethod($callback, '__invoke');
                            } elseif ($callback instanceof Closure || (is_string($callback) && function_exists($callback))) { $ref = new ReflectionFunction($callback); }
                            else { $ref = null; }
                            $file = $ref ? $ref->getFileName() : false;
                            $uploads = wp_upload_dir(null, false);
                            if ($file && 0 === strpos(wp_normalize_path($file), rtrim(wp_normalize_path($uploads['basedir']), '/') . '/')) { $reason = 'loads a callback from the uploads directory'; }
                            if ($file && is_file($file)) {
                                $rel = $this->relative($file);
                                $verified = isset($this->core_checksums[$rel]) && in_array(@md5_file($file), (array) $this->core_checksums[$rel], true);
                                if (!$verified) { $this->inspect_file($file, $rel, (int) @filesize($file)); }
                            }
                        } catch (ReflectionException $e) { $this->coverage_gap('Could not inspect a WP-Cron callback'); }
                        if ($reason) {
                            $this->add_finding('cron:callback:' . md5($hook . $reason), 'database', 'Review scheduled callback: ' . substr($hook, 0, 160), 'warning', true,
                                'This WP-Cron event ' . $reason . '.', 'Verify the callback belongs to an intended plugin or maintenance task.', array('hook' => substr($hook, 0, 160), 'reason' => $reason));
                        }
                    }
                }
            }
        }
        $paths = null === $system_paths ? array_merge(array('/etc/crontab'), (array) glob('/etc/cron.d/*')) : $system_paths;
        foreach (array_slice($paths, 0, 100) as $path) {
            if ($this->out_of_time()) { $this->coverage_gap('System cron inspection time limit reached'); break; }
            if (!is_file($path)) { continue; }
            if (!is_readable($path) || filesize($path) > 262144) { $this->coverage_gap('System cron file unreadable or oversized'); continue; }
            $contents = @file_get_contents($path);
            if (false === $contents) { $this->coverage_gap('System cron read failed'); continue; }
            $system_files_checked++;
            foreach (explode("\n", $contents) as $line => $command) {
                if (preg_match('/^\s*(?:#|$|[A-Za-z_][A-Za-z0-9_]*\s*=)/', $command)) { continue; }
                $reason = ErrorVault_Security_Evidence::cron_command_reason($command);
                if ($reason) {
                    $this->add_finding('cron:system:' . md5($path . ':' . $line), 'config', 'Review system scheduled task', 'warning', true,
                        'A readable cron entry ' . $reason . '. This may be intentional maintenance; confirm its origin.',
                        'Review the cron entry on the server. No command was executed or modified.', array('path' => $path, 'line' => $line + 1, 'reason' => $reason));
                }
            }
        }
        if (count($paths) > 100) { $this->coverage_gap('System cron file limit reached'); }
        $this->add_finding('cron:scope', 'database', 'Scheduled task coverage', 'info', false,
            sprintf('Inspected %d WP-Cron events, %d registered callbacks and %d readable system cron files. User crontabs, systemd timers, container/hosting schedulers and conditionally registered callbacks are not fully visible. Check partial-coverage findings for inspection limits.', $events_checked, $callbacks_checked, $system_files_checked),
            null, array('events_checked' => $events_checked, 'callbacks_checked' => $callbacks_checked, 'system_files_checked' => $system_files_checked));
    }

    /* --------------------------- Findings ---------------------------- */

    private function build_file_findings() {
        if (!empty($this->ioc_hash_hits)) {
            $this->add_finding('files:ioc_hash', 'files', 'Known wp2shell malware files', 'critical', true,
                sprintf('%d file(s) exactly match malware published for the wp2shell attack.', count($this->ioc_hash_hits)),
                'Delete these files, then follow the full cleanup checklist: the attacker had code execution.',
                array('files' => array_slice($this->ioc_hash_hits, 0, self::MAX_LIST)));
        }

        if (!empty($this->ioc_dir_hits)) {
            $this->add_finding('files:ioc_dirs', 'files', 'wp2shell attacker folders', 'critical', true,
                sprintf('%d folder(s) match the fun-<random> naming the wp2shell exploit uses for webshells.', count($this->ioc_dir_hits)),
                'Delete these folders via SFTP and check for other persistence.',
                array('files' => array_slice($this->ioc_dir_hits, 0, self::MAX_LIST)));
        }

        if (!empty($this->signature_hits['critical'])) {
            $this->add_finding('files:signatures', 'files', 'Webshell / backdoor code', 'critical', true,
                sprintf('%d file(s) contain code used by webshells and backdoors.', count($this->signature_hits['critical'])),
                'Review the matched executable code against a known-good release. Quarantine or restore confirmed malicious changes; a heuristic match is not conclusive on its own.',
                array('files' => $this->signature_hits['critical']));
        }

        if (!empty($this->signature_hits['warning'])) {
            $paths = wp_list_pluck($this->signature_hits['warning'], 'path');
            $this->add_finding('files:suspicious:' . md5(implode('|', $paths)), 'files', 'Suspicious code patterns', 'warning', true,
                sprintf('%d file(s) contain obfuscated or unusual code that is common in malware but can be legitimate.', count($paths)),
                'Review each file. If it belongs to a premium plugin or theme you trust, mark it as recognised in Error-Vault.',
                array('files' => $this->signature_hits['warning']));
            $this->mark_trustable_last($paths);
        }

        if (!empty($this->php_in_uploads)) {
            $this->add_finding('files:php_uploads:' . md5(implode('|', $this->php_in_uploads)), 'files', 'Review executable PHP in uploads', 'warning', false,
                sprintf('%d executable PHP candidate(s) are in the uploads directory. Plugins may legitimately store PHP caches or configuration here; location alone is not proof of malware.', count($this->php_in_uploads)),
                'Verify each file against its generating plugin and inspect its contents. Preserve confirmed plugin data; restrict direct PHP execution where compatible with the application.',
                array('files' => array_slice($this->php_in_uploads, 0, self::MAX_LIST)));
            $this->mark_trustable_last($this->php_in_uploads);
        }

        if (!empty($this->htaccess_uploads)) {
            $this->add_finding('files:htaccess_uploads', 'files', '.htaccess enables PHP in uploads', 'warning', true,
                'An .htaccess file inside uploads maps files to the PHP handler, which lets uploaded "images" run as code.',
                'Delete these .htaccess files.',
                array('files' => $this->htaccess_uploads));
        }

        if (!empty($this->plugin_unexpected)) {
            $this->add_finding('files:plugin_unexpected:' . md5(implode('|', $this->plugin_unexpected)), 'plugins', 'Extra PHP files inside WordPress.org plugins', 'warning', true,
                sprintf('%d PHP file(s) inside WordPress.org plugins are not part of the official release.', count($this->plugin_unexpected)),
                'Reinstall the affected plugins from WordPress.org.',
                array('files' => array_slice($this->plugin_unexpected, 0, self::MAX_LIST)));
            $this->mark_trustable_last($this->plugin_unexpected);
        }

        if (!empty($this->recent_php)) {
            $this->add_finding('files:recent', 'files', 'Recently changed PHP files', 'info', false,
                sprintf('%d PHP file(s) changed in the last 7 days. Expected after updates; useful when investigating.', count($this->recent_php)),
                null, array('files' => $this->recent_php));
        }

        if ($this->truncated) {
            $this->add_finding('files:truncated', 'files', 'Scan was partial', 'warning', false,
                'Some files or checks could not be inspected (limits, exclusions or read failures). Absence of a finding is not proof of a clean site.', null, array('reasons' => $this->coverage_gaps));
        }

        if (!$this->truncated && empty($this->ioc_hash_hits) && empty($this->ioc_dir_hits) && empty($this->signature_hits['critical']) && empty($this->signature_hits['warning'])) {
            $this->add_finding('files:clean', 'files', 'No supported malware signatures found', 'pass', false,
                sprintf('%s files checked by checksums or supported signatures. This does not establish absence of malware, and does not cover all host schedulers.', number_format_i18n($this->files_scanned)));
        }
    }

    /**
     * Let the owner accept an exact set of flagged files in the portal. A new
     * file or changed file content changes the set's hash, so it gets flagged again.
     */
    private function mark_trustable_last(array $paths) {
        $fingerprints = array();
        foreach ($paths as $path) {
            $full = ABSPATH . ltrim($path, '/');
            $hash = is_file($full) ? @hash_file('sha256', $full) : false;
            if (false === $hash) { return; } // no persistent trust without a content identity
            $fingerprints[] = $path . ':' . $hash;
        }
        sort($fingerprints, SORT_STRING);
        $i = count($this->findings) - 1;
        $this->findings[$i]['trust'] = array(
            'type' => 'files',
            'value' => hash('sha256', implode('|', $fingerprints)),
            'label' => $this->findings[$i]['check'],
        );
    }

    /* ---------------------------- Admin UI --------------------------- */

    public static function get_last_scan() {
        return get_option(self::LAST_SCAN_OPTION, array());
    }
}
