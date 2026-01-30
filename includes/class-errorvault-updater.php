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

        if ($release && version_compare($this->version, $release->tag_name, '<')) {
            $plugin_data = array(
                'slug' => dirname($this->plugin_slug),
                'new_version' => $release->tag_name,
                'url' => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package' => $release->zipball_url,
                'tested' => '6.4',
                'requires_php' => '7.4',
            );

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

        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $false;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $false;
        }

        $plugin_info = array(
            'name' => 'ErrorVault',
            'slug' => dirname($this->plugin_slug),
            'version' => $release->tag_name,
            'author' => '<a href="https://errorvault.com">ErrorVault</a>',
            'homepage' => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'requires' => '5.8',
            'tested' => '6.4',
            'requires_php' => '7.4',
            'download_link' => $release->zipball_url,
            'sections' => array(
                'description' => $this->parse_markdown_description($release->body),
                'changelog' => $this->parse_changelog($release->body),
            ),
            'banners' => array(),
        );

        return (object) $plugin_info;
    }

    /**
     * After plugin installation
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->plugin_basename);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        if ($this->plugin_slug) {
            activate_plugin($this->plugin_slug);
        }

        return $result;
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
