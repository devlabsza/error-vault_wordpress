=== Error-Vault ===
Contributors: errorvault
Tags: error logging, debugging, error monitoring, php errors, developer tools, server health
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send PHP errors to your centralized Error-Vault dashboard for easy monitoring across all your WordPress sites.

== Description ==

Error-Vault is a centralized error monitoring solution for WordPress. Instead of hunting through server log files, all your PHP errors, warnings, and notices are automatically sent to your Error-Vault dashboard.

**Features:**

* Real-time error logging to your Error-Vault portal
* Automatic grouping of identical errors
* Full stack traces for easy debugging
* Configurable severity levels (notices, warnings, errors, critical, fatal)
* Batch sending to minimize performance impact
* Exclude patterns to filter out known issues
* Dashboard widget showing error statistics
* Works with any hosting provider

**How It Works:**

1. Create an account at your Error-Vault portal
2. Add your WordPress site and get an API token
3. Install this plugin and enter your API endpoint and token
4. Errors are automatically sent to your dashboard!

== Installation ==

**From WordPress Admin (Recommended):**
1. Go to Plugins > Add New > Upload Plugin
2. Choose the plugin zip file and click "Install Now"
3. Activate the plugin
4. Go to Settings > Error-Vault to configure your API endpoint and token
5. Click "Verify Connection" to test the connection
6. Enable logging and save settings

**Manual Installation:**
1. Download the plugin zip from GitHub releases
2. Extract the zip file
3. **Important:** Rename the extracted folder to `errorvault-wordpress` (GitHub names it as `error-vault_wordpress-X.X.X`)
4. Upload the `errorvault-wordpress` folder to `/wp-content/plugins/`
5. Activate the plugin through the 'Plugins' menu in WordPress
6. Go to Settings > Error-Vault to configure your API endpoint and token
7. Click "Verify Connection" to test the connection
8. Enable logging and save settings

**Note:** The folder must be named `errorvault-wordpress` for updates to work correctly.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =

No. The plugin sends errors asynchronously (non-blocking) so it doesn't affect page load times. You can also enable batch mode to send multiple errors at once.

= What errors are logged? =

By default, the plugin logs PHP errors, critical errors, and fatal errors. You can optionally enable logging of warnings and notices in the settings.

= Can I exclude certain errors? =

Yes! In the settings, you can add exclude patterns. Any error message containing these strings will be ignored.

= Where do I get my API token? =

Log in to your Error-Vault portal, add your site, and the API token will be displayed in the site settings.

== Changelog ==

= 1.10.0 =
* Security: database dumps, full-site backup archives (which include wp-config.php) and the backup log are now kept in a private folder, not the public uploads folder.
* Security: the scan detects webshells it missed (such as `echo shell_exec(...)`, short-tag code and compressed PHAR files) and no longer accepts a backdoored wp-includes/version.php as verified core.
* Security: error reports no longer include call arguments, such as passwords, from exception stack traces.
* Restore and undo: undo no longer deletes plugins, themes and uploads after a WordPress update; a restore that fails part-way puts everything back; the previous undo point is kept until a new restore succeeds; another install's tables in a shared database are never touched; symlinked plugins, .git and node_modules folders are kept.
* WordPress's fatal-error protection (the critical error page and recovery mode) works again on sites with Error-Vault enabled.
* Remote actions from the Error-Vault dashboard, including restore and undo, are now opt-in. wp-admin shows a notice when a request was refused.
* The scan now also reviews themes, WordPress drop-ins and PHP in mu-plugin subdirectories.

= 1.9.1 =
* Fix Wordfence and AIOS loader recognition and expand official WP-CLI checksums.
* Recognize WP Hide environment data and avoid treating uploads location alone as an infection indicator.
* Explain failed tool verification and report actual cron inspection counts.

= 1.9.0 =
* Verify AIOS bootstrap and official WP-CLI files using content evidence to reduce false alerts.
* Improve uploads and root PHP inspection, including double extensions.
* Inspect suspicious WP-Cron callbacks and arguments and readable system cron entries.
* Report incomplete scan coverage explicitly and invalidate reviewed-file trust when contents change.

= 1.3.2 =
* Fixed API endpoint placeholder to match actual portal URL (error-vault.com)
* Fixed CSS layout issue with settings notification overlapping version badge
* Added GitHub Actions workflow for automatic release packaging
* Improved updater to prefer properly named release assets
* Added fallback folder renaming for GitHub zipball downloads
* Updated installation documentation with clear manual setup instructions

= 1.3.1 =
* Added automatic update system from GitHub releases
* Plugin now checks for updates and notifies when new versions are available
* Seamless one-click updates directly from WordPress admin

= 1.3.0 =
* **Major Reliability Improvements**
* Added heartbeat/ping system to prevent sites from stopping reporting
* Dual cron jobs: Health check + Heartbeat (every 5 minutes each)
* Connection failure tracking with automatic logging
* Admin email notifications after 5 consecutive connection failures
* New diagnostics dashboard showing connection status and cron schedules
* Test Connection button for quick connectivity verification
* Connection failure history table with clear log functionality
* Non-blocking heartbeat requests for optimal performance
* Fixed open_basedir restriction warning for /proc/cpuinfo access

= 1.2.2 =
* Added URL tracking to health monitoring alerts
* Health alerts now include top targeted URLs during high traffic
* Enhanced portal display with formatted URL and IP tables
* Improved visual presentation of traffic spike data

= 1.2.0 =
* Added comprehensive server health monitoring
* CPU load monitoring with configurable thresholds
* Memory usage tracking and alerts
* Request rate monitoring for DDoS detection
* Traffic spike detection
* Configurable alert cooldowns
* Health report dashboard in portal
* Test health report functionality

= 1.0.0 =
* Initial release
* Real-time error logging to Error-Vault portal
* Automatic error grouping
* Full stack traces
* Configurable severity levels
* Batch sending support
* Exclude patterns
* Dashboard widget

== Upgrade Notice ==

= 1.10.0 =
Important security and restore fixes. Remote actions from the dashboard, including backup restore and undo, stay off until you turn them on in Settings > Error-Vault. Run a fresh security scan after updating.

= 1.9.1 =
Fixes known-tool recognition gaps in 1.9.0. Run a fresh scan after updating. Unverified executable uploads still require review.

= 1.9.0 =
Improves security scan accuracy and adds cron inspection. Run a fresh scan after updating; partial coverage and review findings do not establish that a site is clean.

= 1.3.1 =
Automatic updates from GitHub! Plugin now checks for new versions and allows one-click updates.

= 1.3.0 =
Major reliability update! Heartbeat system prevents sites from stopping reporting. Includes diagnostics dashboard and connection failure tracking.

= 1.2.2 =
Enhanced health monitoring with URL tracking during traffic spikes and attacks.

= 1.2.0 =
Server health monitoring added! Track CPU, memory, disk usage, and detect potential DDoS attacks.

= 1.0.0 =
Initial release of Error-Vault for WordPress.
