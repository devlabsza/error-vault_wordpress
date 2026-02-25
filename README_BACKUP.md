# ErrorVault WordPress Backup System

## Overview

The ErrorVault WordPress plugin includes an automated backup system that integrates with the ErrorVault API. When you request a backup from your ErrorVault dashboard, the plugin automatically creates and uploads a complete backup of your WordPress site.

## Features

✅ **Automatic Polling** - Checks for pending backups every 5 minutes  
✅ **Database Export** - Pure PHP implementation (no shell access required)  
✅ **File Backups** - Optional inclusion of uploads directory  
✅ **Secure Upload** - Encrypted transmission with checksum verification  
✅ **Smart Cleanup** - Automatic removal of temporary files  
✅ **Error Handling** - Comprehensive logging and retry logic  
✅ **Shared Hosting Compatible** - Works on any WordPress hosting  

## How It Works

### 1. Request Backup
From your ErrorVault dashboard, request a backup for your WordPress site. Choose whether to include uploaded files.

### 2. Automatic Detection
Every 5 minutes, the plugin polls the ErrorVault API to check for pending backup requests.

### 3. Backup Creation
When a pending backup is detected:
- Database is exported to SQL format
- ZIP archive is created
- Uploads folder is included (if requested)
- SHA256 checksum is calculated

### 4. Upload
The backup archive is uploaded to ErrorVault with:
- Retry logic for reliability
- Progress tracking
- Metadata about your WordPress installation

### 5. Cleanup
Temporary files are automatically deleted from your server after upload.

## System Requirements

### Required
- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher
- **PHP ZipArchive Extension:** Enabled (standard on most hosts)
- **Writable Uploads Directory:** Standard WordPress requirement
- **ErrorVault API:** Configured with valid endpoint and token

### Recommended
- **PHP Memory Limit:** 256M or higher
- **Max Execution Time:** 300 seconds or higher
- **Available Disk Space:** 2x the size of your database + uploads

## File Locations

### Temporary Files
```
wp-content/uploads/errorvault-backups/tmp/
├── backup-{id}.sql    (deleted after upload)
└── backup-{id}.zip    (deleted after upload)
```

### Logs
```
wp-content/uploads/errorvault-backups/backup.log
```

## What Gets Backed Up

### Database Only Backup
- ✅ All WordPress database tables
- ✅ Posts, pages, comments
- ✅ Users and settings
- ✅ Plugin and theme data
- ✅ Custom post types
- ✅ Metadata

### Database + Uploads Backup
- ✅ Everything above, plus:
- ✅ All files in `wp-content/uploads/`
- ✅ Images, videos, PDFs
- ✅ Documents and media files

### Not Included
- ❌ WordPress core files (can be reinstalled)
- ❌ Themes (should be in version control)
- ❌ Plugins (should be in version control)
- ❌ wp-config.php (contains sensitive credentials)

## Limitations

### File Size
- **Maximum backup size:** 500MB
- Backups exceeding this limit will fail with a clear error message
- Consider database-only backups if your uploads folder is very large

### Performance
- **Database export:** Batched processing (100 rows at a time)
- **Large sites:** May take several minutes to complete
- **Background operation:** Does not block WordPress site

## Monitoring & Debugging

### Check Backup Status
```php
// Via WP-CLI or PHP
$status = EV_Backup_Helpers::get_backup_status();
print_r($status);
```

### View Recent Logs
```php
$logs = EV_Backup_Helpers::get_recent_log_entries(50);
foreach ($logs as $log) {
    echo $log . "\n";
}
```

### Check Requirements
```php
$requirements = EV_Backup_Helpers::check_requirements();
print_r($requirements);
```

### Estimate Backup Size
```php
$size = EV_Backup_Helpers::estimate_backup_size(true);
echo "Estimated size: " . $size['total_size_formatted'];
echo "\nWithin limit: " . ($size['within_limit'] ? 'Yes' : 'No');
```

### Manual Trigger (Testing)
```php
EV_Backup_Helpers::trigger_manual_poll();
```

## Troubleshooting

### Backups Not Running

**Symptoms:** No backups are being created

**Solutions:**
1. Verify ErrorVault API is configured (Settings → ErrorVault)
2. Check cron is scheduled: `wp_next_scheduled('ev_backup_poll_event')`
3. Review backup log for errors
4. Ensure plugin is activated

### Backup Fails Immediately

**Symptoms:** Backup starts but fails quickly

