# ErrorVault WordPress Backup Implementation

## Overview

This document describes the automated backup functionality implemented in the ErrorVault WordPress plugin. The backup system polls the ErrorVault API for pending backup requests, creates database and file archives, and uploads them to the backend.

## Architecture

### Core Components

1. **`class-ev-backup-manager.php`** - Main backup orchestration
   - Polls API for pending backups
   - Coordinates backup creation and upload
   - Handles errors and retries
   - Manages transient locks to prevent concurrent backups

2. **`class-ev-db-exporter.php`** - Database export functionality
   - Pure PHP implementation (no mysqldump dependency)
   - Exports all WordPress tables to SQL format
   - Batched processing for memory efficiency
   - Shared hosting compatible

3. **`class-ev-cron.php`** - Cron job management
   - Schedules backup polling every 5 minutes
   - Manages cron lifecycle (activation/deactivation)
   - Provides manual trigger capability

4. **`class-ev-backup-helpers.php`** - Utility functions
   - Log management
   - Status checking
   - Requirement validation
   - Size estimation

## API Contract Implementation

### Poll for Pending Backup
**Endpoint:** `GET /api/v1/backups/pending`  
**Header:** `X-API-Token: {site_api_token}`

**Response:**
```json
{
  "data": {
    "has_pending_backup": true,
    "backup": {
      "id": 123,
      "include_uploads": true
    }
  }
}
```

**Implementation:** `EV_Backup_Manager::poll_pending_backup()`

### Upload Backup Archive
**Endpoint:** `POST /api/v1/backups/{backup_id}/upload`  
**Header:** `X-API-Token: {site_api_token}`

**Multipart Fields:**
- `backup_archive` (file, required) - ZIP archive
- `checksum` (string, optional) - SHA256 hash
- `metadata` (JSON, optional) - Backup metadata

**Implementation:** `EV_Backup_Manager::upload_archive()`

**File Size Limit:** 500MB (512,000 KB)

## Backup Process Flow

### 1. Polling (Every 5 Minutes)
```
Cron Event → EV_Cron::poll_pending_backup()
           → EV_Backup_Manager::poll_pending_backup()
           → Check transient lock
           → API GET /api/v1/backups/pending
           → If pending backup exists → run_backup()
```

### 2. Backup Execution
```
run_backup($backup_id, $include_uploads)
  ├─ Create temporary directory
  ├─ Export database to SQL
  │  └─ EV_DB_Exporter::export_to_sql()
  │     ├─ SHOW TABLES
  │     ├─ For each table:
  │     │  ├─ DROP TABLE IF EXISTS
  │     │  ├─ CREATE TABLE
  │     │  └─ Batched INSERT statements (100 rows)
  │     └─ Write to .sql file
  ├─ Build ZIP archive
  │  ├─ Add database.sql
  │  └─ If include_uploads:
  │     └─ Recursively add wp-content/uploads/
  ├─ Compute SHA256 checksum
  ├─ Upload to API
  │  ├─ Multipart POST with retry logic
  │  ├─ Max retries: 2
  │  ├─ Exponential backoff
  │  └─ Stop on 409 (no longer accepting)
  └─ Cleanup temporary files
```

### 3. Error Handling
- **Transient Lock:** Prevents concurrent backup runs (10-minute timeout)
- **API Errors:** Logged with detailed error messages
- **File Size Validation:** Checks against 500MB limit before upload
- **Retry Logic:** 2 retries with exponential backoff
- **Cleanup:** Always removes temporary files (success or failure)

## File Locations

### Temporary Files
- **Location:** `wp-content/uploads/errorvault-backups/tmp/`
- **Files:**
  - `backup-{id}.sql` - Database export
  - `backup-{id}.zip` - Final archive
- **Cleanup:** Automatically deleted after upload

### Logs
- **Location:** `wp-content/uploads/errorvault-backups/backup.log`
- **Format:** `[YYYY-MM-DD HH:MM:SS] Message`
- **Also logged to:** PHP error_log

## Configuration

### API Settings
The backup system uses the existing ErrorVault API configuration:
- **API Endpoint:** Configured in plugin settings
- **API Token:** Configured in plugin settings

The backup endpoints are derived from the error endpoint:
```php
// If error endpoint is: https://portal.com/api/v1/errors
// Backup endpoints become:
// - https://portal.com/api/v1/backups/pending
// - https://portal.com/api/v1/backups/{id}/upload
```

### Cron Schedule
- **Interval:** Every 5 minutes
- **Hook:** `ev_backup_poll_event`
- **Auto-scheduled:** On plugin activation
- **Cleanup:** On plugin deactivation

## Database Export Details

### Pure PHP Implementation
The database exporter does NOT rely on `mysqldump`, making it compatible with shared hosting environments.

### Export Process
1. **Get all tables:** `SHOW TABLES`
2. **For each table:**
   - Get structure: `SHOW CREATE TABLE`
   - Get row count: `SELECT COUNT(*)`
   - Export data in batches (100 rows per batch)
   - Write INSERT statements

### Memory Efficiency
- Batched processing (100 rows at a time)
- Streaming writes to file
- Progress logging every 1000 rows

