<?php
/**
 * Admin class for ErrorVault
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_Admin {

    /**
     * Settings
     */
    private $settings;

    /**
     * API instance
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('errorvault_settings', array());
        $this->api = new ErrorVault_API();

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // AJAX handlers
        add_action('wp_ajax_errorvault_verify_token', array($this, 'ajax_verify_token'));
        add_action('wp_ajax_errorvault_test_error', array($this, 'ajax_test_error'));
        add_action('wp_ajax_errorvault_clear_failures', array($this, 'ajax_clear_failures'));
        add_action('wp_ajax_errorvault_trigger_backup_poll', array($this, 'ajax_trigger_backup_poll'));
        add_action('wp_ajax_errorvault_get_backup_logs', array($this, 'ajax_get_backup_logs'));
        add_action('wp_ajax_errorvault_clear_backup_logs', array($this, 'ajax_clear_backup_logs'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('ErrorVault Settings', 'errorvault'),
            __('ErrorVault', 'errorvault'),
            'manage_options',
            'errorvault',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('errorvault_settings', 'errorvault_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        $sanitized['api_endpoint'] = isset($input['api_endpoint']) ? esc_url_raw($input['api_endpoint']) : '';
        $sanitized['api_token'] = isset($input['api_token']) ? sanitize_text_field($input['api_token']) : '';
        $sanitized['enabled'] = isset($input['enabled']) ? (bool)$input['enabled'] : false;
        $sanitized['include_notices'] = isset($input['include_notices']) ? (bool)$input['include_notices'] : false;
        $sanitized['include_warnings'] = isset($input['include_warnings']) ? (bool)$input['include_warnings'] : true;
        $sanitized['send_immediately'] = isset($input['send_immediately']) ? (bool)$input['send_immediately'] : true;
        $sanitized['batch_size'] = isset($input['batch_size']) ? absint($input['batch_size']) : 10;

        // Handle exclude patterns
        if (isset($input['exclude_patterns'])) {
            $patterns = explode("\n", $input['exclude_patterns']);
            $sanitized['exclude_patterns'] = array_filter(array_map('trim', $patterns));
        } else {
            $sanitized['exclude_patterns'] = array();
        }

        // Health monitoring settings
        $sanitized['health_monitoring_enabled'] = isset($input['health_monitoring_enabled']) ? (bool)$input['health_monitoring_enabled'] : false;
        $sanitized['cpu_load_threshold'] = isset($input['cpu_load_threshold']) ? (float)$input['cpu_load_threshold'] : 2.0;
        $sanitized['memory_threshold'] = isset($input['memory_threshold']) ? absint($input['memory_threshold']) : 80;
        $sanitized['request_rate_threshold'] = isset($input['request_rate_threshold']) ? absint($input['request_rate_threshold']) : 100;
        $sanitized['request_spike_threshold'] = isset($input['request_spike_threshold']) ? (float)$input['request_spike_threshold'] : 3.0;
        $sanitized['alert_cooldown'] = isset($input['alert_cooldown']) ? absint($input['alert_cooldown']) : 300;

        return $sanitized;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_errorvault') {
            return;
        }

        wp_enqueue_style(
            'errorvault-admin',
            ERRORVAULT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ERRORVAULT_VERSION
        );

        wp_enqueue_script(
            'errorvault-admin',
            ERRORVAULT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            ERRORVAULT_VERSION,
            true
        );

        wp_localize_script('errorvault-admin', 'errorvaultAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('errorvault_admin'),
            'strings' => array(
                'verifying' => __('Verifying...', 'errorvault'),
                'verified' => __('Connection successful!', 'errorvault'),
                'failed' => __('Connection failed', 'errorvault'),
                'testing' => __('Sending test error...', 'errorvault'),
                'testSuccess' => __('Test error sent! Check your ErrorVault dashboard.', 'errorvault'),
                'testFailed' => __('Failed to send test error', 'errorvault'),
                'testingHealth' => __('Sending health report...', 'errorvault'),
                'testHealthSuccess' => __('Health report sent! Check your ErrorVault Server Health dashboard.', 'errorvault'),
                'testHealthFailed' => __('Failed to send health report', 'errorvault'),
                'triggeringBackup' => __('Triggering backup poll...', 'errorvault'),
                'backupTriggered' => __('Backup poll triggered! Check logs below.', 'errorvault'),
                'backupFailed' => __('Failed to trigger backup poll', 'errorvault'),
                'loadingLogs' => __('Loading logs...', 'errorvault'),
                'clearingLogs' => __('Clearing logs...', 'errorvault'),
                'logsCleared' => __('Logs cleared successfully', 'errorvault'),
            ),
        ));
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('errorvault_settings', array());
        ?>
        <div class="wrap errorvault-settings">
            <div class="errorvault-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <h1 style="margin: 0; flex: 1 1 auto;"><?php echo esc_html(get_admin_page_title()); ?></h1>
                <div class="errorvault-version" style="background: #635bff; color: #fff; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 500; flex-shrink: 0;">
                    Version <?php echo esc_html(ERRORVAULT_VERSION); ?>
                </div>
            </div>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('errorvault_settings'); ?>

                <div class="errorvault-card">
                    <h2><?php _e('Connection Settings', 'errorvault'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_endpoint"><?php _e('API Endpoint', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="url" name="errorvault_settings[api_endpoint]" id="api_endpoint"
                                    value="<?php echo esc_attr($settings['api_endpoint'] ?? ''); ?>"
                                    class="regular-text" placeholder="https://error-vault.com/api/v1/errors">
                                <p class="description"><?php _e('The API endpoint URL from your ErrorVault portal.', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="api_token"><?php _e('API Token', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="errorvault_settings[api_token]" id="api_token"
                                    value="<?php echo esc_attr($settings['api_token'] ?? ''); ?>"
                                    class="regular-text" placeholder="Your site API token">
                                <button type="button" id="verify-token" class="button button-secondary">
                                    <?php _e('Verify Connection', 'errorvault'); ?>
                                </button>
                                <span id="verify-result"></span>
                                <p class="description"><?php _e('Copy this from your site settings in the ErrorVault portal.', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php _e('Enable Logging', 'errorvault'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="errorvault_settings[enabled]" value="1"
                                        <?php checked(!empty($settings['enabled'])); ?>>
                                    <?php _e('Send errors to ErrorVault', 'errorvault'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="errorvault-card">
                    <h2><?php _e('Logging Options', 'errorvault'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Log Levels', 'errorvault'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="errorvault_settings[include_notices]" value="1"
                                            <?php checked(!empty($settings['include_notices'])); ?>>
                                        <?php _e('Include PHP Notices', 'errorvault'); ?>
                                    </label>
                                    <br>
                                    <label>
                                        <input type="checkbox" name="errorvault_settings[include_warnings]" value="1"
                                            <?php checked($settings['include_warnings'] ?? true); ?>>
                                        <?php _e('Include PHP Warnings', 'errorvault'); ?>
                                    </label>
                                    <br>
                                    <span class="description"><?php _e('Errors, Critical errors, and Fatal errors are always logged.', 'errorvault'); ?></span>
                                </fieldset>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php _e('Sending Mode', 'errorvault'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="errorvault_settings[send_immediately]" value="1"
                                        <?php checked($settings['send_immediately'] ?? true); ?>>
                                    <?php _e('Send errors immediately', 'errorvault'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="errorvault_settings[send_immediately]" value="0"
                                        <?php checked(!($settings['send_immediately'] ?? true)); ?>>
                                    <?php _e('Batch errors and send at end of request', 'errorvault'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="batch_size"><?php _e('Batch Size', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="errorvault_settings[batch_size]" id="batch_size"
                                    value="<?php echo esc_attr($settings['batch_size'] ?? 10); ?>"
                                    min="1" max="100" class="small-text">
                                <p class="description"><?php _e('Maximum errors per batch (only used when batching is enabled).', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="exclude_patterns"><?php _e('Exclude Patterns', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <textarea name="errorvault_settings[exclude_patterns]" id="exclude_patterns"
                                    rows="5" class="large-text"><?php
                                    $patterns = isset($settings['exclude_patterns']) ? $settings['exclude_patterns'] : array();
                                    echo esc_textarea(implode("\n", $patterns));
                                ?></textarea>
                                <p class="description"><?php _e('Error messages containing these strings will be ignored. One pattern per line.', 'errorvault'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="errorvault-card">
                    <h2><?php _e('Health Monitoring', 'errorvault'); ?></h2>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php _e('Monitor server health and get alerts for potential DDoS attacks, CPU overload, and memory pressure.', 'errorvault'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Enable Health Monitoring', 'errorvault'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="errorvault_settings[health_monitoring_enabled]" value="1"
                                        <?php checked(!empty($settings['health_monitoring_enabled'])); ?>>
                                    <?php _e('Monitor server health and send alerts', 'errorvault'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="cpu_load_threshold"><?php _e('CPU Load Threshold', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="errorvault_settings[cpu_load_threshold]" id="cpu_load_threshold"
                                    value="<?php echo esc_attr($settings['cpu_load_threshold'] ?? 2.0); ?>"
                                    min="0.5" max="10" step="0.1" class="small-text">
                                <span>× CPU cores</span>
                                <p class="description"><?php _e('Alert when load average exceeds this multiple of CPU cores (e.g., 2.0 = 200% capacity).', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="memory_threshold"><?php _e('Memory Threshold', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="errorvault_settings[memory_threshold]" id="memory_threshold"
                                    value="<?php echo esc_attr($settings['memory_threshold'] ?? 80); ?>"
                                    min="50" max="99" class="small-text">
                                <span>%</span>
                                <p class="description"><?php _e('Alert when memory usage exceeds this percentage of the PHP memory limit.', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="request_rate_threshold"><?php _e('Request Rate Threshold', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="errorvault_settings[request_rate_threshold]" id="request_rate_threshold"
                                    value="<?php echo esc_attr($settings['request_rate_threshold'] ?? 100); ?>"
                                    min="10" max="10000" class="small-text">
                                <span><?php _e('requests/minute', 'errorvault'); ?></span>
                                <p class="description"><?php _e('Alert when requests per minute exceed this threshold (potential DDoS indicator).', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="request_spike_threshold"><?php _e('Traffic Spike Detection', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="errorvault_settings[request_spike_threshold]" id="request_spike_threshold"
                                    value="<?php echo esc_attr($settings['request_spike_threshold'] ?? 3.0); ?>"
                                    min="1.5" max="10" step="0.5" class="small-text">
                                <span>× normal rate</span>
                                <p class="description"><?php _e('Alert when traffic suddenly increases by this factor (e.g., 3.0 = 300% increase).', 'errorvault'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="alert_cooldown"><?php _e('Alert Cooldown', 'errorvault'); ?></label>
                            </th>
                            <td>
                                <select name="errorvault_settings[alert_cooldown]" id="alert_cooldown">
                                    <option value="60" <?php selected($settings['alert_cooldown'] ?? 300, 60); ?>><?php _e('1 minute', 'errorvault'); ?></option>
                                    <option value="300" <?php selected($settings['alert_cooldown'] ?? 300, 300); ?>><?php _e('5 minutes', 'errorvault'); ?></option>
                                    <option value="600" <?php selected($settings['alert_cooldown'] ?? 300, 600); ?>><?php _e('10 minutes', 'errorvault'); ?></option>
                                    <option value="1800" <?php selected($settings['alert_cooldown'] ?? 300, 1800); ?>><?php _e('30 minutes', 'errorvault'); ?></option>
                                    <option value="3600" <?php selected($settings['alert_cooldown'] ?? 300, 3600); ?>><?php _e('1 hour', 'errorvault'); ?></option>
                                </select>
                                <p class="description"><?php _e('Minimum time between alerts of the same type.', 'errorvault'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php
                // Get diagnostics if health monitoring is enabled
                if (!empty($settings['health_monitoring_enabled'])) {
                    $health_monitor = new ErrorVault_Health_Monitor();
                    $diagnostics = $health_monitor->get_diagnostics();
                    $api = new ErrorVault_API();
                    $failures = $api->get_connection_failures();
                    ?>
                    <div class="errorvault-card">
                        <h2><?php _e('Connection Diagnostics', 'errorvault'); ?></h2>
                        <table class="widefat" style="margin-top: 10px;">
                            <tbody>
                                <tr>
                                    <td style="width: 30%; font-weight: 600;"><?php _e('Status', 'errorvault'); ?></td>
                                    <td>
                                        <?php if ($diagnostics['enabled']): ?>
                                            <span style="color: #46b450;">● <?php _e('Active', 'errorvault'); ?></span>
                                        <?php else: ?>
                                            <span style="color: #dc3232;">● <?php _e('Inactive', 'errorvault'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;"><?php _e('Health Check Cron', 'errorvault'); ?></td>
                                    <td><?php echo esc_html($diagnostics['health_cron_scheduled']); ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;"><?php _e('Heartbeat Cron', 'errorvault'); ?></td>
                                    <td><?php echo esc_html($diagnostics['heartbeat_cron_scheduled']); ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;"><?php _e('Consecutive Failures', 'errorvault'); ?></td>
                                    <td>
                                        <?php 
                                        $consecutive = $diagnostics['consecutive_failures'];
                                        if ($consecutive > 0) {
                                            echo '<span style="color: #dc3232; font-weight: 600;">' . esc_html($consecutive) . '</span>';
                                        } else {
                                            echo '<span style="color: #46b450;">0</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;"><?php _e('Total Failures Logged', 'errorvault'); ?></td>
                                    <td><?php echo esc_html($diagnostics['total_failures']); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <?php if (!empty($diagnostics['recent_failures'])): ?>
                        <h3 style="margin-top: 20px;"><?php _e('Recent Connection Failures', 'errorvault'); ?></h3>
                        <table class="widefat" style="margin-top: 10px;">
                            <thead>
                                <tr>
                                    <th><?php _e('Time', 'errorvault'); ?></th>
                                    <th><?php _e('Type', 'errorvault'); ?></th>
                                    <th><?php _e('Error', 'errorvault'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diagnostics['recent_failures'] as $failure): ?>
                                <tr>
                                    <td><?php echo esc_html($failure['timestamp']); ?></td>
                                    <td><code><?php echo esc_html($failure['type']); ?></code></td>
                                    <td><?php echo esc_html($failure['message']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p style="margin-top: 10px;">
                            <button type="button" id="clear-failure-log" class="button button-secondary">
                                <?php _e('Clear Failure Log', 'errorvault'); ?>
                            </button>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php
                }
                ?>

                <div class="errorvault-card">
                    <h2><?php _e('Backup Status', 'errorvault'); ?></h2>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php _e('Monitor automated backup operations. Backups are triggered from your ErrorVault dashboard and run automatically.', 'errorvault'); ?>
                    </p>

                    <?php
                    $backup_status = EV_Backup_Helpers::get_backup_status();
                    $requirements = EV_Backup_Helpers::check_requirements();
                    ?>

                    <table class="widefat" style="margin-bottom: 15px;">
                        <tbody>
                            <tr>
                                <td style="font-weight: 600; width: 200px;"><?php _e('Cron Scheduled', 'errorvault'); ?></td>
                                <td>
                                    <?php if ($backup_status['cron_scheduled']): ?>
                                        <span style="color: #46b450;">✓ <?php _e('Active', 'errorvault'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;">✗ <?php _e('Not Scheduled', 'errorvault'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;"><?php _e('Next Poll', 'errorvault'); ?></td>
                                <td>
                                    <?php if ($backup_status['next_poll_time']): ?>
                                        <?php echo esc_html(date('Y-m-d H:i:s', $backup_status['next_poll_time'])); ?>
                                        <span style="color: #666;">(in <?php echo esc_html($backup_status['next_poll_human']); ?>)</span>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;"><?php _e('Backup In Progress', 'errorvault'); ?></td>
                                <td>
                                    <?php if ($backup_status['backup_in_progress']): ?>
                                        <span style="color: #f0b849;">⏳ <?php _e('Yes', 'errorvault'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #666;"><?php _e('No', 'errorvault'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight: 600;"><?php _e('Requirements', 'errorvault'); ?></td>
                                <td>
                                    <?php if ($requirements['all_met']): ?>
                                        <span style="color: #46b450;">✓ <?php _e('All requirements met', 'errorvault'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;">✗ <?php _e('Missing requirements', 'errorvault'); ?></span>
                                        <?php if (!$requirements['zip_available']): ?>
                                            <br><span style="color: #dc3232; font-size: 12px;">• ZipArchive extension not available</span>
                                        <?php endif; ?>
                                        <?php if (!$requirements['uploads_writable']): ?>
                                            <br><span style="color: #dc3232; font-size: 12px;">• Uploads directory not writable</span>
                                        <?php endif; ?>
                                        <?php if (!$requirements['api_configured']): ?>
                                            <br><span style="color: #dc3232; font-size: 12px;">• API not configured</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
                        <button type="button" id="trigger-backup-poll" class="button button-secondary">
                            <?php _e('Trigger Backup Poll Now', 'errorvault'); ?>
                        </button>
                        <button type="button" id="view-backup-logs" class="button button-secondary">
                            <?php _e('View Recent Logs', 'errorvault'); ?>
                        </button>
                        <button type="button" id="clear-backup-logs" class="button button-secondary">
                            <?php _e('Clear Logs', 'errorvault'); ?>
                        </button>
                        <span id="backup-result"></span>
                    </div>

                    <div id="backup-logs-container" style="display: none; margin-top: 15px;">
                        <h3><?php _e('Recent Backup Logs', 'errorvault'); ?></h3>
                        <div id="backup-logs-content" style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
                            <?php _e('Loading...', 'errorvault'); ?>
                        </div>
                    </div>
                </div>

                <div class="errorvault-card">
                    <h2><?php _e('Test Connection', 'errorvault'); ?></h2>
                    <p><?php _e('Send a test error or health report to verify your connection is working.', 'errorvault'); ?></p>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="button" id="test-connection" class="button button-primary">
                            <?php _e('Test Connection (Ping)', 'errorvault'); ?>
                        </button>
                        <button type="button" id="send-test-error" class="button button-secondary">
                            <?php _e('Send Test Error', 'errorvault'); ?>
                        </button>
                        <button type="button" id="send-test-health" class="button button-secondary">
                            <?php _e('Send Test Health Report', 'errorvault'); ?>
                        </button>
                        <span id="test-result"></span>
                    </div>
                </div>

                <?php submit_button(__('Save Settings', 'errorvault')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (empty($this->settings['enabled']) || empty($this->settings['api_token'])) {
            return;
        }

        wp_add_dashboard_widget(
            'errorvault_dashboard_widget',
            __('ErrorVault Status', 'errorvault'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $stats = $this->api->get_stats();

        if (!$stats) {
            echo '<p>' . __('Unable to load statistics. Check your connection settings.', 'errorvault') . '</p>';
            return;
        }
        ?>
        <div class="errorvault-widget">
            <div class="errorvault-stats">
                <div class="stat">
                    <span class="number"><?php echo number_format($stats['total_errors']); ?></span>
                    <span class="label"><?php _e('Total Errors', 'errorvault'); ?></span>
                </div>
                <div class="stat">
                    <span class="number error"><?php echo number_format($stats['new_errors']); ?></span>
                    <span class="label"><?php _e('New', 'errorvault'); ?></span>
                </div>
                <div class="stat">
                    <span class="number"><?php echo number_format($stats['last_24h']); ?></span>
                    <span class="label"><?php _e('Last 24h', 'errorvault'); ?></span>
                </div>
            </div>
            <p>
                <a href="<?php echo esc_url(str_replace('/api/v1/errors', '/dashboard', $this->settings['api_endpoint'])); ?>" target="_blank">
                    <?php _e('View ErrorVault Dashboard', 'errorvault'); ?> &rarr;
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: Verify token
     */
    public function ajax_verify_token() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $endpoint = isset($_POST['endpoint']) ? esc_url_raw($_POST['endpoint']) : '';
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        $result = $this->api->verify_token($endpoint, $token);

        wp_send_json($result);
    }

    /**
     * AJAX: Send test error
     */
    public function ajax_test_error() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $error_data = array(
            'message' => 'Test error from ErrorVault WordPress plugin',
            'severity' => 'warning',
            'file' => __FILE__,
            'line' => __LINE__,
            'stack_trace' => 'This is a test error sent from the ErrorVault settings page.',
            'context' => array('test' => true),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'url' => admin_url('options-general.php?page=errorvault'),
            'request_method' => 'POST',
        );

        $success = $this->api->send_error($error_data);

        if ($success) {
            wp_send_json_success('Test error sent successfully');
        } else {
            wp_send_json_error('Failed to send test error');
        }
    }

    /**
     * AJAX: Clear connection failure log
     */
    public function ajax_clear_failures() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $this->api->clear_failure_log();

        wp_send_json_success('Failure log cleared successfully');
    }

    /**
     * AJAX: Trigger backup poll
     */
    public function ajax_trigger_backup_poll() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = EV_Backup_Helpers::trigger_manual_poll();

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * AJAX: Get backup logs
     */
    public function ajax_get_backup_logs() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $logs = EV_Backup_Helpers::get_recent_log_entries(100);

        if (empty($logs)) {
            wp_send_json_success(array(
                'logs' => 'No backup logs found. Logs will appear here after the first backup operation.'
            ));
        } else {
            wp_send_json_success(array(
                'logs' => implode("\n", $logs)
            ));
        }
    }

    /**
     * AJAX: Clear backup logs
     */
    public function ajax_clear_backup_logs() {
        check_ajax_referer('errorvault_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        EV_Backup_Helpers::clear_log();

        wp_send_json_success('Backup logs cleared successfully');
    }
}
