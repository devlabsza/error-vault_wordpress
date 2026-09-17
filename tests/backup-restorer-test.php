<?php
/**
 * Standalone regression harness for the database-import gate in
 * EV_Backup_Restorer (includes/class-ev-backup-restorer.php).
 *
 * No WordPress, database, Composer packages or network calls are needed: the
 * gate that decides which dump statements may run (plan_statement / is_allowed_set
 * / table_tail_is_allowed / is_noise_line) is pure and is exercised directly by
 * reflection. Dump text is inspected as data and never executed.
 *
 * Run: php tests/backup-restorer-test.php
 */

error_reporting(E_ALL);
define('ABSPATH', sys_get_temp_dir() . '/');
require __DIR__ . '/../includes/class-ev-backup-helpers.php';
require __DIR__ . '/../includes/class-ev-backup-restorer.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function invoke($object, $method, ...$args) {
    $ref = new ReflectionMethod($object, $method);
    if (PHP_VERSION_ID < 80100) {
        $ref->setAccessible(true);
    }
    return $ref->invoke($object, ...$args);
}

/** How the gate classifies one already-assembled statement. */
function action($r, $query, $prefix = 'wp_', array $foreign = array()) {
    return invoke($r, 'plan_statement', $query, $prefix, $foreign)['action'];
}

/**
 * Mirror only the trivial line-assembly of import_to_temp_tables() (blank/";"
 * handling), delegating every decision to the plugin's own is_noise_line() and
 * plan_statement(). Returns the queries that would actually run, in order, so a
 * whole dump can be checked without a database.
 */
function run_dump($r, $sql, $prefix = 'wp_', array $foreign = array()) {
    $queries = array();
    $created = array();
    $statement = '';
    foreach (preg_split('/(?<=\n)/', $sql) as $line) {
        if ('' === $line) {
            continue;
        }
        if ('' === $statement) {
            $trimmed = ltrim($line);
            if (invoke($r, 'is_noise_line', $trimmed)) {
                continue;
            }
            if (0 === stripos($trimmed, 'DELIMITER')) {
                throw new RuntimeException('DELIMITER encountered');
            }
        }
        $statement .= $line;
        if (!preg_match('/;\s*$/', $line)) {
            continue;
        }
        $query = $statement;
        $statement = '';
        $plan = invoke($r, 'plan_statement', $query, $prefix, $foreign);
        if ('skip' === $plan['action']) {
            continue;
        }
        if ('table' === $plan['action'] && $plan['is_create']) {
            $created[] = $plan['table'];
        }
        $queries[] = array(
            'action' => $plan['action'],
            'table' => isset($plan['table']) ? $plan['table'] : null,
            'sql' => $plan['query'],
        );
    }
    return array('queries' => $queries, 'created' => $created);
}
function ran_sql($result) {
    return array_map(function ($q) { return $q['sql']; }, $result['queries']);
}
function joined($result) {
    return implode("\n", ran_sql($result));
}

$r = new EV_Backup_Restorer();

/* ------------------------------------------------------------------
 * 1. The crafted statements from the report must all be skipped.
 * ------------------------------------------------------------------ */

// A multi-table DROP would drop the live `wp_users` (only the first name is
// rewritten to the evr_ copy). One table only is allowed.
check('skip' === action($r, "DROP TABLE IF EXISTS `wp_a`, `wp_users`;\n"), 'Multi-table DROP is skipped');
check('skip' === action($r, "DROP TABLE `wp_users`, `wp_a`;\n"), 'Multi-table DROP (site table first) is skipped');
check('table' === action($r, "DROP TABLE IF EXISTS `wp_options`;\n"), 'Single-table DROP is allowed');
check('table' === action($r, "DROP TABLE `wp_options`;\n"), 'Single-table DROP without IF EXISTS is allowed');

