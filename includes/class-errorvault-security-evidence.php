<?php
/** Static inspection only: never include, execute or unserialize a suspect file. */
if (!defined('ABSPATH')) { exit; }

class ErrorVault_Security_Evidence {
    /** Official release SHA-512 files, retrieved 2026-09-11. No filename-only trust.
     * https://github.com/wp-cli/wp-cli/releases (wp-cli-VERSION.phar.sha512)
     */
    public static function wp_cli_release_hashes() {
        return array(
            '2.12.0' => 'be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832',
            '2.11.0' => 'adb12146bab8d829621efed41124dcd0012f9027f47e0228be7080296167566070e4a026a09c3989907840b21de94b7a35f3bfbd5f827c12f27c5803546d1bba',
            '2.10.0' => 'c243265be520cd906f6dac767b56bb4e7dae9b6308db32b7e45ed8adbacad97bce987fd69b019d25478f394f0082404a0f44a93416f5e4d943cb32fd08f1feac',
        );
    }

    /** Preserve token boundaries and literals; discard comments/formatting only. */
    public static function tokens($content, $mask_strings = false) {
        $result = array();
        foreach (token_get_all($content) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) { continue; }
                $text = $token[1];
                if (T_OPEN_TAG === $token[0]) { $text = '<?php '; }
                if ($mask_strings && in_array($token[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML), true)) { $text = "'LITERAL'"; }
                $result[] = array($token[0], $text);
            } else { $result[] = $token; }
        }
        return $result;
    }

    public static function executable_text($content) {
        $text = '';
        foreach (self::tokens($content, true) as $token) {
            $text .= is_array($token) ? $token[1] : $token;
        }
        return $text;
    }

    /** Empty directory guards and compiler-halted data are not executable payloads. */
    public static function inert_php($content) {
        $tokens = self::tokens($content);
        // __halt_compiler is safe only as the FIRST statement. All following bytes are data.
        $prefix = self::tokens('<?php __halt_compiler();');
        if (array_slice($tokens, 0, count($prefix)) === $prefix) { return true; }
        if ($tokens && is_array(end($tokens)) && T_INLINE_HTML === end($tokens)[0] && '' === trim(end($tokens)[1])) { array_pop($tokens); }
        if ($tokens && is_array(end($tokens)) && T_CLOSE_TAG === end($tokens)[0]) { array_pop($tokens); }
        foreach (array('<?php', '<?php exit;', '<?php exit();', '<?php die;', '<?php die();') as $guard) {
            if ($tokens === self::tokens($guard)) { return true; }
        }
        return false;
    }

    /** Match complete AIOS bootstrap templates, not a filename or identifying comment. */
    public static function aios_bootstrap($content, $root, $plugin_dir, $uploads_dir) {
        $firewall = rtrim($plugin_dir, '/') . '/all-in-one-wp-security-and-firewall/classes/firewall/wp-security-firewall.php';
        $rules = rtrim($uploads_dir, '/') . '/aios/firewall-rules/';
        $expressions = function ($path) use ($root) {
            $values = array(var_export($path, true));
            if (0 === strpos($path, rtrim($root, '/') . '/')) {
                $values[] = "__DIR__ . " . var_export(substr($path, strlen(rtrim($root, '/'))), true);
            }
            return $values;
        };
        foreach ($expressions($firewall) as $target) {
            $loader = 'if (file_exists(' . $target . ')) { include_once(' . $target . '); }';
            $variants = array('<?php ' . $loader);
            foreach ($expressions($rules) as $rule) {
                $globals = '$GLOBALS[\'aiowps_firewall_rules_path\'] = ' . $rule . ';';
                $variants[] = '<?php ' . $globals . $loader;
                $variants[] = '<?php ' . $globals . '$GLOBALS[\'aiowps_firewall_data\'] = array(\'ABSPATH\' => ' . var_export(rtrim($root, '/') . '/', true) . ',);' . $loader;
            }
            foreach ($variants as $expected) {
                if (self::tokens($content) === self::tokens($expected)) { return true; }
            }
        }
        // Chained auto_prepend_file loaders require separate review; don't silently bless them.
        return false;
    }

    /** Review signals, not proof of infection. Never report full commands/arguments (secrets). */
    public static function cron_command_reason($command) {
        if (preg_match('~\b(?:curl|wget)\b[^\r\n]*(?:\|\s*(?:/[^\s|;]+/)?(?:ba|da|z|k)?sh\b|[;&]+\s*(?:ba|da|z|k)?sh\b)~i', $command)) {
            return 'downloads remote content and passes it to a shell';
        }
        if (preg_match('~\b(?:base64\s+(?:-d|--decode)|openssl\s+enc\b)[^\r\n]*\|\s*(?:/[^\s|;]+/)?(?:php|python[0-9.]*|perl|(?:ba|da|z|k)?sh)\b~i', $command)) {
            return 'decodes a payload and passes it to an interpreter';
        }
        if (preg_match('~\b(?:php[0-9.]*|perl|python[0-9.]*|(?:ba|da|z|k)?sh)\s+[^\r\n]*(?:/uploads/|/tmp/|/var/tmp/|/dev/shm/)~i', $command)) {
            return 'executes a script from uploads or a temporary directory';
        }
        return null;
    }

    public static function cron_argument_reason($args, $depth = 0, &$budget = null) {
        if (null === $budget) { $budget = 262144; }
        if ($depth > 8 || $budget <= 0) { return 'argument inspection limit reached'; }
        if (is_array($args)) {
            foreach ($args as $value) {
                $reason = self::cron_argument_reason($value, $depth + 1, $budget);
                if ($reason) { return $reason; }
            }
        } elseif (is_string($args)) {
            if (strlen($args) > $budget) { return 'argument inspection limit reached'; }
            $budget -= strlen($args);
            if (preg_match('~<\?(?:php\b|=)|\beval\s*\(\s*(?:base64_decode|gzinflate|gzdecode)\s*\(~i', $args)) { return 'arguments contain PHP source or an encoded-code pattern'; }
            return self::cron_command_reason($args);
        } elseif (is_object($args) || is_resource($args)) { return 'arguments contain an unsupported value'; }
        return null;
    }
}
