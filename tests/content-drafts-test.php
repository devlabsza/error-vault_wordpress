<?php
/**
 * Standalone regression tests for draft pages sent from the Error-Vault
 * dashboard (ErrorVault_Content_Drafts). WordPress APIs are stubbed and record
 * what the plugin asks of them; nothing is saved anywhere.
 *
 * Run: php tests/content-drafts-test.php
 */

error_reporting(E_ALL);
ini_set('error_log', '/dev/null');
define('ABSPATH', __DIR__ . '/');

$GLOBALS['ev_settings'] = array();
$GLOBALS['ev_inserted'] = array();
$GLOBALS['ev_kses'] = array();
$GLOBALS['ev_admins'] = array(3);

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function get_option($name, $default = false) {
    if ('errorvault_settings' === $name) {
        return $GLOBALS['ev_settings'];
    }
    return isset($GLOBALS['ev_options'][$name]) ? $GLOBALS['ev_options'][$name] : $default;
}
function sanitize_text_field($text) { return trim(preg_replace('/\s+/', ' ', strip_tags($text))); }
function sanitize_title($title) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($title))), '-'); }
function absint($value) { return abs((int) $value); }
function wp_strip_all_tags($text) { return trim(strip_tags($text)); }
function wp_kses($content, $allowed, $protocols) {
    $GLOBALS['ev_kses'][] = array('allowed' => $allowed, 'protocols' => $protocols);
    return strip_tags($content, '<' . implode('><', array_keys($allowed)) . '>');
}
function strip_shortcodes($content) { return str_replace('[gallery ids="1"]', '', $content); }
function get_users($args) {
    $GLOBALS['ev_users_args'] = $args;
    return $GLOBALS['ev_admins'];
}
function wp_insert_post($post, $wp_error) {
    if (!empty($GLOBALS['ev_insert_error'])) {
        return new WP_Error('db', $GLOBALS['ev_insert_error']);
    }
    $GLOBALS['ev_inserted'][] = $post;
    return 42;
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function admin_url($path) { return 'https://client.example/wp-admin/' . $path; }

// Enough of the action queue's surroundings to run it: results are recorded, not sent.
$GLOBALS['ev_options'] = array();
$GLOBALS['ev_reports'] = array();
function update_option($name, $value, $autoload = null) { $GLOBALS['ev_options'][$name] = $value; return true; }
function wp_json_encode($data) { return json_encode($data); }
function wp_remote_post($url, $args) { $GLOBALS['ev_reports'][] = json_decode($args['body'], true); return array('response' => array('code' => 200)); }
function wp_remote_retrieve_response_code($response) { return 200; }
class ErrorVault_Security_Scanner {
    public static function api_base() { return 'https://error-vault.test/api/v1'; }
    public static function headers() { return array(); }
}

require __DIR__ . '/../includes/class-errorvault-content-drafts.php';
require __DIR__ . '/../includes/class-errorvault-security-actions.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function refuses(array $params, $expected) {
    try {
        ErrorVault_Content_Drafts::create($params);
    } catch (Exception $e) {
        return false !== strpos($e->getMessage(), $expected);
    }
    return false;
}

$page = array(
    'page_draft_id' => 7,
    'title' => "Shower doors\n<b>in Cape Town</b>",
    'slug' => 'Shower Doors Cape Town',
    'meta_description' => 'Fitted in a day.',
    'content' => '<h2>Fitting</h2><p>We fit <a href="https://client.example/contact">doors</a> for [Add your price].</p><script>alert(1)</script><img src=x>'
        . '<!-- wp:html --><p>[gallery ids="1"]</p><!-- /wp:html --><p>end</p><!-- unclosed',
);