// INSERT/REPLACE ... SELECT reads other data; only VALUES is allowed.
check('skip' === action($r, "INSERT INTO `wp_options` SELECT * FROM other_db.wp_users;\n"), 'INSERT ... SELECT is skipped');
check('skip' === action($r, "INSERT INTO `wp_options` (`a`) SELECT `a` FROM x;\n"), 'INSERT (cols) SELECT is skipped');
check('skip' === action($r, "REPLACE INTO `wp_options` SELECT * FROM x;\n"), 'REPLACE ... SELECT is skipped');
check('skip' === action($r, "INSERT INTO `wp_options` SET `a` = 1;\n"), 'INSERT ... SET is skipped');
check('skip' === action($r, "INSERT INTO `wp_options` VALUES ((SELECT user_pass FROM `wp_users`));\n"), 'Subquery inside VALUES is skipped');
check('skip' === action($r, "INSERT INTO `wp_options` VALUES (LOAD_FILE('/etc/passwd'));\n"), 'Function inside VALUES is skipped');
check('skip' === action($r, "INSERT INTO `wp_options` VALUES (@secret);\n"), 'Variable expression inside VALUES is skipped');

// CREATE TABLE ... SELECT / LIKE / AS copies or reads other tables.
check('skip' === action($r, "CREATE TABLE `wp_options` SELECT * FROM wp_users;\n"), 'CREATE ... SELECT is skipped');
check('skip' === action($r, "CREATE TABLE `wp_options` LIKE `wp_users`;\n"), 'CREATE ... LIKE is skipped');
check('skip' === action($r, "CREATE TABLE `wp_options` AS SELECT 1;\n"), 'CREATE ... AS SELECT is skipped');
check('skip' === action($r, "CREATE TABLE `wp_options` (`v` text) AS SELECT user_pass FROM `wp_users`;\n"), 'CREATE definition followed by AS SELECT is skipped');
check('skip' === action($r, "CREATE TABLE `wp_options` (`v` text) SELECT user_pass FROM `wp_users`;\n"), 'CREATE definition followed by SELECT is skipped');
check('skip' === action($r, "CREATE TABLE `wp_options` (`v` text) /*!99999 AS SELECT user_pass FROM `wp_users` */;\n"), 'CREATE executable-comment AS SELECT is skipped');

// ALTER may only enable/disable keys around a data load.
check('skip' === action($r, "ALTER TABLE `wp_options` RENAME TO `other_db`.`t`;\n"), 'ALTER ... RENAME is skipped');
check('skip' === action($r, "ALTER TABLE `wp_options` ADD COLUMN evil INT;\n"), 'ALTER ... ADD COLUMN is skipped');
check('table' === action($r, "/*!40000 ALTER TABLE `wp_options` DISABLE KEYS */;\n"), 'ALTER ... DISABLE KEYS is allowed');
check('table' === action($r, "/*!40000 ALTER TABLE `wp_options` ENABLE KEYS */;\n"), 'ALTER ... ENABLE KEYS is allowed');
check('table' === action($r, "ALTER TABLE `wp_options` DISABLE KEYS;\n"), 'Unwrapped ALTER ... DISABLE KEYS is allowed');

// SET must never reach a GLOBAL / @@GLOBAL / PERSIST variable, even when a valid
// assignment comes first.
check('skip' === action($r, "SET @x = 1, GLOBAL general_log = 1;\n"), 'SET with a trailing GLOBAL is skipped');
check('skip' === action($r, "SET FOREIGN_KEY_CHECKS = 0, GLOBAL read_only = 1;\n"), 'SET with GLOBAL read_only is skipped');
check('skip' === action($r, "SET @@GLOBAL.GTID_PURGED='x';\n"), 'SET @@GLOBAL.* is skipped');
check('skip' === action($r, "SET FOREIGN_KEY_CHECKS = 0, @@GLOBAL.read_only = 1;\n"), 'SET with @@GLOBAL.* is skipped');
check('skip' === action($r, "SET GLOBAL general_log = 'ON';\n"), 'SET GLOBAL is skipped');
check('skip' === action($r, "SET PERSIST sql_mode = '';\n"), 'SET PERSIST is skipped');
check('skip' === action($r, "SET @@GLOBAL.sql_mode = '';\n"), 'SET @@GLOBAL.sql_mode is skipped');
check('skip' === action($r, "SET GLOBAL sql_mode = '';\n"), 'SET GLOBAL on an allowed var name is still skipped');

/* ------------------------------------------------------------------
 * 2. Allowed SET forms (mysqldump + plugin exporter) still run; unknown
 *    session/global variables and non-SET noise do not.
 * ------------------------------------------------------------------ */