### SQL Format
```sql
-- Header with metadata
DROP TABLE IF EXISTS `wp_posts`;
CREATE TABLE `wp_posts` (...);
INSERT INTO `wp_posts` (...) VALUES
  (...),
  (...);
```

## ZIP Archive Structure

### Database Only
```
backup-{id}.zip
└── database.sql
```

### Database + Uploads
```
backup-{id}.zip
├── database.sql
└── uploads/
    ├── 2024/
    │   ├── 01/
    │   └── 02/
    └── ...
```

## Upload Implementation

### Multipart Form Data
The upload uses standard multipart/form-data encoding:

```http
POST /api/v1/backups/123/upload
Content-Type: multipart/form-data; boundary=----WebKitFormBoundary...
X-API-Token: {token}

------WebKitFormBoundary...
Content-Disposition: form-data; name="backup_archive"; filename="backup-123.zip"
Content-Type: application/zip

{binary ZIP data}
------WebKitFormBoundary...
Content-Disposition: form-data; name="checksum"

{sha256 hash}
------WebKitFormBoundary...
Content-Disposition: form-data; name="metadata"

{"wp_version":"6.4","php_version":"8.1",...}
------WebKitFormBoundary...--
```

### Metadata Included
```json
{
  "wp_version": "6.4.2",
  "php_version": "8.1.0",
  "include_uploads": true,
  "file_size": 12345678,
  "site_url": "https://example.com",
  "site_name": "My WordPress Site",
  "created_at": "2024-02-25 14:30:00"
}
```

### Retry Logic
- **Max Retries:** 2
- **Backoff:** 2 seconds × retry_count
- **Stop Conditions:**
  - HTTP 409 (Conflict) - backup no longer accepting uploads
  - Max retries exceeded
  - Success (HTTP 2xx)

### Timeout
- **Upload Timeout:** 300 seconds (5 minutes)
- **Poll Timeout:** 20 seconds

## Error Scenarios & Handling

### 1. No API Configuration
- **Detection:** Empty endpoint or token
- **Action:** Skip polling, log message
- **Log:** "Backup polling skipped: API not configured"

### 2. Backup Already Running
- **Detection:** `ev_backup_lock` transient exists
- **Action:** Skip this poll cycle
- **Log:** "Backup already in progress, skipping poll"

### 3. API Unreachable
- **Detection:** `wp_remote_get()` returns WP_Error
- **Action:** Log error, release lock, exit
- **Log:** "Poll failed: {error_message}"

### 4. Unauthorized (401)
- **Detection:** HTTP 401 response
- **Action:** Log error, release lock, exit
- **Log:** "Poll failed: Unauthorized (401) - check API token"

### 5. No Pending Backup
- **Detection:** `has_pending_backup` is false
- **Action:** Release lock, exit normally
- **Log:** "No pending backup found"

### 6. Database Export Failure
- **Detection:** `export_to_sql()` returns false
- **Action:** Throw exception, cleanup files
- **Log:** "Database export failed"

### 7. ZIP Creation Failure
- **Detection:** ZipArchive errors
- **Action:** Throw exception, cleanup files
- **Log:** "Archive creation failed: {error}"

### 8. File Size Exceeds Limit
- **Detection:** File size > 512,000 KB
- **Action:** Throw exception, cleanup files
- **Log:** "Backup file exceeds 500MB limit ({size}MB)"

### 9. Upload Failure
- **Detection:** HTTP error or WP_Error
- **Action:** Retry with backoff, cleanup on final failure
- **Log:** "Upload failed: {error}"

### 10. Conflict (409)
- **Detection:** HTTP 409 response
- **Action:** Stop retrying, cleanup files
- **Log:** "Backup no longer accepting uploads (409 Conflict)"

## Testing Checklist

### ✅ Phase 1 Tests

#### Basic Functionality
- [ ] **DB-only backup**
  - Request from dashboard with uploads unchecked
  - Confirm backup completes
  - Verify archive contains only database.sql
  - Confirm successful upload

- [ ] **DB + uploads backup**
  - Request with uploads checked
  - Confirm larger artifact
  - Verify archive contains database.sql and uploads/
  - Confirm completion

- [ ] **No pending backup**
  - Poll returns fast
  - No work done
  - No errors logged

#### Error Handling
- [ ] **Large file (>500MB)**
  - Upload fails with clear error
  - Plugin logs error message
  - Temporary files cleaned up

- [ ] **Bad token**
  - 401 handled gracefully
  - No crash
  - Error logged

- [ ] **API unreachable**
  - Network error handled
  - No crash
  - Error logged

#### Concurrency
- [ ] **Concurrent backup prevention**
  - Lock prevents multiple simultaneous backups
  - Lock released after completion
  - Lock timeout works (10 minutes)

#### Cleanup
- [ ] **Temporary file cleanup**
  - SQL file deleted after success
  - ZIP file deleted after success
  - Files deleted on failure

### Manual Testing Commands

#### Trigger Manual Poll
```php
// In WordPress admin or wp-cli
EV_Backup_Helpers::trigger_manual_poll();
```

