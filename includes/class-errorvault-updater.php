<?php
/**
 * GitHub Updater for ErrorVault Plugin
 * Checks for updates from GitHub releases
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_Updater {

    /**
     * GitHub repository owner
     */
    private $github_user = 'devlabsza'; // Change this to your GitHub username

    /**
     * GitHub repository name
     */
    private $github_repo = 'error-vault_wordpress'; // Change this to your repo name

    /**
     * Plugin slug
     */
    private $plugin_slug;

    /**
     * Plugin basename
     */
    private $plugin_basename;

    /**
     * Current version
     */
    private $version;

    /**
     * GitHub API URL
     */
    private $github_api_url;

    /**
     * Constructor
     */
    public function __construct($plugin_file) {
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_basename = $plugin_file;
        $this->version = ERRORVAULT_VERSION;
        $this->github_api_url = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);

        // Preserve plugin activation state after update
        add_filter('upgrader_clear_destination', array($this, 'clear_destination'), 10, 4);

        // Surface update failures in the admin so they don't fail silently
        add_action('admin_notices', array($this, 'display_update_error'));
        add_action('admin_init', array($this, 'maybe_dismiss_update_error'));
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get latest release from GitHub
        $release = $this->get_latest_release();

        if ($release && version_compare($this->version, ltrim($release->tag_name, 'v'), '<')) {
            // Try to find properly named release asset first
            $package_url = $this->get_release_asset_url($release);
            
            // Fallback to zipball if no asset found (will require folder renaming)
            if (!$package_url) {
                $package_url = $release->zipball_url;
                error_log('[ErrorVault Updater] Using zipball URL (no release asset found): ' . $package_url);
            } else {
                error_log('[ErrorVault Updater] Using release asset: ' . $package_url);
            }
            
            $plugin_data = array(
                'slug' => 'errorvault-wordpress',
                'plugin' => 'errorvault-wordpress/errorvault.php',
                'new_version' => ltrim($release->tag_name, 'v'),
                'url' => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package' => $package_url,
                'tested' => '6.4',
                'requires_php' => '7.4',
            );

            error_log('[ErrorVault Updater] Update available: ' . $this->version . ' -> ' . ltrim($release->tag_name, 'v'));
            $transient->response[$this->plugin_slug] = (object) $plugin_data;
        }

        return $transient;
    }

    /**
     * Get plugin information for the update screen
     */
    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') {
            return $false;
        }

        if (!isset($args->slug) || $args->slug !== 'errorvault-wordpress') {
            return $false;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $false;
        }

        // Get the package URL (prefer asset, fallback to zipball)
        $package_url = $this->get_release_asset_url($release);
        if (!$package_url) {
            $package_url = $release->zipball_url;
        }

        $plugin_info = array(
            'name' => 'ErrorVault',
            'slug' => 'errorvault-wordpress',
            'version' => ltrim($release->tag_name, 'v'),
            'author' => '<a href="https://errorvault.com">ErrorVault</a>',
            'homepage' => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'requires' => '5.8',
            'tested' => '6.4',
            'requires_php' => '7.4',
            'download_link' => $package_url,
            'sections' => array(
                'description' => $this->parse_markdown_description($release->body),
                'changelog' => $this->parse_changelog($release->body),
            ),
            'banners' => array(),
        );

        return (object) $plugin_info;
    }

    /**
     * Clear destination before installing update
     * This ensures clean installation
     */
    public function clear_destination($removed, $local_destination, $remote_destination, $hook_extra) {
        global $wp_filesystem;
        
        // Only handle our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $removed;
        }
        
        error_log('[ErrorVault Updater] Clear destination called');
        error_log('[ErrorVault Updater] Local: ' . $local_destination);
        error_log('[ErrorVault Updater] Remote: ' . $remote_destination);
        
        // If remote destination is our properly named folder, we're good
        if (basename($remote_destination) === 'errorvault-wordpress') {
            return $removed;
        }
        
        // Otherwise, we need to handle the rename
        return $removed;
    }

    /**
     * After plugin installation
     * Handles proper plugin folder naming after extraction.
     * When the zipball fallback is used the existing folder must be replaced —
     * we back it up first so a failed rename doesn't leave the user with no plugin.
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $result;
        }

        $proper_destination = WP_PLUGIN_DIR . '/errorvault-wordpress';

        error_log('[ErrorVault Updater] After install - Current destination: ' . $result['destination']);
        error_log('[ErrorVault Updater] After install - Proper destination: ' . $proper_destination);

        // Properly named asset extracted straight into place — nothing to do.
        if ($result['destination'] === $proper_destination) {
            $result['destination_name'] = 'errorvault-wordpress';
            return $result;
        }

        if (!$wp_filesystem->exists($result['destination'])) {
            $this->store_update_error('Extracted update folder was not found on disk.');
            return new WP_Error('ev_missing_source', __('ErrorVault update failed: extracted folder is missing.', 'errorvault'));
        }

        // Move the existing folder aside first so we can roll back if the rename fails.
        $backup_destination = $proper_destination . '.backup-' . time();
        $backup_created = false;

        if ($wp_filesystem->exists($proper_destination)) {
            if (!$wp_filesystem->move($proper_destination, $backup_destination)) {
                $this->store_update_error('Could not back up the existing plugin folder before update. No changes applied.');
                return new WP_Error('ev_backup_failed', __('ErrorVault update failed: could not back up the existing plugin folder.', 'errorvault'));
            }
            $backup_created = true;
        }

        if ($wp_filesystem->move($result['destination'], $proper_destination)) {
            if ($backup_created) {
                $wp_filesystem->delete($backup_destination, true);
            }
            $result['destination'] = $proper_destination;
            $result['destination_name'] = 'errorvault-wordpress';
            return $result;
        }

        // Rename failed — try to restore the previous version so the plugin doesn't vanish.
        if ($backup_created && $wp_filesystem->move($backup_destination, $proper_destination)) {
            $this->store_update_error('Update could not be installed. The previous version was restored.');
        } else {
            $this->store_update_error('Update failed and the previous version could not be restored. Reinstall the plugin manually.');
        }

        return new WP_Error('ev_install_failed', __('ErrorVault update failed. See PHP error log for details.', 'errorvault'));
    }

    /**
     * Record an update failure so the admin sees it instead of the plugin silently disappearing.
     */
    private function store_update_error($message) {
        error_log('[ErrorVault Updater] ' . $message);
        set_transient('errorvault_update_error', $message, DAY_IN_SECONDS);
    }

    /**
     * Render a dismissible admin notice if the last update failed.
     */
    public function display_update_error() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $message = get_transient('errorvault_update_error');
        if (!$message) {
            return;
        }

        $dismiss_url = wp_nonce_url(add_query_arg('errorvault_dismiss_update_error', '1'), 'errorvault_dismiss_update_error');
        printf(
            '<div class="notice notice-error"><p><strong>ErrorVault:</strong> %s <a href="%s" style="margin-left:10px;">Dismiss</a></p></div>',
            esc_html($message),
            esc_url($dismiss_url)
        );
    }

    /**
     * Clear the stored update error when the admin dismisses the notice.
     */
    public function maybe_dismiss_update_error() {
        if (!isset($_GET['errorvault_dismiss_update_error'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('errorvault_dismiss_update_error');

        delete_transient('errorvault_update_error');

        wp_safe_redirect(remove_query_arg(array('errorvault_dismiss_update_error', '_wpnonce')));
        exit;
    }

    /**
     * Get release asset URL (properly named zip from GitHub Actions)
     */
    private function get_release_asset_url($release) {
        if (!isset($release->assets) || empty($release->assets)) {
            return false;
        }

        // Look for errorvault-wordpress.zip asset
        foreach ($release->assets as $asset) {
            if ($asset->name === 'errorvault-wordpress.zip') {
                return $asset->browser_download_url;
            }
        }

        return false;
    }

    /**
     * Get latest release from GitHub
     */
    private function get_latest_release() {
        $transient_key = 'errorvault_github_release';
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($this->github_api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $release = json_decode($body);

        if (!$release || !isset($release->tag_name)) {
            return false;
        }

        // Cache for 12 hours
        set_transient($transient_key, $release, 12 * HOUR_IN_SECONDS);

        return $release;
    }

    /**
     * Parse markdown description
     */
    private function parse_markdown_description($markdown) {
        if (empty($markdown)) {
            return 'ErrorVault WordPress plugin for centralized error monitoring and server health tracking.';
        }

        // Simple markdown to HTML conversion
        $html = wpautop($markdown);
        $html = str_replace('**', '<strong>', $html);
        $html = str_replace('__', '</strong>', $html);
        
        return $html;
    }

    /**
     * Parse changelog from release notes
     */
    private function parse_changelog($markdown) {
        if (empty($markdown)) {
            return '<p>See GitHub releases for changelog.</p>';
        }

        return wpautop($markdown);
    }

    /**
     * Clear update cache
     */
    public function clear_cache() {
        delete_transient('errorvault_github_release');
    }
}