**Solutions:**
1. Check system requirements: `EV_Backup_Helpers::check_requirements()`
2. Verify uploads directory is writable
3. Check available disk space
4. Review PHP error log

### Upload Fails

**Symptoms:** Backup created but upload fails

**Solutions:**
1. Verify backup size is under 500MB
2. Check API token is valid
3. Test network connectivity to ErrorVault
4. Review upload timeout settings

### Large Backup Times Out

**Symptoms:** Backup fails on large sites

**Solutions:**
1. Increase PHP `max_execution_time` (300+ seconds)
2. Increase PHP `memory_limit` (256M+ recommended)
3. Consider database-only backups
4. Contact hosting provider about resource limits

### Database Export Errors

**Symptoms:** Database export fails

**Solutions:**
1. Check database connectivity
2. Verify database permissions
3. Check for corrupted tables
4. Review MySQL error log

## Security

### Data Protection
- ✅ All transmissions use HTTPS
- ✅ API token authentication
- ✅ No backups retained on WordPress server
- ✅ Temporary files deleted immediately
- ✅ Logs contain no sensitive information

### File Permissions
- Temporary files use WordPress default permissions
- Log files stored in uploads directory (protected by .htaccess)
- No executable files created

### Best Practices
- Keep API token secure
- Regularly review backup logs
- Monitor disk space usage
- Test restore procedures periodically

## Performance Impact

### Minimal Impact
- **Polling:** <1 second when no backup pending
- **Background Processing:** Does not block site operations
- **Cron-based:** Runs independently of user requests

### Resource Usage
- **CPU:** Moderate during backup creation
- **Memory:** Batched processing for efficiency
- **Disk:** Temporary files equal to backup size
- **Network:** Upload bandwidth during transmission

## API Integration

### Endpoints Used

**Poll for Pending Backup:**
```
GET /api/v1/backups/pending
Header: X-API-Token: {site_api_token}
```

**Upload Backup:**
```
POST /api/v1/backups/{backup_id}/upload
Header: X-API-Token: {site_api_token}
Content-Type: multipart/form-data
```

### Response Codes
- **200:** Success
- **401:** Unauthorized (check API token)
- **409:** Conflict (backup no longer accepting uploads)
- **500:** Server error (contact ErrorVault support)

## Advanced Usage

### Cleanup Operations

**Clear Logs:**
```php
EV_Backup_Helpers::clear_log();
```

**Cleanup Temp Files:**
```php
$cleaned = EV_Backup_Helpers::cleanup_temp_files();
echo "Cleaned {$cleaned} files";
```

### Cron Management

**Check Next Poll Time:**
```php
$next = EV_Cron::get_next_poll_time();
echo "Next poll: " . date('Y-m-d H:i:s', $next);
```

**Manually Reschedule:**
```php
EV_Cron::unschedule_backup_poll();
EV_Cron::schedule_backup_poll();
```

## Development & Testing

### Test Backup Flow
1. Configure ErrorVault API
2. Request backup from dashboard
3. Trigger manual poll: `EV_Backup_Helpers::trigger_manual_poll()`
4. Monitor log file for progress
5. Verify backup appears in ErrorVault

### Debug Mode
All operations are automatically logged to:
- `wp-content/uploads/errorvault-backups/backup.log`
- PHP error_log

### Log Format
```
[2024-02-25 14:30:00] Starting backup: ID=123
[2024-02-25 14:30:05] Exporting database...
[2024-02-25 14:30:45] Building ZIP archive...
[2024-02-25 14:31:00] Archive created: 45.2MB
[2024-02-25 14:32:30] Backup completed successfully
```

## Support

### Documentation
- **Technical Details:** See `BACKUP_IMPLEMENTATION.md`
- **Quick Start:** See `BACKUP_QUICK_START.md`
- **General Plugin:** See `readme.txt`

### Getting Help
1. Review log file for error details
2. Check troubleshooting section above
3. Verify system requirements
4. Contact ErrorVault support with log excerpts

## Version History

### 1.4.0 (February 25, 2024)
- Initial release of backup functionality
- Database export with pure PHP
- ZIP archive creation
- API integration with retry logic
- Comprehensive logging and error handling

## License

GPL v2 or later - Same as WordPress

---

**Plugin:** ErrorVault WordPress Plugin  
**Feature:** Automated Backup System  
**Version:** 1.4.0  
**Last Updated:** February 25, 2024
