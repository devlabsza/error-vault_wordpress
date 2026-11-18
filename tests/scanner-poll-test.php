<?php
/**
 * Standalone harness for ErrorVault_Security_Scanner::poll(): which check-ins
 * start a scan. A scan starting is seen as run_and_report() taking its 15-minute
 * lock; the stub throws there so no files are scanned.
 */
define('ABSPATH', sys_get_temp_dir() . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('ERRORVAULT_VERSION', 'test');

class ScanStarted extends Exception { public $trigger; }

$GLOBALS['ev_pending'] = array();
$GLOBALS['ev_scheduled'] = array();

function get_option($name, $default = false) { return 'errorvault_settings' === $name ? array('api_token' => 'token') : $default; }
function get_transient($key) { return false; }
function delete_transient($key) { return true; }
function set_transient($key, $value, $ttl) {
    if (ErrorVault_Security_Scanner::LOCK_KEY === $key && 15 * MINUTE_IN_SECONDS === $ttl) {
        $started = new ScanStarted();
        foreach (debug_backtrace() as $frame) {
            if ('run_and_report' === $frame['function']) {
                $started->trigger = $frame['args'][1];
            }
        }
        throw $started;
    }
    return true;
}
function wp_remote_get($url, $options) { return array('status' => 200, 'body' => json_encode(array('data' => $GLOBALS['ev_pending']))); }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function is_wp_error($r) { return false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['ev_scheduled'][] = $hook; return true; }

class ErrorVault_Security_Actions {
    public static function flush_unreported() {}
    public static function process($actions) { return array_column($actions, 'type'); }
}
class ErrorVault_Content_Drafts { const TYPE = 'create_draft_page'; }
class EV_Backup_Restorer { public static function cleanup_expired() {} }

require __DIR__ . '/../includes/class-errorvault-security-scanner.php';

$checks = 0;
function check($condition, $message) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }

/** The trigger of the scan a poll with this pending payload starts, or false. */
function poll_scans(array $pending) {
    $GLOBALS['ev_pending'] = $pending;
    $GLOBALS['ev_scheduled'] = array();
    try {
        ErrorVault_Security_Scanner::poll();
    } catch (ScanStarted $e) {
        return $e->trigger;
    }
    return false;
}

$draft = array('id' => 1, 'type' => 'create_draft_page', 'params' => array());
$quarantine = array('id' => 2, 'type' => 'quarantine_path', 'params' => array());

try {
    check('scheduled' === poll_scans(array('actions' => array($draft), 'scan' => true, 'trigger' => 'scheduled')), 'Draft-only check-in must still run a due scan, with the portal\'s trigger');
    check('requested' === poll_scans(array('actions' => array($draft), 'scan' => true, 'trigger' => 'requested')), 'Draft-only check-in must still run a requested scan');
    check(false === poll_scans(array('actions' => array($draft), 'scan' => false)), 'Draft-only check-in with no scan due must not scan');
    check('requested' === poll_scans(array('actions' => array($draft, $quarantine), 'scan' => false)), 'Mixed batch must rescan after the cleanup');
    check('requested' === poll_scans(array('actions' => array($quarantine))), 'Cleanup batch must rescan');
    check(false === poll_scans(array('actions' => array(array('id' => 3, 'type' => 'update_core', 'params' => array())))), 'Core update rescans from a later request, not this one');
    check(array('errorvault_security_run_now') === $GLOBALS['ev_scheduled'], 'Core update must schedule the rescan');
    check('scheduled' === poll_scans(array('scan' => true, 'trigger' => 'scheduled')), 'Due scan without actions must run');
    check(false === poll_scans(array('scan' => false)), 'Nothing due, nothing run');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "OK ($checks checks)\n";
