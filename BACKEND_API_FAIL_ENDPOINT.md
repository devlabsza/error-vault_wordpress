# Backend API: Mark Backup as Failed Endpoint

## Overview

When a backup fails 3 times, the WordPress plugin needs to notify the Error-Vault portal to mark the backup as permanently failed and remove it from the pending queue.

## Required Endpoint

### Mark Backup as Failed

**Endpoint:** `POST /api/v1/backups/{backup_id}/fail`

**Headers:**
- `X-API-Token`: Authentication token
- `Content-Type`: application/json

**Request Body:**
```json
{
  "error": "Archive creation failed: Failed to add database.sql to archive",
  "failed_at": "2026-02-26 10:05:14"
}
```

**Response (200 OK):**
```json
{
  "backup_id": 7,
  "status": "failed",
  "error": "Archive creation failed: Failed to add database.sql to archive",
  "failed_at": "2026-02-26 10:05:14"
}
```

**Response (404 Not Found):**
```json
{
  "error": "Backup not found"
}
```

## Purpose

- Marks the backup as permanently failed in the database
- Removes the backup from the pending queue
- Prevents the plugin from retrying the same failed backup indefinitely
- Stores the error message for troubleshooting

## Implementation

### Database Update

```sql
UPDATE backups 
SET 
  status = 'failed',
  error_message = 'Archive creation failed: Failed to add database.sql to archive',
  failed_at = '2026-02-26 10:05:14',
  updated_at = NOW()
WHERE id = 7;
```

### Example Implementation (PHP/Laravel)

```php
public function markBackupFailed(Request $request, $backupId)
{
    $backup = Backup::findOrFail($backupId);
    
    // Only allow marking pending or processing backups as failed
    if (!in_array($backup->status, ['pending', 'processing'])) {
        return response()->json([
            'error' => 'Backup cannot be marked as failed (current status: ' . $backup->status . ')'
        ], 400);
    }
    
    $backup->update([
        'status' => 'failed',
        'error_message' => $request->error,
        'failed_at' => $request->failed_at ?? now(),
    ]);
    
    // Optional: Send notification to user about failed backup
    // event(new BackupFailed($backup));
    
    return response()->json([
        'backup_id' => $backup->id,
        'status' => 'failed',
        'error' => $backup->error_message,
        'failed_at' => $backup->failed_at->toIso8601String(),
    ]);
}
```

### Route Registration

```php
// In routes/api.php or similar
Route::post('/backups/{id}/fail', [BackupController::class, 'markBackupFailed'])
    ->middleware('auth:api');
```

## When This Endpoint is Called

The WordPress plugin calls this endpoint when:

1. A backup has failed 3 times in a row
2. The plugin has tracked the failure count using transients
3. On the 3rd failure, it calls this endpoint to permanently mark the backup as failed

## Workflow

```
Attempt 1: Backup fails → Increment failure count (1/3)
           ↓
Attempt 2: Backup fails → Increment failure count (2/3)
           ↓
Attempt 3: Backup fails → Increment failure count (3/3)
           ↓
           Call POST /api/v1/backups/{id}/fail
           ↓
           Portal marks backup as failed
           ↓
           Plugin stops retrying this backup
```

## Error Scenarios

### Backup Not Found
```json
{
  "error": "Backup not found"
}
```
**Status Code:** 404

### Backup Already Completed
```json
{
  "error": "Backup cannot be marked as failed (current status: completed)"
}
```
**Status Code:** 400

### Invalid Request
```json
{
  "error": "Missing required field: error"
}
```
**Status Code:** 422

## Testing

### Test 1: Mark Pending Backup as Failed
```bash
curl -X POST https://portal.example.com/api/v1/backups/7/fail \
  -H "X-API-Token: your-api-token" \
  -H "Content-Type: application/json" \
  -d '{
    "error": "Archive creation failed: Failed to add database.sql to archive",
    "failed_at": "2026-02-26 10:05:14"
  }'
```

**Expected:** 200 OK, backup marked as failed

### Test 2: Try to Mark Completed Backup as Failed
```bash
curl -X POST https://portal.example.com/api/v1/backups/5/fail \
  -H "X-API-Token: your-api-token" \
  -H "Content-Type: application/json" \
  -d '{
    "error": "Test error",
    "failed_at": "2026-02-26 10:05:14"
  }'
```

**Expected:** 400 Bad Request (backup already completed)

### Test 3: Invalid Backup ID
```bash
curl -X POST https://portal.example.com/api/v1/backups/99999/fail \
  -H "X-API-Token: your-api-token" \
  -H "Content-Type: application/json" \
  -d '{
    "error": "Test error",
    "failed_at": "2026-02-26 10:05:14"
  }'
```

**Expected:** 404 Not Found

## Database Schema Suggestion

```sql
ALTER TABLE backups 
ADD COLUMN error_message TEXT NULL,
ADD COLUMN failed_at TIMESTAMP NULL;
```

## Security Considerations

1. **Authentication:** Validate `X-API-Token` header
2. **Authorization:** Ensure token belongs to the site that owns the backup
3. **Validation:** Validate backup exists and is in a valid state
4. **Rate Limiting:** Prevent abuse of the endpoint

## Related Endpoints

This endpoint works alongside:
- `GET /api/v1/backups/pending` - Get pending backups
- `POST /api/v1/backups/{id}/upload/initiate` - Start upload
- `POST /api/v1/backups/{id}/upload/complete` - Complete upload

## Notes

- The plugin will automatically stop retrying after calling this endpoint
- The failure count is cleared after 24 hours (transient expiration)
- If a backup succeeds on retry, the failure count is cleared immediately
- This prevents infinite retry loops that waste server resources
