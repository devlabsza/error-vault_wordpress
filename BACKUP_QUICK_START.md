# ErrorVault Backup - Quick Start Guide

## What It Does

The ErrorVault plugin now automatically backs up your WordPress site when requested from your ErrorVault dashboard. Backups include your database and optionally your uploaded files.

## How It Works

1. **Every 5 minutes**, the plugin checks the ErrorVault API for pending backup requests
2. **If a backup is requested**, it automatically:
   - Exports your database to SQL
   - Creates a ZIP archive
   - Includes uploads folder (if requested)
   - Uploads to ErrorVault
   - Cleans up temporary files
3. **No manual intervention required** - it's completely automatic

## Requirements

✅ **ErrorVault API configured** (Settings → ErrorVault)  
✅ **PHP ZipArchive extension** (usually enabled by default)  
✅ **Writable uploads directory** (standard WordPress requirement)

## Check Status

### Via PHP/WP-CLI
```php
// Check if backup system is ready
$requirements = EV_Backup_Helpers::check_requirements();
print_r($requirements);

// Check current status
$status = EV_Backup_Helpers::get_backup_status();
print_r($status);

// View recent logs
$logs = EV_Backup_Helpers::get_recent_log_entries(20);
foreach ($logs as $log) {
    echo $log . "\n";
}
```

### Via File System
- **Log file:** `wp-content/uploads/errorvault-backups/backup.log`
- **Temp directory:** `wp-content/uploads/errorvault-backups/tmp/`

## Manual Testing

Trigger a backup poll manually (for testing):
```php
EV_Backup_Helpers::trigger_manual_poll();
```

## File Size Limits

- **Maximum backup size:** 500MB
- **Typical database:** 1-50MB
- **With uploads:** Varies (can be large)

**Tip:** If your uploads folder is very large, request database-only backups.

## Troubleshooting

### Backups not running?
1. Check ErrorVault API is configured (Settings → ErrorVault)
2. Verify cron is scheduled: `wp_next_scheduled('ev_backup_poll_event')`
3. Check the log file for errors

### Backup fails?
1. Check available disk space
2. Verify uploads directory is writable
3. Check PHP memory limit (256M+ recommended)
4. Review log file for specific error

### Upload fails?
1. Verify backup size is under 500MB
2. Check API token is valid
3. Test network connectivity to ErrorVault

## What Gets Backed Up

### Database Only
- All WordPress database tables
- Posts, pages, comments, users, settings, etc.
- Plugin and theme data

### Database + Uploads
- Everything above, plus:
- All files in `wp-content/uploads/`
- Images, PDFs, documents, etc.

### NOT Included
- WordPress core files (can be reinstalled)
- Themes (should be in version control)
- Plugins (should be in version control)
- wp-config.php (contains sensitive credentials)

## Security

- ✅ Backups transmitted over HTTPS only
- ✅ Authenticated with API token
- ✅ Temporary files deleted immediately after upload
- ✅ No backups retained on your server
- ✅ Logs contain no sensitive information

## Performance Impact

- **Minimal** - Runs in background via cron
- **Poll check:** <1 second (if no backup pending)
- **Backup creation:** 30 seconds to 5 minutes (depends on size)
- **Does not block** WordPress site operations

## Advanced Configuration

### Disable Backups
Deactivate the ErrorVault plugin to stop backup polling.

### Change Poll Frequency
The 5-minute interval is defined in the cron schedule. To change it, modify the `five_minutes` cron interval in `errorvault.php`.

### Cleanup Old Logs
```php
EV_Backup_Helpers::clear_log();
```

### Cleanup Temp Files
```php
EV_Backup_Helpers::cleanup_temp_files();
```

## Support

For issues or questions:
1. Check `BACKUP_IMPLEMENTATION.md` for detailed documentation
2. Review log file: `wp-content/uploads/errorvault-backups/backup.log`
3. Contact ErrorVault support with log details

## Quick Reference

| Task | Command |
|------|---------|
| Check requirements | `EV_Backup_Helpers::check_requirements()` |
| Check status | `EV_Backup_Helpers::get_backup_status()` |
| View logs | `EV_Backup_Helpers::get_recent_log_entries(50)` |
| Trigger manual poll | `EV_Backup_Helpers::trigger_manual_poll()` |
| Estimate size | `EV_Backup_Helpers::estimate_backup_size(true)` |
| Clear logs | `EV_Backup_Helpers::clear_log()` |
| Cleanup temp files | `EV_Backup_Helpers::cleanup_temp_files()` |

---

**Version:** 1.0  
**Last Updated:** February 25, 2024