#### Check Backup Status
```php
$status = EV_Backup_Helpers::get_backup_status();
print_r($status);
```

#### View Recent Logs
```php
$logs = EV_Backup_Helpers::get_recent_log_entries(50);
foreach ($logs as $log) {
    echo $log . "\n";
}
```

#### Estimate Backup Size
```php
$size = EV_Backup_Helpers::estimate_backup_size(true);
print_r($size);
```

#### Check Requirements
```php
$requirements = EV_Backup_Helpers::check_requirements();
print_r($requirements);
```

## System Requirements

### Required
- ✅ **PHP ZipArchive Extension** - For creating ZIP archives
- ✅ **Writable Uploads Directory** - For temporary files
- ✅ **API Configuration** - Valid endpoint and token

### Recommended
- **PHP Memory Limit:** 256M or higher
- **Max Execution Time:** 300 seconds or higher
- **Disk Space:** 2x the size of database + uploads

### Compatibility
- **WordPress:** 5.0+
- **PHP:** 7.4+
- **Hosting:** Shared hosting compatible (no shell access required)

## Troubleshooting

### Backups Not Running
1. Check cron is scheduled: `wp_next_scheduled('ev_backup_poll_event')`
2. Check API configuration in plugin settings
3. Review backup log: `wp-content/uploads/errorvault-backups/backup.log`
4. Check PHP error log

### Backup Fails Immediately
1. Check system requirements: `EV_Backup_Helpers::check_requirements()`
2. Verify uploads directory is writable
3. Check available disk space
4. Review error logs

### Upload Fails
1. Check file size (must be < 500MB)
2. Verify API token is valid
3. Check network connectivity
4. Review upload timeout settings

### Large Backup Times Out
1. Increase PHP `max_execution_time`
2. Increase PHP `memory_limit`
3. Consider excluding uploads if not needed
4. Check for slow database queries

## Security Considerations

### File Permissions
- Temporary files created with default WordPress permissions
- Files deleted immediately after upload
- Log files stored in uploads directory (protected by .htaccess)

### API Authentication
- All requests use X-API-Token header
- Token stored in WordPress options (encrypted at rest)
- No token exposure in logs

### Data Protection
- Backups contain sensitive database information
- Transmitted over HTTPS only
- Deleted from server after upload
- No backup retention on WordPress server

## Performance Optimization

### Database Export
- Batched queries (100 rows per batch)
- Streaming writes to disk
- No full table loads into memory

### File Operations
- Recursive directory iteration (memory efficient)
- Progress logging to track large operations
- Chunked file reading for uploads

### Cron Efficiency
- Quick exit if no pending backup
- Transient lock prevents wasted cycles
- Non-blocking for WordPress operations

## Future Enhancements (Not in Phase 1)

- [ ] Compression level configuration
- [ ] Selective table backup
- [ ] Incremental backups
- [ ] Backup scheduling from dashboard
- [ ] Email notifications on completion/failure
- [ ] Backup history/statistics
- [ ] Restore functionality
- [ ] Multi-part uploads for files >500MB
- [ ] Progress tracking for long-running backups

## Code References

### Main Entry Points
- **Cron Registration:** `@errorvault.php:50` - `EV_Cron::init()`
- **Cron Cleanup:** `@errorvault.php:127` - `EV_Cron::unschedule_backup_poll()`

### Class Files
- **Backup Manager:** `@includes/class-ev-backup-manager.php`
- **Database Exporter:** `@includes/class-ev-db-exporter.php`
- **Cron Management:** `@includes/class-ev-cron.php`
- **Helper Functions:** `@includes/class-ev-backup-helpers.php`

### Key Methods
- **Poll API:** `EV_Backup_Manager::poll_pending_backup()`
- **Run Backup:** `EV_Backup_Manager::run_backup()`
- **Export Database:** `EV_DB_Exporter::export_to_sql()`
- **Upload Archive:** `EV_Backup_Manager::upload_archive()`

## Support & Debugging

### Enable Debug Logging
All backup operations are automatically logged to:
1. PHP error_log
2. `wp-content/uploads/errorvault-backups/backup.log`

### Log Format
```
[2024-02-25 14:30:00] Starting backup: ID=123
[2024-02-25 14:30:05] Exporting database...
[2024-02-25 14:30:15] Completed export of wp_posts (1000 rows)
[2024-02-25 14:30:45] Building ZIP archive...
[2024-02-25 14:31:00] Archive created: 45.2MB, SHA256=abc123...
[2024-02-25 14:31:05] Uploading backup...
[2024-02-25 14:32:30] Backup completed successfully
```

### Common Log Messages
- ✅ **"Backup completed successfully"** - All good
- ⚠️ **"Backup already in progress"** - Concurrent run prevented
- ⚠️ **"No pending backup found"** - Normal poll with no work
- ❌ **"Poll failed: Unauthorized"** - Check API token
- ❌ **"Upload failed: HTTP 500"** - Backend API issue
- ❌ **"Backup file exceeds 500MB limit"** - File too large

---

**Implementation Date:** February 25, 2024  
**Version:** 1.0 (Phase 1)  
**Status:** ✅ Complete
