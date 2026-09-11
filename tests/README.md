# Security scanner regression suite

Run `php tests/security-scanner-test.php`. No WordPress installation, Composer packages or network calls are needed. The harness stubs WordPress APIs and creates a private temporary site tree, which is removed in a finally block. Suspect file contents are test strings and are never included/executed. Cron callbacks and commands are inspected but never invoked. The system cron test supplies a temporary file, not the host crontab.

Covered: whole-template AIOS recognition with a checksum-verified target; tampered/misnamed loaders; official-digest PHAR recognition and failure cases; inert guards, compiler-halted data and executable upload files; double extensions and custom uploads; comments/literal signature examples; direct execution signatures; root scanning without checksums; skipped-file coverage; WP/system cron review and command confidentiality; content-bound trust; duplicate and oversized reports.

The test PHAR is a mock byte string and its remote checksum is mocked. Separately during the 2026-09-11 review, the actual published WP-CLI 2.12.0 PHAR matched its official SHA-512 checksum, and the current AIOS bootstrap generator produced a template accepted by the matcher.

These regression tests establish behavior for the covered cases, not a detection-rate estimate or assurance that a production site is clean. The changes still require a plugin release, deployment and a fresh scan of the affected sites.

1.9.1 adds Wordfence loader and tampering cases, dotted AIOS names, guarded WP Hide JSON data, plain-data PHP suffixes, cron counts and uploads indicator classification. WP Hide fixtures follow the official 1.4.9.1 package generator; Wordfence follows the current official package generator. Redux PHP contents from the affected production site have not been inspected.
