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
            '2.9.0' => '39fa365300ab45840e30cc344595bbd175c3558a4499679edd9f9e3a00a846e94179c29c80de3242f1c405f3623605605748d188d9bb98450d9377388f60f113',
            '2.8.1' => 'c1d40ee90b330ca1f8ddbed14b938b41ec5d9ff723c7c1cf3f41a2d9a1b271079a51a37ea3d1c9aa9c628fdd43449dba3995a8de150a68abbd505b06b91d9d2b',
            '2.8.0' => 'c6b0af2bcddaadc16d89c03c601176eac0a2022b6706b9ffea8a6fe94bbefcf79bf358802260e7f6e937830bc2110d8cb057bbb397805d7fbb0d1485a56a7b39',
            '2.7.1' => '956b5e3e1a076bd5441c082ee754e3ff4517ec965b93c621f455c2bf5719358c36e67d52f676492700b59d42cacb34a50d382535c035f19da7a0b98bc41860de',
            '2.7.0' => '43ada12f3d462b7e4cd2b29cb8bd11789e57f6cd57be1627eb137bc1312c62a565794e13ede71c80e35356f4253130d0d1869873b80817fdfc55812035a2bd43',
            '2.6.0' => 'd73f9161a1f03b8ecaac7b196b6051fe847b3c402b9c92b1f6f3acbe5b1cf91f7260c0e499b8947bab75920ecec918b39533ca65fa5a1fd3eb6ce7b8e2c58e7d',
            '2.5.0' => '08dd9035fda1d529807380d5b757839e2809e289eb1a698fe33e7e21a1431d3f77c551c2b2db5adc55083d5075ea4137407994111890f765e790a97e6d9ca7af',
            '2.4.0' => '4049c7e45e14276a70a41c3b0864be7a6a8cfa8ea65ebac8b184a4f503a91baa1a0d29260d03248bc74aef70729824330fb6b396336172a624332e16f64e37ef',
            '2.3.0' => 'fdf1c6e7d33665fc9c6202a91fdebc72be6ebad12949ecf0280765bf24819e7ca2072e6834abd3848bceaae0f7aa1896322c837ae5a5b66dd69b760c310e4a30',
            '2.2.0' => '2103f04a5014d629eaa42755815c9cec6bb489ed7b0ea6e77dedb309e8af098ab902b2f9c6369ae4b7cb8cc1f20fbb4dedcda83eb1d0c34b880fa6e8a3ae249d',
            '2.1.0' => 'c2ff556c21c85bbcf11be38d058224f53d3d57a1da45320ecf0079d480063dcdc11b5029b94b0b181c1e3bec84745300cd848d28065c0d3619f598980cc17244',
            '2.0.1' => '21b9c1d65993f88bf81cc73c0a832532cc424bea8c15563a77af1905d0dc4714f2af679dfadedd3b683f3968902b4b6be4c6cf94285da9f5582b30c1dac5397f',
            '2.0.0' => '18c15c792a0747e434f70fb74f8d82dbc6dc504bd6238a4928859430129f12d88d829a1f37203c30813eb5f4d2c69f4e40aa15c74d9c6f01343052f01842463b',
        );
    }

    /** Preserve token boundaries and literals; discard comments/formatting only. */
    public static function tokens($content, $mask_strings = false) {
        $result = array();
        foreach (token_get_all($content) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) { continue; }
                $text = $token[1];
                // Equivalent unescaped single/double quoted literals have identical meaning.
                if (T_CONSTANT_ENCAPSED_STRING === $token[0] && false === strpos(substr($text, 1, -1), chr(92))) { $text = var_export(substr($text, 1, -1), true); }
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
        // Some caches use a .php suffix for plain data. Short tags remain reviewable.
        if (false === strpos($content, '<?')) { return true; }
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

    /** WP Hide's generated environment is a guarded JSON string assignment, not executable logic. */
    public static function generated_upload_data($content, $relative) {
        if ('wph/environment.php' !== $relative) { return false; }
        $tokens = self::tokens($content);
        if ($tokens && is_array(end($tokens)) && T_INLINE_HTML === end($tokens)[0] && '' === trim(end($tokens)[1])) { array_pop($tokens); }
        if ($tokens && is_array(end($tokens)) && T_CLOSE_TAG === end($tokens)[0]) { array_pop($tokens); }
        if ($tokens && ';' === end($tokens)) { array_pop($tokens); }
        $prefix = self::tokens("<?php if (!defined('ABSPATH')) exit; " . '$environment_variable = ');
        if (count($tokens) !== count($prefix) + 1 || array_slice($tokens, 0, count($prefix)) !== $prefix) { return false; }
        $value = end($tokens);
        // Only a single-quoted literal: never evaluate PHP or interpolate strings.
        if (!is_array($value) || T_CONSTANT_ENCAPSED_STRING !== $value[0] || "'" !== $value[1][0]) { return false; }
        $json = str_replace(array(chr(92).chr(92), chr(92)."'"), array(chr(92), "'"), substr($value[1], 1, -1));
        $data = json_decode($json, true);
        return JSON_ERROR_NONE === json_last_error() && is_array($data) && isset($data['allowed_paths'], $data['cache_path']);
    }

    /** Wordfence's generated root loader, checked in full against fixed local paths. */
    public static function wordfence_bootstrap($content, $root, $plugin_dir, $content_dir) {
        $target = rtrim($plugin_dir, '/') . '/wordfence/waf/bootstrap.php';
        $logs = rtrim($content_dir, '/') . '/wflogs/';
        $expressions = function ($path) use ($root) {
            $values = array(var_export($path, true));
            if (0 === strpos($path, rtrim($root, '/') . '/')) {
                $values[] = "__DIR__ . " . var_export(substr($path, strlen(rtrim($root, '/'))), true);
            }
            return $values;
        };
        foreach ($expressions($target) as $file) {
            foreach ($expressions($logs) as $log) {
                foreach (array('include_once ' . $file . ';', 'include_once(' . $file . ');') as $include) {
                    $expected = '<?php if (file_exists(' . $file . ')) { define("WFWAF_LOG_PATH", ' . $log . '); ' . $include . ' }';
                    if (self::tokens($content) === self::tokens($expected)) { return true; }
                }
            }
        }
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
