<?php
/**
 * Cleanup actions queued from the ErrorVault portal.
 *
 * The portal can only ask for a fixed set of actions, and every action is
 * re-checked here against local rules before anything changes:
 *
 *  - quarantine_path / remove_plugin: files are copied (folders zipped) into a
 *    non-executable quarantine and can be restored; never deleted outright.
 *    wp-config.php, genuine core files, the active theme, ErrorVault itself
 *    and anything outside the WordPress install are refused.
 *  - reinstall_plugin / update_core: packages only ever come from WordPress.org.
 *  - delete_admin: content is reassigned; the last administrator can't be removed.
 *  - rotate_salts / logout_all: sign everyone out, including an attacker.
 *
 * Nothing sent by the portal is ever written to disk or executed as code.
 * Site owners can turn remote actions off in Settings, ErrorVault, or with
 * define('ERRORVAULT_DISABLE_REMOTE_ACTIONS', true);
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_Security_Actions {

    const EXECUTED_OPTION = 'errorvault_executed_actions';
    const QUARANTINE_OPTION = 'errorvault_quarantine';
    const QUARANTINE_DIR_OPTION = 'errorvault_quarantine_dir';

    const TYPES = array(
        'quarantine_path', 'restore_quarantine', 'remove_plugin', 'reinstall_plugin',
        'update_core', 'delete_admin', 'rotate_salts', 'logout_all', 'fix_active_plugins',
    );

    const SALT_KEYS = array(
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    );

    const CORE_ROOT_FILES = array(
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config.php',
        'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php',
        'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
    );

    private static $core_checksums = null;

    public static function enabled() {
        if (defined('ERRORVAULT_DISABLE_REMOTE_ACTIONS') && ERRORVAULT_DISABLE_REMOTE_ACTIONS) {
            return false;
        }
        $settings = get_option('errorvault_settings', array());
        return empty($settings['disable_remote_actions']);
    }

    /* ------------------------------------------------------------------
     * Queue processing
     * ------------------------------------------------------------------ */

    public static function process(array $actions) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        @ignore_user_abort(true);

        $executed = get_option(self::EXECUTED_OPTION, array());
        $completed = array();

        foreach ($actions as $action) {
            $id = isset($action['id']) ? (int) $action['id'] : 0;
            $type = isset($action['type']) ? (string) $action['type'] : '';
            $params = isset($action['params']) && is_array($action['params']) ? $action['params'] : array();

            if ($id <= 0) {
                continue;
            }

            // Never run the same action twice; just re-send what happened.
            if (isset($executed[$id])) {
                $executed[$id]['reported'] = self::report($id, $executed[$id]['status'], $executed[$id]['message'], $executed[$id]['data']);
                continue;
            }

            // Record before running, so a crash mid-action can't cause a re-run.
            $executed[$id] = array('status' => 'failed', 'message' => 'Interrupted before finishing.', 'data' => array(), 'reported' => false, 'time' => time());
            self::save_executed($executed);

            if (!self::enabled()) {
                $status = 'failed';
                $message = 'Remote cleanup actions are turned off in the ErrorVault plugin settings on this site.';
                $data = array();
            } elseif (!in_array($type, self::TYPES, true)) {
                $status = 'failed';
                $message = 'Unknown action type.';
                $data = array();
            } else {
                try {
                    $data = self::run($type, $params);
                    $status = 'completed';
                    $message = isset($data['message']) ? $data['message'] : 'Done.';
                    unset($data['message']);
                } catch (Throwable $e) {
                    $status = 'failed';
                    $message = $e->getMessage();
                    $data = array();
                }
            }

            error_log(sprintf('[ErrorVault Security] Action #%d %s: %s (%s)', $id, $type, $status, $message));

            $executed[$id] = array('status' => $status, 'message' => $message, 'data' => $data, 'reported' => false, 'time' => time());
            $executed[$id]['reported'] = self::report($id, $status, $message, $data);
            self::save_executed($executed);

            if ('completed' === $status) {
                $completed[] = $type;
            }
        }

        return $completed;
    }

    public static function flush_unreported() {
        $executed = get_option(self::EXECUTED_OPTION, array());
        $changed = false;
        foreach ($executed as $id => $entry) {
            if (empty($entry['reported'])) {
                $executed[$id]['reported'] = self::report($id, $entry['status'], $entry['message'], $entry['data']);
                $changed = true;
            }
        }
        if ($changed) {
            self::save_executed($executed);
        }
    }

    private static function save_executed(array $executed) {
        // Keep the most recent 200 entries.
        if (count($executed) > 200) {
            ksort($executed);
            $executed = array_slice($executed, -200, null, true);
        }
        update_option(self::EXECUTED_OPTION, $executed, false);
    }

    private static function report($id, $status, $message, $data) {
        $response = wp_remote_post(ErrorVault_Security_Scanner::api_base() . '/security/actions/' . (int) $id . '/result', array(
            'timeout' => 20,
            'headers' => ErrorVault_Security_Scanner::headers(),
            'body' => wp_json_encode(array('status' => $status, 'message' => (string) $message, 'data' => (array) $data)),
        ));

        return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 300;
    }

    public static function run($type, array $params) {
        switch ($type) {
            case 'quarantine_path':
                return self::quarantine_path(isset($params['path']) ? (string) $params['path'] : '');
            case 'restore_quarantine':
                return self::restore(isset($params['quarantine_id']) ? (string) $params['quarantine_id'] : '');
            case 'remove_plugin':
                return self::remove_plugin(isset($params['slug']) ? (string) $params['slug'] : '');
            case 'reinstall_plugin':
                return self::reinstall_plugin(isset($params['slug']) ? (string) $params['slug'] : '');
            case 'update_core':
                return self::update_core();
            case 'delete_admin':
                return self::delete_admin(
                    isset($params['user_id']) ? (int) $params['user_id'] : 0,
                    isset($params['login']) ? (string) $params['login'] : '',
                    isset($params['reassign_to']) ? (int) $params['reassign_to'] : 0
                );
            case 'rotate_salts':
                return self::rotate_salts();
            case 'logout_all':
                WP_Session_Tokens::destroy_all_for_all_users();
                return array('message' => 'Logged out every user on the site.');
            case 'fix_active_plugins':
                return self::fix_active_plugins();
        }
        throw new Exception('Unknown action type.');
    }

    /* ------------------------------------------------------------------
     * Quarantine
     * ------------------------------------------------------------------ */

    private static function quarantine_path($relative) {
        $real = self::resolve($relative);
        self::assert_quarantinable($real);

        return self::quarantine($real, self::relative($real), is_dir($real) ? 'dir' : 'file');
    }

    /**
     * Resolve a path reported by the scanner (relative to ABSPATH, or absolute
     * when wp-content lives outside it) and make sure it's inside the install.
     */
    private static function resolve($path) {
        $path = str_replace('\\', '/', trim($path));
        if ('' === $path || false !== strpos($path, "\0")) {
            throw new Exception('Invalid path.');
        }

        $full = ('/' === $path[0]) ? $path : ABSPATH . $path;
        $real = realpath($full);
        if (false === $real) {
            throw new Exception('Not found: ' . $path . ' (already removed?)');
        }
        $real = wp_normalize_path($real);

        $roots = array_filter(array(realpath(ABSPATH), realpath(WP_CONTENT_DIR)));
        foreach ($roots as $root) {
            if (0 === strpos($real, wp_normalize_path($root) . '/')) {
                return $real;
            }
        }

        throw new Exception('Refusing to touch a path outside the WordPress install.');
    }

    private static function assert_quarantinable($real) {
        $rel = self::relative($real);
        $name = strtolower(basename($real));
        $abspath = wp_normalize_path(realpath(ABSPATH));

        if ('wp-config.php' === $name) {
            throw new Exception('wp-config.php is never quarantined. Remove injected code from it by hand, then rotate the salts.');
        }
        if ($real === $abspath . '/.htaccess') {
            throw new Exception('The root .htaccess is never quarantined (it would break permalinks). Edit it by hand.');
        }
        if (0 === strpos($real . '/', wp_normalize_path(ERRORVAULT_PLUGIN_DIR))) {
            throw new Exception('Refusing to quarantine the ErrorVault plugin.');
        }

        // Genuine core paths are fixed by reinstalling core, not removed.
        if (is_file($real)) {
            if (false === strpos($rel, '/') && in_array($name, self::CORE_ROOT_FILES, true)) {
                throw new Exception($rel . ' is a WordPress core file. Use "Update / reinstall WordPress core" instead.');
            }
            $checksums = self::core_checksums();
            if (is_array($checksums) && isset($checksums[$rel])) {
                throw new Exception($rel . ' is a WordPress core file. Use "Update / reinstall WordPress core" instead.');
            }
            if (!is_array($checksums) && (0 === strpos($rel, 'wp-admin/') || 0 === strpos($rel, WPINC . '/'))) {
                throw new Exception('Could not verify core checksums, so files inside wp-admin / wp-includes are left alone.');
            }
            return;
        }

        // Directories: never a top-level folder, never one holding the active theme.
        $protected = array(
            $abspath, ABSPATH . 'wp-admin', ABSPATH . WPINC, WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root(),
        );
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['basedir'])) {
            $protected[] = $uploads['basedir'];
        }
        foreach ($protected as $dir) {
            $dir_real = realpath($dir);
            if ($dir_real && wp_normalize_path($dir_real) === $real) {
                throw new Exception('Refusing to quarantine a top-level WordPress folder.');
            }
        }
        foreach (array(get_stylesheet_directory(), get_template_directory()) as $theme) {
            $theme_real = realpath($theme);
            if ($theme_real && 0 === strpos(wp_normalize_path($theme_real) . '/', $real . '/')) {
                throw new Exception('Refusing to quarantine the active theme. Switch themes first.');
            }
        }
        if (0 === strpos($rel, 'wp-admin/') || 0 === strpos($rel, WPINC . '/')) {
            throw new Exception('Folders inside wp-admin / wp-includes are fixed by reinstalling core.');
        }
    }

    private static function quarantine($real, $rel, $type) {
        $dir = self::quarantine_dir();
        $id = 'q' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false));

        if ('dir' === $type) {
            if (!class_exists('ZipArchive')) {
                throw new Exception('The ZipArchive PHP extension is needed to quarantine folders.');
            }
            $stored = $dir . '/' . $id . '.zip';
            $count = self::zip_dir($real, $stored);
            self::rrmdir($real);
            if (file_exists($real)) {
                throw new Exception('Copied to quarantine but could not remove the original folder (permissions?).');
            }
            $meta = array('files' => $count, 'size' => filesize($stored));
        } else {
            $stored = $dir . '/' . $id . '.bin';
            $sha = hash_file('sha256', $real);
            if (!@copy($real, $stored) || hash_file('sha256', $stored) !== $sha) {
                throw new Exception('Could not copy the file into quarantine.');
            }
            if (!@unlink($real)) {
                @unlink($stored);
                throw new Exception('Could not remove the original file (permissions?).');
            }
            $meta = array('sha256' => $sha, 'size' => filesize($stored));
        }

        $manifest = get_option(self::QUARANTINE_OPTION, array());
        $manifest[$id] = array_merge($meta, array('path' => $rel, 'abs' => $real, 'type' => $type, 'stored' => $stored, 'time' => time()));
        update_option(self::QUARANTINE_OPTION, $manifest, false);

        return array(
            'message' => sprintf('Quarantined %s%s.', $rel, 'dir' === $type ? ' (' . (int) $meta['files'] . ' files)' : ''),
            'quarantine_id' => $id,
            'path' => $rel,
        );
    }

    private static function restore($id) {
        $manifest = get_option(self::QUARANTINE_OPTION, array());
        if ('' === $id || !isset($manifest[$id])) {
            throw new Exception('Nothing in quarantine with that ID.');
        }
        $item = $manifest[$id];

        if ('backup' !== $item['type'] && file_exists($item['abs'])) {
            throw new Exception('Something already exists at ' . $item['path'] . '; not overwriting it.');
        }
        if (!file_exists($item['stored'])) {
            throw new Exception('The quarantined copy is missing.');
        }

        wp_mkdir_p(dirname($item['abs']));
        if ('dir' === $item['type']) {
            $zip = new ZipArchive();
            if (true !== $zip->open($item['stored']) || !$zip->extractTo($item['abs'])) {
                throw new Exception('Could not extract the quarantined folder.');
            }
            $zip->close();
        } elseif (!@copy($item['stored'], $item['abs'])) {
            throw new Exception('Could not copy the file back.');
        }

        @unlink($item['stored']);
        unset($manifest[$id]);
        update_option(self::QUARANTINE_OPTION, $manifest, false);

        return array('message' => 'Restored ' . $item['path'] . '.', 'quarantine_id' => $id, 'path' => $item['path']);
    }

    /**
     * Quarantine lives outside the web root when possible, in a folder with a
     * random name, and stores .bin / .zip files only, so nothing in it can run.
     */
    private static function quarantine_dir() {
        $dir = get_option(self::QUARANTINE_DIR_OPTION);
        if ($dir && is_dir($dir) && wp_is_writable($dir)) {
            return $dir;
        }

        $name = 'errorvault-quarantine-' . strtolower(wp_generate_password(12, false));
        foreach (array(dirname(untrailingslashit(ABSPATH)), WP_CONTENT_DIR) as $base) {
            if (@is_dir($base) && @wp_is_writable($base) && wp_mkdir_p($base . '/' . $name)) {
                $dir = $base . '/' . $name;
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
                @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
                update_option(self::QUARANTINE_DIR_OPTION, $dir, false);
                return $dir;
            }
        }

        throw new Exception('No writable location for the quarantine folder.');
    }

    private static function zip_dir($source, $target) {
        $zip = new ZipArchive();
        if (true !== $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new Exception('Could not create the quarantine archive.');
        }

        $count = 0;
        $bytes = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $local = ltrim(substr(wp_normalize_path($file->getPathname()), strlen($source)), '/');
            if ($file->isDir()) {
                $zip->addEmptyDir($local);
                continue;
            }
            $count++;
            $bytes += $file->getSize();
            if ($count > 20000 || $bytes > 500 * MB_IN_BYTES) {
                $zip->close();
                @unlink($target);
                throw new Exception('Folder is too large to quarantine automatically (over 20,000 files or 500 MB).');
            }
            $zip->addFile($file->getPathname(), $local);
        }

        if (!$zip->close()) {
            @unlink($target);
            throw new Exception('Could not write the quarantine archive.');
        }

        return $count;
    }

    private static function rrmdir($dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    private static function relative($real) {
        $root = wp_normalize_path(realpath(ABSPATH));
        return 0 === strpos($real, $root . '/') ? substr($real, strlen($root) + 1) : $real;
    }

    private static function core_checksums() {
        if (null === self::$core_checksums) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
            $version = get_bloginfo('version');
            $checksums = get_core_checksums($version, get_locale() ? get_locale() : 'en_US');
            if (!is_array($checksums)) {
                $checksums = get_core_checksums($version, 'en_US');
            }
            self::$core_checksums = is_array($checksums) ? $checksums : false;
        }
        return self::$core_checksums;
    }

    /* ------------------------------------------------------------------
     * Plugins / core
     * ------------------------------------------------------------------ */

    private static function find_plugin($slug) {
        if (!preg_match('/^[a-z0-9._-]+$/i', $slug) || '.' === $slug[0]) {
            throw new Exception('Invalid plugin slug.');
        }
        if (dirname(plugin_basename(ERRORVAULT_PLUGIN_DIR . 'errorvault.php')) === $slug) {
            throw new Exception('Refusing to touch the ErrorVault plugin.');
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (get_plugins() as $file => $data) {
            if (dirname($file) === $slug || ('.' === dirname($file) && basename($file, '.php') === $slug)) {
                return array($file, $data);
            }
        }
        return array(null, null);
    }

    private static function remove_plugin($slug) {
        list($file) = self::find_plugin($slug);

        $path = WP_PLUGIN_DIR . '/' . ($file && '.' === dirname($file) ? $file : $slug);
        if (!file_exists($path)) {
            throw new Exception('Plugin ' . $slug . ' is not installed (already removed?).');
        }

        if ($file) {
            deactivate_plugins($file, true);
            if (is_multisite()) {
                deactivate_plugins($file, true, true);
            }
        }

        $real = wp_normalize_path(realpath($path));
        $result = self::quarantine($real, self::relative($real), is_dir($real) ? 'dir' : 'file');
        $result['message'] = 'Deactivated and quarantined plugin ' . $slug . '.';
        return $result;
    }

    private static function require_upgrader() {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if ('direct' !== get_filesystem_method(array(), WP_CONTENT_DIR, true) || !WP_Filesystem()) {
            throw new Exception('WordPress needs FTP/SSH credentials to write files on this server, so this has to be done from wp-admin.');
        }
    }

    private static function reinstall_plugin($slug) {
        list($file, $data) = self::find_plugin($slug);
        if (!$file || '.' === dirname($file)) {
            throw new Exception('Plugin ' . $slug . ' is not installed as a folder.');
        }
        $version = (string) $data['Version'];
        if (!preg_match('/^[0-9a-z._-]+$/i', $version)) {
            throw new Exception('Unrecognised plugin version.');
        }

        // Only ever install from WordPress.org, and only the version already installed.
        $package = 'https://downloads.wordpress.org/plugin/' . rawurlencode($slug) . '.' . rawurlencode($version) . '.zip';
        $head = wp_remote_head($package, array('timeout' => 15, 'redirection' => 3));
        if (is_wp_error($head) || (int) wp_remote_retrieve_response_code($head) >= 400) {
            throw new Exception($slug . ' ' . $version . ' is not available on WordPress.org.');
        }

        self::require_upgrader();
        $was_active = is_plugin_active($file);

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->install($package, array('overwrite_package' => true, 'clear_update_cache' => true));

        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }
        if (!$result) {
            throw new Exception('Reinstall failed: ' . implode(' ', array_map('wp_strip_all_tags', (array) $skin->get_upgrade_messages())));
        }
        if ($was_active && !is_plugin_active($file)) {
            activate_plugin($file, '', false, true);
        }

        return array('message' => sprintf('Reinstalled %s %s from WordPress.org.', $slug, $version));
    }

    private static function update_core() {
        self::require_upgrader();

        $from = get_bloginfo('version');
        delete_site_transient('update_core');
        wp_version_check(array(), true);

        $updates = get_core_updates();
        if (empty($updates) || !is_object($updates[0])) {
            throw new Exception('Could not get update information from WordPress.org.');
        }

        $update = $updates[0];
        if ('latest' === $update->response) {
            $update->response = 'reinstall';
        }

        $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($update, array('allow_relaxed_file_ownership' => true));

        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }
        if (!$result) {
            throw new Exception('The core update did not complete.');
        }

        return array(
            'message' => $from === $result ? sprintf('Reinstalled WordPress %s.', $result) : sprintf('Updated WordPress from %s to %s.', $from, $result),
            'from' => $from,
            'to' => $result,
        );
    }

    /**
     * Drop active_plugins entries that point outside the plugins folder or
     * aren't PHP files. Valid entries are left exactly as they were.
     */
    private static function fix_active_plugins() {
        $active = (array) get_option('active_plugins', array());
        $kept = array();
        $removed = array();
        foreach ($active as $entry) {
            if (false !== strpos((string) $entry, '..') || !preg_match('/\.php$/i', (string) $entry)) {
                $removed[] = (string) $entry;
            } else {
                $kept[] = $entry;
            }
        }

        if (empty($removed)) {
            return array('message' => 'active_plugins had no invalid entries.');
        }

        update_option('active_plugins', array_values($kept));

        return array('message' => 'Removed from active_plugins: ' . implode(', ', $removed) . '. Delete the files they pointed to if they still exist.', 'removed' => $removed);
    }

    /* ------------------------------------------------------------------
     * Accounts / secrets
     * ------------------------------------------------------------------ */

    private static function delete_admin($user_id, $login, $reassign_to) {
        if (is_multisite()) {
            throw new Exception('Deleting users on multisite has to be done from Network Admin.');
        }

        $user = get_userdata($user_id);
        if (!$user || $user->user_login !== $login) {
            throw new Exception('User #' . $user_id . ' (' . $login . ') no longer exists or has changed.');
        }

        $reassign = get_userdata($reassign_to);
        if (!$reassign || (int) $reassign->ID === (int) $user->ID || !user_can($reassign, 'manage_options')) {
            throw new Exception('Content must be reassigned to a different administrator.');
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        WP_Session_Tokens::get_instance($user->ID)->destroy_all();

        if (!wp_delete_user($user->ID, $reassign->ID)) {
            throw new Exception('WordPress refused to delete the user.');
        }

        return array('message' => sprintf('Deleted administrator %s (%s); content reassigned to %s.', $user->user_login, $user->user_email, $reassign->user_login));
    }

    /**
     * Replace the eight keys/salts in wp-config.php, logging everyone out.
     * Writes via a temp file and keeps a restorable backup in quarantine.
     */
    private static function rotate_salts() {
        $config = file_exists(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(ABSPATH) . '/wp-config.php';
        if (!is_file($config) || !is_writable($config)) {
            throw new Exception('wp-config.php is not writable by WordPress.');
        }

        $original = file_get_contents($config);
        $updated = $original;
        foreach (self::SALT_KEYS as $key) {
            $pattern = '/(define\s*\(\s*([\'"])' . $key . '\2\s*,\s*)([\'"])((?:(?!\3).)*)\3(\s*\)\s*;)/';
            if (1 !== preg_match_all($pattern, $updated)) {
                throw new Exception($key . ' is not defined exactly once in wp-config.php (salts may come from environment variables). Rotate them where they are defined.');
            }
            $salt = self::salt();
            $updated = preg_replace_callback($pattern, function ($m) use ($salt) {
                return $m[1] . "'" . $salt . "'" . $m[5];
            }, $updated, 1);
        }

        // Restorable backup.
        $dir = self::quarantine_dir();
        $id = 'q' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false));
        $stored = $dir . '/' . $id . '.bin';
        if (false === file_put_contents($stored, $original)) {
            throw new Exception('Could not back up wp-config.php.');
        }

        $tmp = $config . '.errorvault-tmp';
        if (false === file_put_contents($tmp, $updated, LOCK_EX)) {
            throw new Exception('Could not write the new wp-config.php.');
        }
        @chmod($tmp, fileperms($config) & 0777);
        if (!@rename($tmp, $config)) {
            @unlink($tmp);
            throw new Exception('Could not replace wp-config.php.');
        }

        $real = wp_normalize_path(realpath($config));
        $manifest = get_option(self::QUARANTINE_OPTION, array());
        $manifest[$id] = array('path' => self::relative($real), 'abs' => $real, 'type' => 'backup', 'stored' => $stored, 'time' => time(), 'size' => strlen($original));
        update_option(self::QUARANTINE_OPTION, $manifest, false);

        return array(
            'message' => 'Rotated the 8 security keys/salts in wp-config.php. Everyone, including any attacker, has been logged out.',
            'quarantine_id' => $id,
        );
    }

    private static function salt() {
        // No quotes, backslashes or $ so the value is safe in any PHP string.
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|';
        $salt = '';
        for ($i = 0; $i < 64; $i++) {
            $salt .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $salt;
    }
}
