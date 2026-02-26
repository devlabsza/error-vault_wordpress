# Changelog

All notable changes to ErrorVault WordPress Plugin will be documented in this file.

## [1.4.9] - 2026-02-26

### Fixed
- **Critical: Database Export Timeout on Very Large Databases**
  - PHP export was being killed silently by web server
  - Backups never completed, always showed "attempt 1/3"
  - Failure counter couldn't increment because export never threw exception

### Added
- **mysqldump Support (Primary Export Method)**
  - Automatically uses `mysqldump` if available (10-100x faster)
  - Falls back to PHP export if mysqldump not found
  - Handles large databases that timeout in PHP
  - Searches common paths: `/usr/bin/mysqldump`, `/usr/local/bin/mysqldump`, etc.
  - Uses `--single-transaction` and `--quick` for optimal performance

- **Fixed Failure Counter Variable Scope**
  - Re-reads failure count in catch block
  - Properly increments and saves to transient
  - Now correctly shows "attempt 1/3", "attempt 2/3", "attempt 3/3"
  - Stops after 3 failures and marks backup as failed on portal

### Changed
- **Export Method Priority**
  1. Try `mysqldump` first (fastest, handles any size)
  2. Fall back to PHP export if mysqldump unavailable
  3. Log which method is being used

### Performance
- **mysqldump Benefits**
  - Can export multi-GB databases in minutes
  - No PHP memory limits
  - No execution time limits
  - Native MySQL performance
  - Handles millions of rows efficiently

---

## [1.4.8] - 2026-02-26

### Fixed
- **Database Export Timeout for Very Large Databases**
  - Removed time limits (set to 0 = unlimited)
  - Increased memory limit to 1024M
  - Reduced batch size from 50 to 25 rows for better memory management
  - Added memory cleanup after each batch

### Added
- **Better Progress Tracking for Large Exports**
  - More frequent progress updates (every 250 rows instead of 500)
  - Log total export time in minutes instead of seconds
  - Log final SQL file size after export
  - Warning message if export takes longer than 20 minutes
  - Better visibility into which tables are being processed

### Changed
- **Batch Processing Optimization**
  - Reduced batch size to 25 rows (was 50) to prevent memory exhaustion
  - Added `unset($rows)` after each batch to free memory immediately
  - Reset time limit for each table to prevent mid-table timeouts
  - More aggressive memory management for multi-million row tables

### Performance
- **Unlimited Execution Time**
  - `set_time_limit(0)` for both backup manager and exporter
  - Allows backups to run as long as needed for very large databases
  - Better suited for shared hosting with large databases
  - Prevents timeout errors on databases with millions of rows

---

## [1.4.7] - 2026-02-26

### Fixed
- **Infinite Retry Loop on Failed Backups**
  - Backups now stop after 3 failed attempts
  - Portal is notified when backup permanently fails
  - Failure count tracked per backup ID
  - Prevents endless retry cycles

### Added
- **Failure Tracking System**
  - Track failure count per backup ID using transients
  - Log attempt number (1/3, 2/3, 3/3)
  - Auto-cancel after 3 failures
  - Clear failure count on successful backup

- **Portal Notification on Failure**
  - New `mark_backup_failed()` method
  - POST to `/api/v1/backups/{id}/fail`
  - Sends error message and timestamp
  - Allows portal to update backup status

### Changed
- Backup attempts now show "attempt X/3" in logs
- After 3 failures, backup is marked as failed and removed from queue
- Failure transients expire after 24 hours
- Better logging for failure tracking

### Backend API Required
- **New Endpoint:** `POST /api/v1/backups/{id}/fail`
  - Marks backup as permanently failed
  - Request body: `{"error": "error message", "failed_at": "timestamp"}`
  - Should update backup status to "failed" and remove from pending queue

---

## [1.4.6] - 2026-02-26

### Fixed
- **ZIP Archive Creation Failure**
  - Added file existence check before adding to ZIP
  - Added file readability check
  - Better error logging with actual file paths
  - Log SQL file size before adding to archive

### Added
- **Enhanced Diagnostic Logging**
  - Log when SQL file doesn't exist at expected path
  - Log when SQL file exists but isn't readable
  - Log SQL file size before ZIP creation
  - More specific error messages for troubleshooting

### Changed
- Improved error messages to include actual file paths
- Better visibility into ZIP creation process

