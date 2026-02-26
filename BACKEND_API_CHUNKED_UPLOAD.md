# Backend API Requirements for Chunked Upload

## Overview

The WordPress plugin now uses a **chunked multipart upload system** to handle large backup files. The backend API needs to implement three new endpoints to support this.

## Why Chunked Upload?

- Avoids HTTP 413 (Payload Too Large) errors
- Handles backups of any size
- Better error recovery with per-chunk retries
- Reduced memory usage

## Required API Endpoints

### 1. Initiate Multipart Upload

**Endpoint:** `POST /api/v1/backups/{backup_id}/upload/initiate`

**Headers:**
- `X-API-Token`: Authentication token
- `Content-Type`: application/json

**Request Body:**
```json
{
  "checksum": "sha256_hash_of_complete_file",
  "metadata": {
    "wordpress_version": "6.4.2",
    "plugin_version": "1.4.5",
    "php_version": "8.1.0",
    "mysql_version": "8.0.32",
    "site_url": "https://example.com",
    "backup_size": 10485760,
    "created_at": "2026-02-26 08:30:00"
  }
}
```

**Response (200 OK):**
```json
{
  "upload_id": "unique_upload_identifier_string",
  "backup_id": 123,
  "expires_at": "2026-02-26 09:30:00"
}
```

**Purpose:**
- Creates a new multipart upload session
- Returns `upload_id` to track this upload
- Backend should create temporary storage for incoming chunks

---

### 2. Upload Chunk

**Endpoint:** `POST /api/v1/backups/{backup_id}/upload/part`

**Headers:**
- `X-API-Token`: Authentication token
- `X-Upload-ID`: The upload_id from initiate response
- `X-Part-Number`: Integer (1-based) chunk sequence number
- `Content-Type`: application/octet-stream

**Request Body:**
- Raw binary data (5MB chunk)

**Response (200 OK):**
```json
{
  "part_number": 1,
  "etag": "md5_or_hash_of_chunk",
  "received_bytes": 5242880
}
```

**Purpose:**
- Receives and stores individual 5MB chunks
- Returns ETag for verification
- Plugin will retry failed chunks up to 3 times

**Notes:**
- Chunks are sent sequentially (1, 2, 3, ...)
- Each chunk is 5MB except the last one (may be smaller)
- Backend should store chunks temporarily until complete

---

### 3. Complete Multipart Upload

**Endpoint:** `POST /api/v1/backups/{backup_id}/upload/complete`

**Headers:**
- `X-API-Token`: Authentication token
- `Content-Type`: application/json

**Request Body:**
```json
{
  "upload_id": "unique_upload_identifier_string",
  "parts": [
    {
      "part_number": 1,
      "etag": "hash_of_chunk_1"
    },
    {
      "part_number": 2,
      "etag": "hash_of_chunk_2"
    }
  ]
}
```

**Response (200 OK):**
```json
{
  "backup_id": 123,
  "status": "completed",
  "file_size": 10485760,
  "checksum": "sha256_hash_of_complete_file",
  "url": "https://storage.example.com/backups/123.zip"
}
```

**Response (409 Conflict):**
```json
{
  "error": "Backup no longer accepting uploads",
  "status": "cancelled"
}
```

**Purpose:**
- Assembles all chunks into final file
- Verifies checksum matches the one from initiate
- Marks backup as complete
- Cleans up temporary chunk storage

**Notes:**
- Backend should verify all parts are present
- Assemble chunks in order (1, 2, 3, ...)
- Verify final file checksum matches initiate request
- Return 409 if backup was cancelled or expired

---

### 4. Abort Multipart Upload (Optional but Recommended)

**Endpoint:** `POST /api/v1/backups/{backup_id}/upload/abort`

**Headers:**
- `X-API-Token`: Authentication token
- `Content-Type`: application/json

**Request Body:**
```json
{
  "upload_id": "unique_upload_identifier_string"
}
```

**Response (200 OK):**
```json
{
  "upload_id": "unique_upload_identifier_string",
  "status": "aborted"
}
```

**Purpose:**
- Called when plugin encounters unrecoverable error
- Allows backend to clean up temporary chunks
- Frees storage space

---

## Implementation Guide

### Database Schema Suggestion

**multipart_uploads table:**
```sql
CREATE TABLE multipart_uploads (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  upload_id VARCHAR(255) UNIQUE NOT NULL,
  backup_id BIGINT NOT NULL,
  checksum VARCHAR(64) NOT NULL,
  metadata JSON,
  status ENUM('initiated', 'uploading', 'completed', 'aborted') DEFAULT 'initiated',
  expires_at TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (backup_id) REFERENCES backups(id)
);

CREATE TABLE upload_parts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  upload_id VARCHAR(255) NOT NULL,
  part_number INT NOT NULL,
  etag VARCHAR(64),
  file_path VARCHAR(500),
  size BIGINT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (upload_id, part_number),
  FOREIGN KEY (upload_id) REFERENCES multipart_uploads(upload_id)
);
```

### Backend Implementation Steps

1. **Initiate Endpoint:**
   - Generate unique `upload_id` (UUID recommended)
   - Create record in `multipart_uploads` table
   - Set expiration (1 hour recommended)
   - Return `upload_id`

2. **Upload Part Endpoint:**
   - Validate `upload_id` exists and not expired
   - Save chunk to temporary storage (e.g., `/tmp/uploads/{upload_id}/part_{part_number}`)
   - Calculate ETag (MD5 or SHA256 of chunk)
   - Store part metadata in `upload_parts` table
   - Return ETag

