<?php
/**
 * Draft pages written in the Error-Vault portal from AI visibility results.
 *
 * The portal sends a title, slug, meta description and HTML; this saves them
 * as a new draft page and nothing more:
 *
 *  - Always a new page with status "draft". Nothing is published, scheduled
 *    or made private, and no existing post or page is ever changed.
 *  - The HTML is reduced to headings, paragraphs, lists, emphasis, quotes and
 *    links (http, https, mailto, tel or site-relative), with no other attributes.
 *    HTML comments (block markup) and registered shortcodes are removed.
 *  - Off until a site owner turns on "Allow draft pages" in Settings,
 *    Error-Vault, separately from remote cleanup actions.
 *    define('ERRORVAULT_DISABLE_REMOTE_ACTIONS', true); keeps it off too.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ErrorVault_Content_Drafts {

    const TYPE = 'create_draft_page';
    const SETTING = 'allow_content_drafts';
    const META_DRAFT_ID = '_errorvault_page_draft_id';
    const META_DESCRIPTION = '_errorvault_meta_description';
    const MAX_CONTENT = 100000;

    public static function enabled() {
        if (defined('ERRORVAULT_DISABLE_REMOTE_ACTIONS') && ERRORVAULT_DISABLE_REMOTE_ACTIONS) {
            return false;
        }
        $settings = get_option('errorvault_settings', array());
        return !empty($settings[self::SETTING]);
    }

    /** Tags and attributes a draft page may contain. */
    public static function allowed_html() {
        return array(
            'h2' => array(), 'h3' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
            'strong' => array(), 'em' => array(), 'blockquote' => array(), 'br' => array(),
            'a' => array('href' => true),
        );
    }

    /**
     * Save the portal's page as a new draft page.
     *
     * @return array message, post_id, edit_url
     * @throws Exception when the page is unusable or WordPress refuses it.
     */
    public static function create(array $params) {
        $title = isset($params['title']) ? self::limit(sanitize_text_field((string) $params['title']), 200) : '';
        $raw = isset($params['content']) ? (string) $params['content'] : '';
        if ('' === $title || '' === trim($raw)) {
            throw new Exception('The page has no title or content.');
        }
        if (strlen($raw) > self::MAX_CONTENT) {
            throw new Exception('The page is too long.');
        }

        // kses drops a tag but keeps its text; scripts and the like go entirely.
        $raw = preg_replace('#<(script|style|iframe|object|embed|noscript|template|svg|math|textarea|select)\b[^>]*>.*?</\1\s*>#is', '', $raw);
        $content = wp_kses((string) $raw, self::allowed_html(), array('http', 'https', 'mailto', 'tel'));
        // kses keeps comments, which the block editor reads as block markup, and
        // shortcodes would run plugin code on preview. [Placeholders] stay.
        $content = trim(strip_shortcodes(preg_replace('/<!--.*?(?:-->|$)/s', '', $content)));
        if ('' === trim(wp_strip_all_tags($content))) {
            throw new Exception('The page has no content left after removing unsupported HTML.');
        }

        $slug = isset($params['slug']) ? sanitize_title((string) $params['slug']) : '';
        $description = isset($params['meta_description']) ? self::limit(sanitize_text_field((string) $params['meta_description']), 300) : '';

        $author = self::author_id();
        if (!$author) {
            throw new Exception('No administrator found to own the draft page.');
        }

        $meta = array(self::META_DRAFT_ID => isset($params['page_draft_id']) ? absint($params['page_draft_id']) : 0);
        if ('' !== $description) {
            $meta[self::META_DESCRIPTION] = $description;
            // Fill the description field of the SEO plugin in use, if any.
            if (defined('WPSEO_VERSION')) {
                $meta['_yoast_wpseo_metadesc'] = $description;
            }
            if (class_exists('RankMath')) {
                $meta['rank_math_description'] = $description;
            }
        }

        $post_id = wp_insert_post(array(
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_author' => $author,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'meta_input' => $meta,
        ), true);

        if (is_wp_error($post_id)) {
            throw new Exception('WordPress could not save the draft: ' . $post_id->get_error_message());
        }
        if (!$post_id) {
            throw new Exception('WordPress could not save the draft.');
        }

        return array(
            'message' => sprintf('Saved draft page #%d "%s".', $post_id, $title),
            'post_id' => (int) $post_id,
            'edit_url' => admin_url('post.php?post=' . (int) $post_id . '&action=edit'),
        );
    }

    /**
     * Cron runs without a signed-in user, so the draft belongs to the longest-standing
     * administrator; they can reassign it in WordPress.
     */
    private static function author_id() {
        $admins = get_users(array('role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID'));
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    private static function limit($text, $max) {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }
}