foreach (array(
    'SET NAMES utf8mb4',
    "/*!50503 SET NAMES utf8mb4 */",
    'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SET CHARACTER SET utf8mb4',
    'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"',
    'SET time_zone = "+00:00"',
    "/*!40103 SET TIME_ZONE='+00:00' */",
    '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */',
    '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */',
    "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */",
    '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */',
    '/*!40101 SET @saved_cs_client     = @@character_set_client */',    // per-table save
    '/*!50503 SET character_set_client = utf8mb4 */',                   // per-table, bare value
    '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */',                         // footer restore
    '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */',
    'SET SESSION SQL_MODE = \'\'',
) as $set) {
    check('session' === action($r, $set . ";\n"), 'Allowed SET runs: ' . $set);
}

// GTID / binary-log preamble that a GTID-enabled server emits: skipped, as before.
check('skip' === action($r, "SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;\n"), 'Log-bin save is skipped');
check('skip' === action($r, "SET @@SESSION.SQL_LOG_BIN = 0;\n"), 'Log-bin write is skipped');
check('skip' === action($r, "SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;\n"), 'Log-bin restore is skipped');
check('session' === action($r, "SET @unknown_session_var = 1;\n"), 'A user-variable save runs');
check('skip' === action($r, "SET timestamp = 1700000000;\n"), 'A session var outside the allow-list is skipped');
check('skip' === action($r, "LOCK TABLES `wp_options` WRITE;\n"), 'LOCK TABLES is skipped');
check('skip' === action($r, "UNLOCK TABLES;\n"), 'UNLOCK TABLES is skipped');
check('skip' === action($r, "SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '14115aea:1-9';\n"), 'GTID_PURGED with an inline comment is skipped');

/* ------------------------------------------------------------------
 * 3. Another install's tables in a shared database are never touched,
 *    whatever the statement.
 * ------------------------------------------------------------------ */

$foreign = array('wp_shop_');
check('skip' === action($r, "DROP TABLE IF EXISTS `wp_shop_options`;\n", 'wp_', $foreign), 'Foreign DROP is skipped');
check('skip' === action($r, "INSERT INTO `wp_shop_options` VALUES (1);\n", 'wp_', $foreign), 'Foreign INSERT is skipped');
check('table' === action($r, "INSERT INTO `wp_options` VALUES (1);\n", 'wp_', $foreign), 'This site INSERT still runs alongside a foreign install');

/* ------------------------------------------------------------------
 * 4. The evr_ rewrite touches only the first table name; data is verbatim.
 * ------------------------------------------------------------------ */

$plan = invoke($r, 'plan_statement', "DROP TABLE IF EXISTS `wp_options`;\n", 'wp_', array());
check('DROP TABLE IF EXISTS `evr_wp_options`;' === trim($plan['query']), 'DROP is rewritten to the evr_ copy only');

$nasty = "a, b; DROP TABLE x; -- `bt` and \\'q\\' and VALUES(1) and SELECT 1";
$plan = invoke($r, 'plan_statement', "INSERT INTO `wp_options` (`option_name`,`option_value`) VALUES (1,'" . $nasty . "');\n", 'wp_', array());
check('table' === $plan['action'] && !$plan['is_create'], 'Extended INSERT with nasty data is a data load');
check(0 === strpos(ltrim($plan['query']), 'INSERT INTO `evr_wp_options` ('), 'INSERT rewritten to the evr_ copy');
check(false !== strpos($plan['query'], "'" . $nasty . "'"), 'INSERT data (commas, ";", backticks, quotes, keywords) is left verbatim');
check(false === strpos($plan['query'], '`evr_wp_options`.') , 'No second table name is introduced');

$create = "CREATE TABLE `wp_options` (\n  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`option_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
$plan = invoke($r, 'plan_statement', $create, 'wp_', array());
check('table' === $plan['action'] && $plan['is_create'], 'CREATE TABLE is recognised as a create');
check(0 === strpos($plan['query'], 'CREATE TABLE `evr_wp_options` ('), 'CREATE rewritten to the evr_ copy');

