# Changelog

All notable changes to ErrorVault WordPress Plugin will be documented in this file.

## [1.4.1] - 2026-02-25

### Added
- **Backup Status UI** in admin settings page
  - Real-time status display (cron scheduled, next poll time, backup in progress)
  - System requirements check with detailed feedback
  - Manual backup poll trigger button
  - View recent backup logs (last 100 entries)
  - Clear backup logs functionality
- **AJAX Handlers** for backup operations
  - Trigger manual backup poll
  - Fetch recent backup logs
  - Clear backup logs
- **Enhanced Admin Interface**
  - Auto-refresh logs after manual trigger
  - Collapsible log viewer
  - Visual status indicators (✓/✗/⏳)
  - Inline error messages

### Changed
- Admin JavaScript updated with backup UI handlers
- Improved user feedback for backup operations

---

## [1.4.0] - 2026-02-25

### Added
- **Automated Backup System**: Complete backup functionality with API integration
  - Automatic polling for pending backups every 5 minutes
  - Database export to SQL (pure PHP, no mysqldump dependency)
  - ZIP archive creation with optional uploads folder inclusion
  - SHA256 checksum generation for backup verification
  - Multipart upload to ErrorVault API with retry logic
  - Transient locking to prevent concurrent backup runs
  - Comprehensive error handling and logging
- **Backup Components**:
  - `EV_Backup_Manager` - Main backup orchestration and API communication
  - `EV_DB_Exporter` - Shared hosting compatible database export
  - `EV_Cron` - Cron job management for backup polling
  - `EV_Backup_Helpers` - Utility functions for status, logs, and diagnostics
- **Backup Features**:
  - Database-only backups
  - Database + uploads backups
  - 500MB file size limit validation
  - Automatic cleanup of temporary files
  - Detailed logging to file and error_log
  - Manual trigger capability for testing
  - Status checking and requirement validation
  - Backup size estimation
- **Documentation**:
  - `BACKUP_IMPLEMENTATION.md` - Complete technical documentation
  - `BACKUP_QUICK_START.md` - User-friendly quick reference guide

### Changed
- Backup cron automatically scheduled on plugin activation
- Backup cron cleaned up on plugin deactivation
- Enhanced plugin initialization with backup system integration

### Technical Details
- API Endpoints: `/api/v1/backups/pending` and `/api/v1/backups/{id}/upload`
- Batched database export (100 rows per batch) for memory efficiency
- Exponential backoff retry logic (2 retries max)
- 300-second upload timeout for large files
- Logs stored in `wp-content/uploads/errorvault-backups/backup.log`

---

## [1.3.2] - 2026-02-02

### Added
- **GitHub Actions Workflow**: Automatic release packaging with properly named folders
- Release asset preference in updater (uses `errorvault-wordpress.zip` when available)
- Fallback folder renaming for GitHub zipball downloads

### Fixed
- API endpoint placeholder now matches actual portal URL (`error-vault.com`)
- CSS layout issue with settings notification overlapping version badge
- Header flex layout with proper wrapping and spacing
- WordPress settings errors now display correctly below header

### Changed
- Updated installation documentation with clear manual setup instructions
- Improved GITHUB_SETUP.md with GitHub Actions workflow explanation
- Enhanced readme.txt with both automatic and manual installation steps

---

## [1.3.1] - 2026-01-30

### Added
- **Automatic Update System**: Plugin now checks for updates from GitHub releases
- One-click updates directly from WordPress admin dashboard
- Update notifications when new versions are available

### Changed
- Improved update mechanism for seamless plugin maintenance

---

## [1.3.0] - 2026-01-30

### Added
- **Heartbeat/Ping System**: Prevents sites from stopping reporting to portal
  - Dual cron jobs running every 5 minutes (health check + heartbeat)
  - Non-blocking heartbeat requests for optimal performance
  - Lightweight ping endpoint for minimal overhead
- **Connection Failure Tracking**:
  - Automatic logging of all API connection failures
  - Consecutive failure counter
  - Admin email notifications after 5 consecutive failures (once per day)
  - Failure history stored (last 20 failures)
- **Diagnostics Dashboard**:
  - Connection status indicator (Active/Inactive)
  - Scheduled cron job information
  - Consecutive failures count
  - Recent failure history table
  - Clear failure log button
- **Test Connection Feature**:
  - Quick connectivity test button
  - Blocking requests for testing to detect actual failures
  - Detailed error messages for troubleshooting

### Fixed
- `open_basedir` restriction warning when accessing `/proc/cpuinfo`
- Added `@` error suppression for system file checks with proper fallbacks

### Changed
- Heartbeat implementation uses direct action hook pattern for simplicity
- Improved error handling with specific failure reasons
- Better diagnostics for troubleshooting connection issues

---

## [1.2.2] - 2026-01-28

### Added
- **URL Tracking in Health Monitoring**:
  - Top targeted URLs now included in high request rate alerts
  - Traffic spike alerts show which endpoints are being hit
  - Up to 10 URLs tracked per alert
- **Enhanced Portal Display**:
  - Formatted URL and IP tables in alert details
  - Visual badges showing request counts
  - Truncated URLs with hover tooltips

### Changed
- Health alert data structure now includes `top_urls` field
- Portal view updated with dedicated sections for URLs and IPs

---

## [1.2.0] - 2026-01-27

### Added
- **Comprehensive Server Health Monitoring**:
  - CPU load monitoring with configurable thresholds
  - Memory usage tracking and alerts
  - Disk space monitoring
  - Request rate monitoring for DDoS detection
  - Traffic spike detection
- **Health Alert System**:
  - Configurable alert thresholds for CPU, memory, and traffic
  - Alert cooldown periods to prevent spam
  - Severity levels (warning, critical)
  - Real-time alerts sent to portal
- **Health Reporting**:
  - Periodic health reports every 5 minutes
  - Dashboard showing CPU, memory, disk, and traffic metrics
  - Test health report functionality in admin
- **Portal Integration**:
  - Health alerts table and management
  - Health reports with historical data
  - Visual charts for metrics over time

### Changed
- Added health monitoring settings section to admin page
- Improved admin UI with card-based layout

---

## [1.0.0] - 2025-12-01

### Added
- Initial release of ErrorVault WordPress Plugin
- Real-time error logging to ErrorVault portal
- Automatic error grouping by message and file
- Full stack traces for debugging
- Configurable severity levels (notices, warnings, errors, critical, fatal)
- Batch sending to minimize performance impact
- Exclude patterns to filter out known issues
- Dashboard widget showing error statistics
- Non-blocking API requests
- Connection verification tool
- Test error sending functionality

### Features
- Works with any hosting provider
- Asynchronous error sending
- Minimal performance impact
- Easy configuration via WordPress admin
- API token authentication
- Comprehensive error context (URL, user agent, request method, etc.)
