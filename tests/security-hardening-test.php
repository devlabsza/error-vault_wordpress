<?php
/** Standalone regression tests for opt-in response-header hardening. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['ev_settings'] = array();
$GLOBALS['ev_hooks'] = array();
function get_option($name, $default = false) { return 'errorvault_settings' === $name ? $GLOBALS['ev_settings'] : $default; }
function add_filter($name, $callback, $priority = 10) { $GLOBALS['ev_hooks']['filter'][$name][] = array($callback, $priority); }
function add_action($name, $callback, $priority = 10) { $GLOBALS['ev_hooks']['action'][$name][] = array($callback, $priority); }
require __DIR__ . '/../includes/class-errorvault-security-hardening.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    check(array() === ErrorVault_Security_Hardening::configured_headers(array()), 'Headers are opt-in');

    $settings = array(
        'security_header_nosniff' => true,
        'security_header_referrer_policy' => true,
        'security_header_frame_options' => true,
        'security_header_permissions_policy' => true,
        'security_permissions_policy' => 'camera=(), microphone=()',
    );
    $configured = ErrorVault_Security_Hardening::configured_headers($settings);
    check('nosniff' === $configured['X-Content-Type-Options'], 'nosniff header is available');
    check('strict-origin-when-cross-origin' === $configured['Referrer-Policy'], 'Referrer policy is conservative');
    check('SAMEORIGIN' === $configured['X-Frame-Options'], 'Frame policy is same-origin');
    check('camera=(), microphone=()' === $configured['Permissions-Policy'], 'Configured permissions policy is used');
    check(!isset($configured['Strict-Transport-Security']), 'HSTS is not emitted by the plugin');
    check(!isset($configured['Content-Security-Policy']), 'CSP is not emitted without site-specific review');

    $settings['security_permissions_policy'] = "camera=()\r\nX-Evil: yes";
    $policy = ErrorVault_Security_Hardening::configured_headers($settings)['Permissions-Policy'];
    check(false === strpos($policy, "\r") && false === strpos($policy, "\n"), 'Header injection characters are removed');
    check(strlen($policy) <= 1000, 'Permissions policy is bounded');

    $settings['security_permissions_policy'] = "\r\n";
    check(
        ErrorVault_Security_Hardening::DEFAULT_PERMISSIONS_POLICY === ErrorVault_Security_Hardening::configured_headers($settings)['Permissions-Policy'],
        'An empty enabled policy uses the safe default'
    );

    $GLOBALS['ev_settings'] = $settings;
    $existing = array(
        'x-content-type-options' => 'host-value',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'self'",
        'X-Powered-By' => 'PHP/8.4',
    );
    $GLOBALS['ev_settings']['security_remove_powered_by'] = true;
    $filtered = ErrorVault_Security_Hardening::filter_headers($existing);
    check('host-value' === $filtered['x-content-type-options'], 'Existing headers are not replaced');
    check('DENY' === $filtered['X-Frame-Options'], 'A stronger existing frame policy is preserved');
    check(!isset($filtered['X-Powered-By']), 'X-Powered-By is removed when selected');
    check(isset($filtered['Referrer-Policy'], $filtered['Permissions-Policy']), 'Missing enabled headers are added');
    check(1 === count(array_filter(array_keys($filtered), function ($name) { return 0 === strcasecmp($name, 'X-Content-Type-Options'); })), 'Header matching is case-insensitive');

    ErrorVault_Security_Hardening::init();
    check(isset($GLOBALS['ev_hooks']['filter']['wp_headers']), 'WordPress response filter is registered');
    check(isset($GLOBALS['ev_hooks']['action']['send_headers']), 'WordPress send_headers fallback is registered');
    check(isset($GLOBALS['ev_hooks']['action']['login_init']), 'Login responses are covered');
    check(isset($GLOBALS['ev_hooks']['action']['admin_init']), 'Admin responses are covered');

    echo "OK ({$checks} checks)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