3. **Complete Endpoint:**
   - Validate all parts are present
   - Assemble chunks in order into final file
   - Verify final file checksum matches initiate request
   - Move to permanent storage
   - Update backup record with file location
   - Clean up temporary chunks
   - Mark upload as completed

4. **Abort Endpoint:**
   - Delete temporary chunk files
   - Mark upload as aborted
   - Clean up database records

### Storage Recommendations

**Temporary Storage:**
- `/tmp/uploads/{upload_id}/part_{part_number}`
- Clean up after 24 hours if not completed
- Use cron job to remove expired uploads

**Permanent Storage:**
- `/backups/{backup_id}.zip`
- Or cloud storage (S3, etc.)

### Error Handling

**Return 404 if:**
- Backup ID doesn't exist
- Upload ID doesn't exist

**Return 409 if:**
- Backup already completed
- Backup cancelled
- Upload expired

**Return 400 if:**
- Missing required headers
- Invalid part number
- Checksum mismatch

**Return 413 if:**
- Chunk size exceeds limit (though 5MB should be fine)

---

## Testing the Implementation

### Test 1: Small Backup (< 5MB)
- Should create 1 chunk
- Verify initiate, upload part, complete flow

### Test 2: Medium Backup (10-20MB)
- Should create 2-4 chunks
- Verify all chunks uploaded in sequence

### Test 3: Large Backup (50MB+)
- Should create 10+ chunks
- Verify progress logging
- Test chunk retry on simulated failure

### Test 4: Checksum Verification
- Modify a chunk before complete
- Verify backend rejects with checksum mismatch

### Test 5: Expired Upload
- Initiate upload
- Wait for expiration
- Attempt to upload chunk
- Should return 409 or 404

---

## Migration from Single Upload

If you want to support both old and new upload methods:

1. Keep existing `POST /api/v1/backups/{id}/upload` endpoint
2. Add new chunked endpoints
3. Plugin will automatically use chunked upload
4. Old plugin versions will continue using single upload

---

## Security Considerations

1. **Authentication:** Validate `X-API-Token` on all endpoints
2. **Rate Limiting:** Limit uploads per user/site
3. **File Size Limits:** Set maximum backup size (e.g., 500MB)
4. **Expiration:** Auto-expire uploads after 1 hour
5. **Cleanup:** Regular cron to remove abandoned uploads
6. **Validation:** Verify checksums to prevent corruption

---

## Example Implementation (PHP/Laravel)

```php
// Initiate Upload
public function initiateUpload(Request $request, $backupId)
{
    $backup = Backup::findOrFail($backupId);
    
    if ($backup->status !== 'pending') {
        return response()->json(['error' => 'Backup not pending'], 409);
    }
    
    $uploadId = Str::uuid();
    
    MultipartUpload::create([
        'upload_id' => $uploadId,
        'backup_id' => $backupId,
        'checksum' => $request->checksum,
        'metadata' => $request->metadata,
        'expires_at' => now()->addHour(),
    ]);
    
    return response()->json([
        'upload_id' => $uploadId,
        'backup_id' => $backupId,
        'expires_at' => now()->addHour()->toIso8601String(),
    ]);
}

// Upload Part
public function uploadPart(Request $request, $backupId)
{
    $uploadId = $request->header('X-Upload-ID');
    $partNumber = $request->header('X-Part-Number');
    
    $upload = MultipartUpload::where('upload_id', $uploadId)
        ->where('backup_id', $backupId)
        ->where('expires_at', '>', now())
        ->firstOrFail();
    
    $tempDir = storage_path("uploads/{$uploadId}");
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $partPath = "{$tempDir}/part_{$partNumber}";
    file_put_contents($partPath, $request->getContent());
    
    $etag = md5_file($partPath);
    
    UploadPart::create([
        'upload_id' => $uploadId,
        'part_number' => $partNumber,
        'etag' => $etag,
        'file_path' => $partPath,
        'size' => filesize($partPath),
    ]);
    
    return response()->json([
        'part_number' => (int)$partNumber,
        'etag' => $etag,
        'received_bytes' => filesize($partPath),
    ]);
}

// Complete Upload
public function completeUpload(Request $request, $backupId)
{
    $uploadId = $request->upload_id;
    
    $upload = MultipartUpload::where('upload_id', $uploadId)
        ->where('backup_id', $backupId)
        ->firstOrFail();
    
    $parts = UploadPart::where('upload_id', $uploadId)
        ->orderBy('part_number')
        ->get();
    
    // Assemble file
    $finalPath = storage_path("backups/{$backupId}.zip");
    $finalHandle = fopen($finalPath, 'wb');
    
    foreach ($parts as $part) {
        $partData = file_get_contents($part->file_path);
        fwrite($finalHandle, $partData);
        unlink($part->file_path); // Clean up chunk
    }
    
    fclose($finalHandle);
    
    // Verify checksum
    $actualChecksum = hash_file('sha256', $finalPath);
    if ($actualChecksum !== $upload->checksum) {
        unlink($finalPath);
        return response()->json(['error' => 'Checksum mismatch'], 400);
    }
    
    // Update backup
    $backup = Backup::find($backupId);
    $backup->update([
        'status' => 'completed',
        'file_path' => $finalPath,
        'file_size' => filesize($finalPath),
    ]);
    
    // Clean up
    $upload->update(['status' => 'completed']);
    rmdir(storage_path("uploads/{$uploadId}"));
    
    return response()->json([
        'backup_id' => $backupId,
        'status' => 'completed',
        'file_size' => filesize($finalPath),
        'checksum' => $actualChecksum,
    ]);
}
```

---

## Questions?

If you need help implementing these endpoints, let me know and I can provide more specific code examples for your backend framework.
