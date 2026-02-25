<?php
/**
 * Backup Manager for ErrorVault
 * Handles polling for pending backups and executing backup operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class EV_Backup_Manager {

    /**
     * Settings
     */
    private $settings;

    /**
     * API base URL
     */
    private $api_base;

    /**
     * API token
     */
    private $api_token;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('errorvault_settings', array());
        $this->api_base = $this->get_api_base();
        $this->api_token = isset($this->settings['api_token']) ? $this->settings['api_token'] : '';
    }

    /**
     * Get API base URL from endpoint
     */
    private function get_api_base() {
        if (empty($this->settings['api_endpoint'])) {
            return '';
        }
        
        $endpoint = rtrim($this->settings['api_endpoint'], '/');
        return preg_replace('#/api/v1/errors$#', '', $endpoint);
    }

    /**
     * Poll for pending backup
     */
    public function poll_pending_backup() {
        if (empty($this->api_base) || empty($this->api_token)) {
            $this->log('Backup polling skipped: API not configured');
            return false;
        }

        if (get_transient('ev_backup_lock')) {
            $this->log('Backup already in progress, skipping poll');
            return false;
        }

        set_transient('ev_backup_lock', 1, 10 * MINUTE_IN_SECONDS);

        try {
            $endpoint = $this->api_base . '/api/v1/backups/pending';
            
            $response = wp_remote_get($endpoint, array(
                'timeout' => 20,
                'headers' => array(
                    'X-API-Token' => $this->api_token,
                    'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
                ),
            ));

            if (is_wp_error($response)) {
                $this->log('Poll failed: ' . $response->get_error_message());
                delete_transient('ev_backup_lock');
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code === 401) {
                $this->log('Poll failed: Unauthorized (401) - check API token');
                delete_transient('ev_backup_lock');
                return false;
            }

            if ($status_code !== 200) {
                $this->log('Poll failed: HTTP ' . $status_code);
                delete_transient('ev_backup_lock');
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (empty($data['data']['has_pending_backup'])) {
                $this->log('No pending backup found');
                delete_transient('ev_backup_lock');
                return false;
            }

            $backup = $data['data']['backup'];
            $backup_id = (int) $backup['id'];
            $include_uploads = !empty($backup['include_uploads']);

            $this->log('Pending backup found: ID=' . $backup_id . ', include_uploads=' . ($include_uploads ? 'yes' : 'no'));

            $this->run_backup($backup_id, $include_uploads);

        } catch (Exception $e) {
            $this->log('Poll exception: ' . $e->getMessage());
            delete_transient('ev_backup_lock');
            return false;
        }

        return true;
    }

    /**
     * Run backup process
     */
    public function run_backup($backup_id, $include_uploads) {
        $this->log('Starting backup: ID=' . $backup_id);

        try {
            $upload_dir = wp_upload_dir();
            $tmp_dir = $upload_dir['basedir'] . '/errorvault-backups/tmp';
            
            if (!wp_mkdir_p($tmp_dir)) {
                throw new Exception('Failed to create temporary directory: ' . $tmp_dir);
            }

            $sql_path = $tmp_dir . '/backup-' . $backup_id . '.sql';
            $zip_path = $tmp_dir . '/backup-' . $backup_id . '.zip';

            require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-ev-db-exporter.php';
            $exporter = new EV_DB_Exporter();
            
            $this->log('Exporting database...');
            $export_start = time();
            if (!$exporter->export_to_sql($sql_path)) {
                throw new Exception('Database export failed');
            }
            $export_time = time() - $export_start;
            $this->log('Database export completed in ' . $export_time . ' seconds');

            $elapsed = time() - $start_time;
            if ($elapsed > 300) {
                $this->log('WARNING: Backup taking longer than expected (' . $elapsed . 's elapsed)');
            }

            $this->log('Building ZIP archive...');
            $archive_result = $this->build_archive($sql_path, $zip_path, $include_uploads);
            
            if (!$archive_result['success']) {
                throw new Exception('Archive creation failed: ' . $archive_result['error']);
            }

            $checksum = hash_file('sha256', $zip_path);
            $file_size = filesize($zip_path);
            $file_size_mb = round($file_size / 1024 / 1024, 2);
            
            $this->log('Archive created: ' . $file_size_mb . 'MB, SHA256=' . substr($checksum, 0, 16) . '...');

            if ($file_size > 512000 * 1024) {
                throw new Exception('Backup file exceeds 500MB limit (' . $file_size_mb . 'MB)');
            }

            $metadata = array(
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'include_uploads' => $include_uploads,
                'file_size' => $file_size,
                'site_url' => get_site_url(),
                'site_name' => get_bloginfo('name'),
                'created_at' => current_time('mysql'),
            );

            $this->log('Uploading backup...');
            $upload_result = $this->upload_archive($backup_id, $zip_path, $checksum, $metadata);

            if (!$upload_result['success']) {
                throw new Exception('Upload failed: ' . $upload_result['error']);
            }

            $total_time = time() - $start_time;
            $this->log('Backup completed successfully in ' . $total_time . ' seconds');

            @unlink($sql_path);
            @unlink($zip_path);

            delete_transient('ev_backup_lock');
            return true;

        } catch (Exception $e) {
            $elapsed = isset($start_time) ? (time() - $start_time) : 0;
            $this->log('Backup failed after ' . $elapsed . ' seconds: ' . $e->getMessage());
            
            if (isset($sql_path) && file_exists($sql_path)) {
                @unlink($sql_path);
            }
            if (isset($zip_path) && file_exists($zip_path)) {
                @unlink($zip_path);
            }

            delete_transient('ev_backup_lock');
            return false;
        }
    }

    /**
     * Build ZIP archive
     */
    private function build_archive($sql_path, $zip_path, $include_uploads) {
        if (!class_exists('ZipArchive')) {
            return array(
                'success' => false,
                'error' => 'ZipArchive class not available',
            );
        }

        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            return array(
                'success' => false,
                'error' => 'Failed to create ZIP file (error code: ' . $result . ')',
            );
        }

        if (!$zip->addFile($sql_path, 'database.sql')) {
            $zip->close();
            return array(
                'success' => false,
                'error' => 'Failed to add database.sql to archive',
            );
        }

        if ($include_uploads) {
            $uploads_dir = WP_CONTENT_DIR . '/uploads';
            
            if (is_dir($uploads_dir)) {
                $this->log('Adding uploads directory to archive...');
                $add_result = $this->add_directory_to_zip($zip, $uploads_dir, 'uploads');
                
                if (!$add_result['success']) {
                    $zip->close();
                    return $add_result;
                }
            } else {
                $this->log('Uploads directory not found, skipping');
            }
        }

        $zip->close();

        return array('success' => true);
    }

    /**
     * Recursively add directory to ZIP
     */
    private function add_directory_to_zip($zip, $dir_path, $zip_path) {
        $dir_path = rtrim($dir_path, '/');
        $zip_path = rtrim($zip_path, '/');

        if (!is_dir($dir_path)) {
            return array(
                'success' => false,
                'error' => 'Directory does not exist: ' . $dir_path,
            );
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $file_count = 0;
        foreach ($files as $file) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($dir_path) + 1);
            $zip_file_path = $zip_path . '/' . $relative_path;

            if ($file->isDir()) {
                $zip->addEmptyDir($zip_file_path);
            } else {
                if (!$zip->addFile($file_path, $zip_file_path)) {
                    return array(
                        'success' => false,
                        'error' => 'Failed to add file to archive: ' . $relative_path,
                    );
                }
                $file_count++;
                
                if ($file_count % 100 === 0) {
                    $this->log('Added ' . $file_count . ' files to archive...');
                }
            }
        }

        $this->log('Added ' . $file_count . ' files from uploads directory');

        return array('success' => true);
    }

    /**
     * Upload backup archive to API
     */
    private function upload_archive($backup_id, $file_path, $checksum, $metadata) {
        $endpoint = $this->api_base . '/api/v1/backups/' . $backup_id . '/upload';

        $boundary = wp_generate_password(24, false);
        $file_contents = file_get_contents($file_path);
        $filename = basename($file_path);

        $body = '';
        
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="backup_archive"; filename="' . $filename . '"' . "\r\n";
        $body .= 'Content-Type: application/zip' . "\r\n\r\n";
        $body .= $file_contents . "\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="checksum"' . "\r\n\r\n";
        $body .= $checksum . "\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="metadata"' . "\r\n\r\n";
        $body .= wp_json_encode($metadata) . "\r\n";

        $body .= '--' . $boundary . '--' . "\r\n";

        $max_retries = 2;
        $retry_count = 0;
        $backoff = 2;

        while ($retry_count <= $max_retries) {
            if ($retry_count > 0) {
                $wait_time = $backoff * $retry_count;
                $this->log('Retry ' . $retry_count . ' after ' . $wait_time . ' seconds...');
                sleep($wait_time);
            }

            $response = wp_remote_post($endpoint, array(
                'timeout' => 300,
                'headers' => array(
                    'X-API-Token' => $this->api_token,
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    'User-Agent' => 'ErrorVault-WordPress/' . ERRORVAULT_VERSION,
                ),
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                $this->log('Upload error: ' . $error_msg);
                
                $retry_count++;
                if ($retry_count > $max_retries) {
                    return array(
                        'success' => false,
                        'error' => $error_msg,
                    );
                }
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 409) {
                return array(
                    'success' => false,
                    'error' => 'Backup no longer accepting uploads (409 Conflict)',
                );
            }

            if ($status_code >= 200 && $status_code < 300) {
                return array('success' => true);
            }

            $this->log('Upload failed with HTTP ' . $status_code);
            
            $retry_count++;
            if ($retry_count > $max_retries) {
                return array(
                    'success' => false,
                    'error' => 'HTTP ' . $status_code,
                );
            }
        }

        return array(
            'success' => false,
            'error' => 'Max retries exceeded',
        );
    }

    /**
     * Log message
     */
    private function log($message) {
        error_log('[ErrorVault Backup] ' . $message);
        
        $log_file = wp_upload_dir()['basedir'] . '/errorvault-backups/backup.log';
        $log_dir = dirname($log_file);
        
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = '[' . $timestamp . '] ' . $message . "\n";
        
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
}
