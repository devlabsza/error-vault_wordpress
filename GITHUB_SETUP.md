# GitHub Update System Setup Guide

This guide explains how to set up automatic updates for the ErrorVault WordPress plugin using GitHub releases.

## Prerequisites

1. A GitHub repository for the plugin
2. Access to create releases on the repository

## Configuration Steps

### 1. Update the Updater Class

Edit `/includes/class-errorvault-updater.php` and update these lines:

```php
private $github_user = 'yourusername'; // Change to your GitHub username or organization
private $github_repo = 'errorvault-wordpress'; // Change to your repository name
```

**Example:**
```php
private $github_user = 'errorvault';
private $github_repo = 'errorvault-wordpress';
```

### 2. Create a GitHub Release

When you're ready to release a new version:

1. **Tag the release** with the version number (e.g., `1.3.1`, `1.4.0`)
2. **Create a release** on GitHub:
   - Go to your repository
   - Click "Releases" → "Create a new release"
   - Choose or create a tag (e.g., `1.3.1`)
   - Set the release title (e.g., `Version 1.3.1`)
   - Add release notes in the description (markdown supported)

**Example Release Notes:**
```markdown
## What's New in 1.3.1

### Added
- Automatic update system from GitHub releases
- One-click updates from WordPress admin
- Update notifications

### Installation
Download the plugin and install via WordPress admin or upload to `/wp-content/plugins/`
```

3. **Publish the release**

### 3. Important: GitHub Folder Naming

**Issue**: When GitHub creates a release zipball, it names the extracted folder as `repo-name-tag` (e.g., `error-vault_wordpress-1.3.1`). This causes WordPress to see each version as a different plugin.

**Recommended Solution: GitHub Actions** (Automatic)

The repository includes a GitHub Action (`.github/workflows/release.yml`) that automatically:
1. Creates a properly named `errorvault-wordpress` folder
2. Packages it as `errorvault-wordpress.zip`
3. Attaches it to the release as an asset

When you create a release, the Action runs automatically and the updater will use this properly named zip file. **No folder renaming needed!**

**Fallback Solution**: If the GitHub Action hasn't run or the asset is missing, the updater will:
1. Fall back to using GitHub's zipball
2. Automatically detect and rename the folder during installation
3. Ensure the plugin updates correctly

**For Manual Installation**: If you download a release zip manually:
- **Preferred**: Download the `errorvault-wordpress.zip` asset from the release (if available)
- **Alternative**: Download the source code zip and rename the extracted folder from `error-vault_wordpress-1.3.1` to `errorvault-wordpress` before uploading

### 4. How Updates Work

Once configured, the plugin will:

1. **Check for updates** every 12 hours (cached)
2. **Compare versions** using semantic versioning
3. **Show update notification** in WordPress admin when available
4. **Allow one-click update** from the Plugins page
5. **Automatically handle folder renaming** during update process

### 4. Testing the Update System

To test updates:

1. Set the plugin version to something lower (e.g., `1.3.0`)
2. Create a GitHub release with a higher version (e.g., `1.3.1`)
3. Go to WordPress Admin → Plugins
4. You should see an update notification
5. Click "Update Now" to test the update process

### 5. Clearing the Update Cache

If you need to force a check for updates:

```php
// Add this to your functions.php temporarily
delete_transient('errorvault_github_release');
```

Or use the WordPress Transients API to clear the cache.

## Release Checklist

Before creating a new release:

- [ ] Update version number in `errorvault.php` header
- [ ] Update `ERRORVAULT_VERSION` constant
- [ ] Update `readme.txt` stable tag
- [ ] Add changelog entry to `readme.txt`
- [ ] Add changelog entry to `CHANGELOG.md`
- [ ] Test the plugin thoroughly
- [ ] Commit and push changes
- [ ] Create GitHub release with proper tag
- [ ] Add release notes
- [ ] Publish release

## Troubleshooting

### Updates Not Showing

1. Check that GitHub username and repo name are correct in `class-errorvault-updater.php`
2. Verify the GitHub release is published (not draft)
3. Clear the transient cache
4. Check WordPress debug log for API errors

### Update Fails

1. Ensure the GitHub release has a valid zipball
2. Check file permissions on the plugins directory
3. Verify WordPress has write access
4. Check for PHP errors in debug log

## Security Notes

- The plugin uses GitHub's public API (no authentication required for public repos)
- Updates are served directly from GitHub's CDN
- SSL/TLS is enforced for all API calls
- The update system respects WordPress's built-in security checks

## Advanced Configuration

### Custom Update Interval

To change the 12-hour cache interval, edit `class-errorvault-updater.php`:

```php
// Change from 12 hours to 6 hours
set_transient($transient_key, $release, 6 * HOUR_IN_SECONDS);
```

### Private Repositories

For private repositories, you'll need to add GitHub authentication:

```php
$response = wp_remote_get($this->github_api_url, array(
    'timeout' => 10,
    'headers' => array(
        'Accept' => 'application/vnd.github.v3+json',
        'Authorization' => 'token YOUR_GITHUB_TOKEN', // Add this
    ),
));
```

**Note:** Store the token securely, not in the code!
