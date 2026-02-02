=== ErrorVault ===
Contributors: errorvault
Tags: error logging, debugging, error monitoring, php errors, developer tools, server health
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send PHP errors to your centralized ErrorVault dashboard for easy monitoring across all your WordPress sites.

== Description ==

ErrorVault is a centralized error monitoring solution for WordPress. Instead of hunting through server log files, all your PHP errors, warnings, and notices are automatically sent to your ErrorVault dashboard.

**Features:**

* Real-time error logging to your ErrorVault portal
* Automatic grouping of identical errors
* Full stack traces for easy debugging
* Configurable severity levels (notices, warnings, errors, critical, fatal)
* Batch sending to minimize performance impact
* Exclude patterns to filter out known issues
* Dashboard widget showing error statistics
* Works with any hosting provider

**How It Works:**

1. Create an account at your ErrorVault portal
2. Add your WordPress site and get an API token
3. Install this plugin and enter your API endpoint and token
4. Errors are automatically sent to your dashboard!

== Installation ==

**From WordPress Admin (Recommended):**
1. Go to Plugins > Add New > Upload Plugin
2. Choose the plugin zip file and click "Install Now"
3. Activate the plugin
4. Go to Settings > ErrorVault to configure your API endpoint and token
5. Click "Verify Connection" to test the connection
6. Enable logging and save settings

**Manual Installation:**
1. Download the plugin zip from GitHub releases
2. Extract the zip file
3. **Important:** Rename the extracted folder to `errorvault-wordpress` (GitHub names it as `error-vault_wordpress-X.X.X`)
4. Upload the `errorvault-wordpress` folder to `/wp-content/plugins/`
5. Activate the plugin through the 'Plugins' menu in WordPress
6. Go to Settings > ErrorVault to configure your API endpoint and token
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

Log in to your ErrorVault portal, add your site, and the API token will be displayed in the site settings.

== Changelog ==

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
* Real-time error logging to ErrorVault portal
* Automatic error grouping
* Full stack traces
* Configurable severity levels
* Batch sending support
* Exclude patterns
* Dashboard widget

== Upgrade Notice ==

= 1.3.1 =
Automatic updates from GitHub! Plugin now checks for new versions and allows one-click updates.

= 1.3.0 =
Major reliability update! Heartbeat system prevents sites from stopping reporting. Includes diagnostics dashboard and connection failure tracking.

= 1.2.2 =
Enhanced health monitoring with URL tracking during traffic spikes and attacks.

= 1.2.0 =
Server health monitoring added! Track CPU, memory, disk usage, and detect potential DDoS attacks.

= 1.0.0 =
Initial release of ErrorVault for WordPress.