/* ------------------------------------------------------------------
 * 5. A real mysqldump dump (MySQL, GTID-enabled server) imports unchanged:
 *    table data + session setup run against evr_ copies; GTID, binary-log,
 *    LOCK/UNLOCK lines are skipped. Nothing reaches a live or foreign table.
 * ------------------------------------------------------------------ */

$mysql_dump = <<<'SQL'
-- MySQL dump 10.13  Distrib 9.7.1, for macos26.4 (arm64)
--
-- Host: localhost    Database: ev_gate_test
-- ------------------------------------------------------
-- Server version	9.7.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;

--
-- GTID state at the beginning of the backup
--

SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '14115aea-918e-11f1-ba3f-57d7306c8e80:1-258512';

--
-- Table structure for table `wp_options`
--

DROP TABLE IF EXISTS `wp_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_options`
--

LOCK TABLES `wp_options` WRITE;
/*!40000 ALTER TABLE `wp_options` DISABLE KEYS */;
INSERT INTO `wp_options` VALUES (1,'siteurl','http://ex.test','yes'),(2,'funny','a, b; DROP TABLE x; -- and `bt` and \'q\' and VALUES(1)','yes'),(3,'percent','100% sure, really','yes');
/*!40000 ALTER TABLE `wp_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wp_users`
--

DROP TABLE IF EXISTS `wp_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wp_users` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wp_users`
--

LOCK TABLES `wp_users` WRITE;
/*!40000 ALTER TABLE `wp_users` DISABLE KEYS */;
INSERT INTO `wp_users` VALUES (1,'admin');
/*!40000 ALTER TABLE `wp_users` ENABLE KEYS */;
UNLOCK TABLES;
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-17 10:56:59
SQL;

$result = run_dump($r, $mysql_dump);
$sql_run = joined($result);
check(array('wp_options', 'wp_users') === $result['created'], 'Both site tables are created from the real dump');
// Every table statement targets an evr_ copy; nothing reaches the live tables.
check(false === strpos($sql_run, '`wp_options`') && false === strpos($sql_run, '`wp_users`'), 'No live table name is run');
check(substr_count($sql_run, 'DROP TABLE IF EXISTS `evr_wp_options`;') === 1, 'evr_ DROP for wp_options runs once');
check(substr_count($sql_run, 'CREATE TABLE `evr_wp_users`') === 1, 'evr_ CREATE for wp_users runs once');
check(substr_count($sql_run, 'ALTER TABLE `evr_wp_options` DISABLE KEYS') === 1, 'DISABLE KEYS runs against the evr_ copy');
check(substr_count($sql_run, 'ALTER TABLE `evr_wp_options` ENABLE KEYS') === 1, 'ENABLE KEYS runs against the evr_ copy');
check(false !== strpos($sql_run, "a, b; DROP TABLE x; -- and `bt` and \\'q\\' and VALUES(1)"), 'Extended INSERT data survives verbatim');
// The dangerous lines never run.
check(false === stripos($sql_run, 'GTID_PURGED'), 'GTID_PURGED never runs');
check(false === stripos($sql_run, 'SQL_LOG_BIN'), 'SQL_LOG_BIN never runs');
check(false === stripos($sql_run, 'GLOBAL'), 'No GLOBAL statement runs');
check(false === stripos($sql_run, 'LOCK TABLES'), 'LOCK TABLES never runs');
// Session setup still runs (character set, sql_mode, time zone, foreign-key checks).
check(false !== strpos($sql_run, 'SET NAMES utf8mb4'), 'SET NAMES runs');
check(false !== strpos($sql_run, "SET TIME_ZONE='+00:00'"), 'TIME_ZONE runs');
check(false !== strpos($sql_run, 'FOREIGN_KEY_CHECKS=0'), 'FOREIGN_KEY_CHECKS=0 runs');

/* ------------------------------------------------------------------
 * 6. The plugin's own PHP exporter output (export format 2) imports unchanged.
 * ------------------------------------------------------------------ */

$exporter_dump = <<<'SQL'
-- Error-Vault WordPress Database Backup
-- Error-Vault export format: 2
-- Generated: 2026-09-17 10:00:00
-- WordPress Version: 6.8.2
-- PHP Version: 8.4.23
-- MySQL Version: 9.7.1
-- Site URL: http://ex.test
-- Database: ev_gate_test
--

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