---

## [1.4.5] - 2026-02-26

### Fixed
- **Critical: Plugin Deactivation After Update**
  - Fixed "Plugin file does not exist" error after auto-update
  - Added plugin-specific check in `after_install` hook
  - Added `upgrader_clear_destination` filter for better update handling
  - Set `destination_name` to ensure WordPress recognizes plugin folder
  - Enhanced logging to debug update process

### Changed
- `after_install` now only processes updates for errorvault-wordpress plugin
- Added `clear_destination` filter to handle pre-update cleanup
- Improved error logging with plugin basename tracking

### Technical Details
- Check `$hook_extra['plugin']` matches `$this->plugin_slug` before processing
- Set `$result['destination_name']` to 'errorvault-wordpress'
- Log plugin basename during update process
- Ensures WordPress maintains plugin activation state after update

---

## [1.4.4] - 2026-02-26

### Fixed
- **HTTP 413 Upload Errors (Payload Too Large)**
  - Replaced single-file upload with chunked multipart upload
  - Files are now uploaded in 5MB chunks to avoid server size limits
  - Fixes "HTTP 413" errors on backups larger than ~10MB

### Added
- **Chunked Multipart Upload System**
  - Three-phase upload: initiate, upload chunks, complete
  - 5MB chunk size for optimal performance
  - Progress logging every 5 chunks
  - Automatic abort on failure with cleanup
  - Per-chunk retry logic with exponential backoff (up to 3 retries)
  - ETag tracking for each uploaded part

### Changed
- Upload endpoint structure:
  - `POST /api/v1/backups/{id}/upload/initiate` - Start multipart upload
  - `POST /api/v1/backups/{id}/upload/part` - Upload individual chunk
  - `POST /api/v1/backups/{id}/upload/complete` - Finalize upload
  - `POST /api/v1/backups/{id}/upload/abort` - Cancel failed upload
- Improved error messages with specific failure points
- Better handling of 409 Conflict (backup no longer accepting uploads)
- Reduced memory usage by streaming file in chunks instead of loading entire file

### Technical Details
- Chunk size: 5MB (5 * 1024 * 1024 bytes)
- Max retries per chunk: 3 with exponential backoff (2^n seconds)
- Chunk timeout: 120 seconds
- Initiate/complete timeout: 30-60 seconds
- Headers: X-Upload-ID, X-Part-Number for chunk identification

---

## [1.4.3] - 2026-02-26

### Fixed
- **GitHub Auto-Update Installation Issues**
  - Fixed plugin slug handling to use hardcoded 'errorvault-wordpress' instead of dynamic dirname()
  - Added 'plugin' field to update data for proper WordPress plugin identification
  - Strip 'v' prefix from version tags for proper version comparison
  - Improved after_install hook to handle both release assets and zipball formats
  - Fixed folder renaming logic to properly handle extracted plugin directory

### Added
- **Debug Logging for Updates**
  - Log when release asset is found vs zipball fallback
  - Log version comparison (current -> new)
  - Log folder renaming operations in after_install
  - Log success/failure of folder move operations
  - Helps troubleshoot update installation issues

### Changed
- Version comparison now strips 'v' prefix from GitHub tags (v1.4.3 -> 1.4.3)
- Plugin info now uses release asset URL instead of always using zipball
- Improved error handling in after_install with detailed logging

---

## [1.4.2] - 2026-02-25

### Fixed
- **Database Export Timeout Issues**
  - Extended PHP execution time limit to 600 seconds (10 minutes)
  - Increased memory limit to 512M for large databases
  - Reduced batch size from 100 to 50 rows for better memory management
  - Added execution time extension in both backup manager and exporter

### Added
- **Enhanced Progress Logging**
  - Detailed per-table export progress with row counts and percentages
  - Progress updates every 500 rows for large tables
  - Time tracking for database export, archive creation, and total backup
  - PHP configuration logging (max_execution_time, memory_limit)
  - Warning when backup exceeds 5 minutes
- **Better Error Reporting**
  - Elapsed time included in all error messages
  - Detailed timing for each backup phase
  - Microtime precision for table export timing

### Changed
- Batch size reduced from 100 to 50 rows for improved performance
- Progress logging frequency increased (every 500 rows instead of 1000)
- Large tables (>1000 rows) now show detailed progress updates

---

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
