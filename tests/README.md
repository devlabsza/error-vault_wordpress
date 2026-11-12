# Regression suites

Run `php tests/security-scanner-test.php`, `php tests/backup-restorer-test.php`, `php tests/security-hardening-test.php` and `php tests/content-drafts-test.php` (all are also run in CI on PHP 7.4, 8.3 and 8.5). No WordPress installation, database, Composer packages or network calls are needed.

## Security scanner

The harness stubs WordPress APIs and creates a private temporary site tree, which is removed in a finally block. Suspect file contents are test strings and are never included/executed. Cron callbacks and commands are inspected but never invoked. The system cron test supplies a temporary file, not the host crontab.

Covered: whole-template AIOS recognition with a checksum-verified target; tampered/misnamed loaders; official-digest PHAR recognition and failure cases; inert guards, compiler-halted data and executable upload files; double extensions and custom uploads; comments/literal signature examples; direct execution signatures; root scanning without checksums; skipped-file coverage; WP/system cron review and command confidentiality; content-bound trust; duplicate and oversized reports.

The test PHAR is a mock byte string and its remote checksum is mocked. Separately during the 2026-09-11 review, the actual published WP-CLI 2.12.0 PHAR matched its official SHA-512 checksum, and the current AIOS bootstrap generator produced a template accepted by the matcher.

These regression tests establish behavior for the covered cases, not a detection-rate estimate or assurance that a production site is clean. The changes still require a plugin release, deployment and a fresh scan of the affected sites.

1.10.1 adds cases for excluded folder names outside their legitimate locations (shells in `uploads/node_modules`, `uploads/.git`, `uploads/updraft`, `uploads/errorvault-backups` and unrecorded quarantine-like folders), legitimate backup, quarantine and undo-point folders staying skipped, quarantine and restore records pointing elsewhere, `node_modules` and `.git` in plugins and themes inspected last (including scan-limit gaps and `node_modules/.bin` links), and `errorvault_security_scan_skip_dirs` entries as plain names, relative paths and absolute paths.

1.10.0 adds cases for sinks after a keyword or comment (`echo shell_exec(...)`), method dispatch on request input, compressed PHAR data, files too dense to tokenize within the memory limit, `version.php` statements and commented-out version lines, and the theme file-count limit.

1.9.1 adds Wordfence loader and tampering cases, dotted AIOS names, guarded WP Hide JSON data, plain-data PHP suffixes, cron counts and uploads indicator classification. WP Hide fixtures follow the official 1.4.9.1 package generator; Wordfence follows the current official package generator. Redux PHP contents from the affected production site have not been inspected.

## Security headers

`security-hardening-test.php` verifies that every header is opt-in, existing headers win case-insensitively, `X-Powered-By` is removed only when selected, Permissions-Policy values cannot inject another header, and the plugin never emits HSTS or CSP without host/site-specific review.

## Draft pages

`content-drafts-test.php` stubs the WordPress calls `ErrorVault_Content_Drafts` makes and records what it asks for. It verifies that a page is always saved as a new draft page owned by the longest-standing administrator, never published and never targeting an existing post. It also checks the HTML handed to `wp_kses`: only headings, paragraphs, lists, emphasis, quotes and `href` on links, limited to http, https, mailto and tel. Scripts are removed together with their text, comments (block markup) and registered shortcodes are removed, and `[placeholders]` stay. Through the real action queue, it checks that draft pages need their own setting, that this setting doesn't allow cleanup actions (nor remote actions draft pages), that a repeated action id saves nothing twice, and that `ERRORVAULT_DISABLE_REMOTE_ACTIONS` turns draft pages off too.

`wp_kses` itself is stubbed here. During the 1.11.0 change the same allowlist was run once through WordPress 7.1.4's real kses with hostile input (encoded `javascript:` and `data:` links, event handlers, `style`, SVG, forms, iframes, block comments and a registered shortcode). All of it was removed or neutralised. That check is not part of the committed suite.

## Database import gate (backup restore)

`backup-restorer-test.php` exercises the gate in `EV_Backup_Restorer` that decides which statements from a backup's `database.sql` may run during a restore (`plan_statement`, `is_allowed_set`, `table_tail_is_allowed`, `is_noise_line`). That logic is pure and is called directly by reflection; no database is touched and dump text is only ever inspected as a string, never executed.

Covered: the crafted statements from the import-gate review are each skipped — a multi-table `DROP` (which would drop the live `wp_users`), `INSERT`/`REPLACE`/`CREATE ... SELECT` (including a `SELECT`, function or variable nested inside `VALUES`, and `CREATE (...) AS SELECT`), `CREATE ... LIKE`/`AS`, `ALTER ... RENAME`/`ADD COLUMN`, and any `SET` that reaches a `GLOBAL` / `@@GLOBAL` / `PERSIST` variable even after a valid assignment. The allowed forms still run: `SET NAMES`/`CHARACTER SET`, the session variables mysqldump and the plugin exporter set (including comma-separated `@OLD_*=@@x, x=0` pairs, per-table `character_set_client` save/restore with a bare charset value, and footer restores), and each table's `DROP`/`CREATE`/`INSERT`/`DISABLE|ENABLE KEYS` rewritten only in the first table name. GTID and binary-log `SET`s, `LOCK`/`UNLOCK TABLES` and the MySQL/MariaDB "enable the sandbox mode" comment are skipped, and another install's tables in a shared database are never touched. Two full fixtures — a real GTID-enabled `mysqldump` dump and the plugin's own export-format-2 output — must import with every table statement rewritten to its `evr_` copy and the row data (commas, semicolons, backticks, quotes and SQL keywords inside string values) left verbatim.

A `mysqldump`/mariadb-dump backstop that these DB-free tests cannot show: `mysqli_query` runs one statement at a time (WordPress does not enable `CLIENT_MULTI_STATEMENTS`), so a valid `INSERT ... VALUES (...);` followed by a smuggled `DROP TABLE ...;` in one line is rejected by the server rather than executed. This was confirmed once against a live MySQL 9.7 during the change, alongside a checksum comparison proving the `evr_` copies of a real dump equal the originals; that check is not part of the committed, database-free suite.