--
-- Table structure for table `wp_options`
--

DROP TABLE IF EXISTS `wp_options`;
CREATE TABLE `wp_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_options`
--

INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES
('1', 'siteurl', 'http://ex.test', 'yes'),
('2', 'funny', 'a, b; DROP TABLE x; -- keeps `bt`, \'q\', VALUES(9), SELECT 1', 'yes'),
('3', 'percent', '100% done, really', 'yes');

--
-- Table structure for table `wp_users`
--

DROP TABLE IF EXISTS `wp_users`;
CREATE TABLE `wp_users` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_users`
--

INSERT INTO `wp_users` (`ID`, `user_login`) VALUES
('1', 'admin');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
SQL;

$result = run_dump($r, $exporter_dump);
$sql_run = joined($result);
check(array('wp_options', 'wp_users') === $result['created'], 'Both site tables are created from the exporter dump');
check(false === strpos($sql_run, '`wp_options`') && false === strpos($sql_run, '`wp_users`'), 'Exporter dump never runs a live table name');
check(substr_count($sql_run, "DROP TABLE IF EXISTS `evr_") === 2, 'Exporter DROPs both run against evr_ copies');
check(false !== strpos($sql_run, "INSERT INTO `evr_wp_options` (`option_id`, `option_name`"), 'Column-qualified INSERT is rewritten only in the table name');
check(false !== strpos($sql_run, "a, b; DROP TABLE x; -- keeps `bt`, \\'q\\', VALUES(9), SELECT 1"), 'Multi-row exporter INSERT data survives verbatim');
check(false !== strpos($sql_run, 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"'), 'Exporter SQL_MODE runs');
// Every statement in the exporter dump runs (nothing silently dropped):
// 6 header SET + (DROP + CREATE + INSERT) x 2 tables + 3 footer SET = 15.
check(count($result['queries']) === 15, 'Every exporter statement runs (' . count($result['queries']) . ')');

/* ------------------------------------------------------------------
 * 7. Real-world header/comment lines that begin no statement of their own.
 * ------------------------------------------------------------------ */

foreach (array(
    '-- MySQL dump 10.13  Distrib 9.7.1',
    '-- Host: localhost    Database: db',
    'mysqldump: [Warning] Using a password on the command line interface can be insecure.',
    'Warning: A partial dump from a server that has GTIDs ...',
    '/*!999999\- enable the sandbox mode */',        // MySQL/MariaDB sandbox line, no ";"
    '/*M!999999\- enable the sandbox mode */',        // MariaDB spelling
    '',
) as $noise) {
    check(invoke($r, 'is_noise_line', ltrim($noise)), 'Noise line begins no statement: ' . $noise);
}
// A version-guarded statement that DOES end in ";" is not noise.
check(!invoke($r, 'is_noise_line', '/*!40000 ALTER TABLE `wp_options` DISABLE KEYS */;'), 'A /*! ... */; statement is not noise');
check(!invoke($r, 'is_noise_line', '/*!50503 SET NAMES utf8mb4 */;'), 'A /*! SET ... */; statement is not noise');

// The MariaDB sandbox line must not swallow the SET that follows it.
$maria = "/*!999999\\- enable the sandbox mode */\n/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET NAMES utf8mb4 */;\nDROP TABLE IF EXISTS `wp_options`;\n";
$result = run_dump($r, $maria);
check(count($result['queries']) === 3, 'Sandbox line does not merge with the following statement');
check('session' === $result['queries'][0]['action'], 'First SET after the sandbox line still runs');

/* ------------------------------------------------------------------
 * 8. Stored procedures / triggers are refused, as before.
 * ------------------------------------------------------------------ */

$threw = false;
try {
    run_dump($r, "DELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON `wp_options` FOR EACH ROW BEGIN END;;\nDELIMITER ;\n");
} catch (RuntimeException $e) {
    $threw = (false !== strpos($e->getMessage(), 'DELIMITER'));
}
check($threw, 'A dump with DELIMITER (procedures/triggers) is refused');

echo "PASS: $checks backup-restorer import-gate checks\n";
