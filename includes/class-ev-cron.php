<?php
/**
 * Cron Management for ErrorVault Backups
 * Handles scheduling and execution of backup polling
 */

if (!defined('ABSPATH')) {
    exit;
}

class EV_Cron {

    /**
     * Cron hook name
     */
    const BACKUP_POLL_HOOK = 'ev_backup_poll_event';

    /**
     * Initialize cron
     */
    public static function init() {
        add_action(self::BACKUP_POLL_HOOK, array(__CLASS__, 'poll_pending_backup'));
        
        self::schedule_backup_poll();
    }

    /**
     * Schedule backup polling if not already scheduled
     */
    public static function schedule_backup_poll() {
        if (!wp_next_scheduled(self::BACKUP_POLL_HOOK)) {
            wp_schedule_event(time() + 60, 'five_minutes', self::BACKUP_POLL_HOOK);
            error_log('[ErrorVault Backup] Scheduled backup polling cron');
        }
    }

    /**
     * Poll for pending backup (cron callback)
     */
    public static function poll_pending_backup() {
        require_once ERRORVAULT_PLUGIN_DIR . 'includes/class-ev-backup-manager.php';
        
        $manager = new EV_Backup_Manager();
        $manager->poll_pending_backup();
    }

    /**
     * Unschedule backup polling
     */
    public static function unschedule_backup_poll() {
        $timestamp = wp_next_scheduled(self::BACKUP_POLL_HOOK);
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::BACKUP_POLL_HOOK);
            error_log('[ErrorVault Backup] Unscheduled backup polling cron');
        }
        
        wp_clear_scheduled_hook(self::BACKUP_POLL_HOOK);
    }

    /**
     * Get next scheduled backup poll time
     */
    public static function get_next_poll_time() {
        $timestamp = wp_next_scheduled(self::BACKUP_POLL_HOOK);
        
        if ($timestamp) {
            return $timestamp;
        }
        
        return null;
    }

    /**
     * Manually trigger backup poll (for testing)
     */
    public static function trigger_poll_now() {
        self::poll_pending_backup();
    }
}
