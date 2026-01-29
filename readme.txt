=== ErrorVault ===
Contributors: errorvault
Tags: error logging, debugging, error monitoring, php errors, developer tools
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
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

1. Upload the `errorvault` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > ErrorVault to configure your API endpoint and token
4. Click "Verify Connection" to test the connection
5. Enable logging and save settings

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

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of ErrorVault for WordPress.