try {
    check(!ErrorVault_Content_Drafts::enabled(), 'Draft pages are off by default');
    $GLOBALS['ev_settings'] = array('allow_remote_actions' => true);
    check(!ErrorVault_Content_Drafts::enabled(), 'Remote cleanup actions do not turn draft pages on');
    $GLOBALS['ev_settings'] = array('allow_content_drafts' => true);
    check(ErrorVault_Content_Drafts::enabled(), 'The draft-pages setting turns them on');

    $result = ErrorVault_Content_Drafts::create($page);
    $post = $GLOBALS['ev_inserted'][0];
    check('page' === $post['post_type'], 'Saved as a page');
    check('draft' === $post['post_status'], 'Saved as a draft, never published');
    check(!isset($post['ID']) && !isset($post['import_id']), 'Always a new post: no existing post is targeted');
    check('Shower doors in Cape Town' === $post['post_title'], 'Title is plain text');
    check('shower-doors-cape-town' === $post['post_name'], 'Slug is sanitised');
    check(3 === $post['post_author'], 'Owned by the longest-standing administrator');
    check('administrator' === $GLOBALS['ev_users_args']['role'] && 'ASC' === $GLOBALS['ev_users_args']['order'], 'Author is looked up among administrators, oldest first');
    check(false === strpos($post['post_content'], '<script') && false === strpos($post['post_content'], '<img'), 'Unsupported HTML is removed');
    check(false === strpos($post['post_content'], 'alert(1)'), 'Script text is removed along with the tag');
    check(false === strpos($post['post_content'], '<!--') && false === strpos($post['post_content'], 'wp:html'), 'Comments, including block markup, are removed');
    check(false === strpos($post['post_content'], '[gallery'), 'Registered shortcodes are removed');
    check(false !== strpos($post['post_content'], '[Add your price]'), 'Placeholders for the owner stay');
    check('<p>end</p>' === substr($post['post_content'], -10), 'An unclosed comment does not hide earlier content');
    check(7 === $post['meta_input'][ErrorVault_Content_Drafts::META_DRAFT_ID], 'The portal draft id is recorded');
    check('Fitted in a day.' === $post['meta_input'][ErrorVault_Content_Drafts::META_DESCRIPTION], 'Meta description is stored');
    check(!isset($post['meta_input']['_yoast_wpseo_metadesc']), 'No Yoast field without Yoast');
    check(42 === $result['post_id'], 'Reports the new post id');
    check('https://client.example/wp-admin/post.php?post=42&action=edit' === $result['edit_url'], 'Reports the edit link');

    $kses = $GLOBALS['ev_kses'][0];
    check(array('h2', 'h3', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'blockquote', 'br', 'a') === array_keys($kses['allowed']), 'Only the page tags are allowed');
    check(array('href' => true) === $kses['allowed']['a'], 'Links keep only href');
    foreach ($kses['allowed'] as $tag => $attributes) {
        check('a' === $tag || array() === $attributes, "No attributes on <$tag>");
    }
    check(array('http', 'https', 'mailto', 'tel') === $kses['protocols'], 'Links are limited to safe protocols');

    define('WPSEO_VERSION', '25.0');
    ErrorVault_Content_Drafts::create($page);
    check('Fitted in a day.' === $GLOBALS['ev_inserted'][1]['meta_input']['_yoast_wpseo_metadesc'], 'Fills Yoast\'s description when Yoast is active');

    check(refuses(array('title' => '', 'content' => '<p>x</p>'), 'no title or content'), 'A page needs a title');
    check(refuses(array('title' => 'x', 'content' => '  '), 'no title or content'), 'A page needs content');
    check(refuses(array('title' => 'x', 'content' => '<script>alert(1)</script>'), 'no content left'), 'Content made only of removed HTML is refused');
    check(refuses(array('title' => 'x', 'content' => str_repeat('a', 100001)), 'too long'), 'Oversized content is refused');

    $GLOBALS['ev_admins'] = array();
    check(refuses($page, 'No administrator'), 'Refused without an administrator to own it');
    $GLOBALS['ev_admins'] = array(3);

    $GLOBALS['ev_insert_error'] = 'Database is read-only';
    check(refuses($page, 'Database is read-only'), 'WordPress errors are reported');
    unset($GLOBALS['ev_insert_error']);

    // The action queue: each setting governs only its own kind of action.
    $GLOBALS['ev_settings'] = array('allow_remote_actions' => true);
    ErrorVault_Security_Actions::process(array(array('id' => 1, 'type' => 'create_draft_page', 'params' => $page)));
    check('failed' === end($GLOBALS['ev_reports'])['status'], 'Remote actions alone do not allow a draft page');
    check(false !== strpos(end($GLOBALS['ev_reports'])['message'], 'Allow draft pages'), 'The refusal names the draft-pages setting');
    check('create_draft_page' === $GLOBALS['ev_options'][ErrorVault_Security_Actions::REFUSED_OPTION]['type'], 'The refusal is noted for wp-admin');

    $GLOBALS['ev_settings'] = array('allow_content_drafts' => true);
    $inserted = count($GLOBALS['ev_inserted']);
    ErrorVault_Security_Actions::process(array(array('id' => 2, 'type' => 'create_draft_page', 'params' => $page)));
    $report = end($GLOBALS['ev_reports']);
    check('completed' === $report['status'] && 42 === $report['data']['post_id'], 'Draft pages run with only their own setting on');
    check($inserted + 1 === count($GLOBALS['ev_inserted']), 'One draft is saved');

    ErrorVault_Security_Actions::process(array(array('id' => 3, 'type' => 'logout_all', 'params' => array())));
    check('failed' === end($GLOBALS['ev_reports'])['status'], 'The draft-pages setting does not allow cleanup actions');

    ErrorVault_Security_Actions::process(array(array('id' => 2, 'type' => 'create_draft_page', 'params' => $page)));
    check($inserted + 1 === count($GLOBALS['ev_inserted']), 'A repeated action id never saves a second draft');

    define('ERRORVAULT_DISABLE_REMOTE_ACTIONS', true);
    check(!ErrorVault_Content_Drafts::enabled(), 'ERRORVAULT_DISABLE_REMOTE_ACTIONS turns draft pages off too');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK ($checks checks)" . PHP_EOL;
