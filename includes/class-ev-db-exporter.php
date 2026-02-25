<?php
/**
 * Database Exporter for ErrorVault Backups
 * Pure PHP implementation for shared hosting compatibility
 */

if (!defined('ABSPATH')) {
    exit;
}

class EV_DB_Exporter {

    /**
     * WordPress database object
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Export database to SQL file
     */
    public function export_to_sql($target_sql_path) {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');
        
        try {
            $handle = fopen($target_sql_path, 'w');
            
            if (!$handle) {
                $this->log('Failed to open file for writing: ' . $target_sql_path);
                return false;
            }

            $this->write_header($handle);

            $tables = $this->get_tables();
            
            if (empty($tables)) {
                $this->log('No tables found to export');
                fclose($handle);
                return false;
            }

            $this->log('Exporting ' . count($tables) . ' tables...');

            foreach ($tables as $table) {
                $this->export_table($handle, $table);
            }

            $this->write_footer($handle);

            fclose($handle);

            $this->log('Database export completed: ' . $target_sql_path);
            return true;

        } catch (Exception $e) {
            $this->log('Export failed: ' . $e->getMessage());
            if (isset($handle) && $handle) {
                fclose($handle);
            }
            return false;
        }
    }

    /**
     * Write SQL file header
     */
    private function write_header($handle) {
        $header = "-- ErrorVault WordPress Database Backup\n";
        $header .= "-- Generated: " . current_time('mysql') . "\n";
        $header .= "-- WordPress Version: " . get_bloginfo('version') . "\n";
        $header .= "-- PHP Version: " . PHP_VERSION . "\n";
        $header .= "-- MySQL Version: " . $this->wpdb->db_version() . "\n";
        $header .= "-- Site URL: " . get_site_url() . "\n";
        $header .= "-- Database: " . DB_NAME . "\n";
        $header .= "--\n\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET time_zone = \"+00:00\";\n\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $header .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

        fwrite($handle, $header);
    }

    /**
     * Write SQL file footer
     */
    private function write_footer($handle) {
        $footer = "\n/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        $footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        $footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";

        fwrite($handle, $footer);
    }

    /**
     * Get all tables in database
     */
    private function get_tables() {
        $tables = array();
        $results = $this->wpdb->get_results('SHOW TABLES', ARRAY_N);

        foreach ($results as $row) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /**
     * Export single table
     */
    private function export_table($handle, $table) {
        fwrite($handle, "\n--\n");
        fwrite($handle, "-- Table structure for table `{$table}`\n");
        fwrite($handle, "--\n\n");

        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

        $create_table = $this->wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        
        if ($create_table) {
            fwrite($handle, $create_table[1] . ";\n\n");
        }

        fwrite($handle, "--\n");
        fwrite($handle, "-- Dumping data for table `{$table}`\n");
        fwrite($handle, "--\n\n");

        $this->export_table_data($handle, $table);
    }

    /**
     * Export table data in batches
     */
    private function export_table_data($handle, $table) {
        $batch_size = 50;
        $offset = 0;

        $count_query = "SELECT COUNT(*) FROM `{$table}`";
        $total_rows = $this->wpdb->get_var($count_query);

        if ($total_rows == 0) {
            return;
        }

        $start_time = microtime(true);
        
        if ($total_rows > 1000) {
            $this->log('Exporting ' . $table . ' (' . number_format($total_rows) . ' rows)...');
        }

        while ($offset < $total_rows) {
            $query = "SELECT * FROM `{$table}` LIMIT {$batch_size} OFFSET {$offset}";
            $rows = $this->wpdb->get_results($query, ARRAY_A);

            if (empty($rows)) {
                break;
            }

            $this->write_insert_statements($handle, $table, $rows);

            $offset += $batch_size;

            if ($offset % 500 === 0) {
                $elapsed = round(microtime(true) - $start_time, 2);
                $percent = round(($offset / $total_rows) * 100);
                $this->log('  ' . $table . ': ' . number_format($offset) . '/' . number_format($total_rows) . ' rows (' . $percent . '%) - ' . $elapsed . 's');
            }
        }

        $total_time = round(microtime(true) - $start_time, 2);
        $this->log('Completed ' . $table . ': ' . number_format($total_rows) . ' rows in ' . $total_time . 's');
    }

    /**
     * Write INSERT statements for rows
     */
    private function write_insert_statements($handle, $table, $rows) {
        if (empty($rows)) {
            return;
        }

        $columns = array_keys($rows[0]);
        $column_list = '`' . implode('`, `', $columns) . '`';

        $values_array = array();

        foreach ($rows as $row) {
            $values = array();
            
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $escaped = $this->wpdb->_real_escape($value);
                    $values[] = "'" . $escaped . "'";
                }
            }

            $values_array[] = '(' . implode(', ', $values) . ')';
        }

        $insert = "INSERT INTO `{$table}` ({$column_list}) VALUES\n";
        $insert .= implode(",\n", $values_array);
        $insert .= ";\n";

        fwrite($handle, $insert);
    }

    /**
     * Log message
     */
    private function log($message) {
        error_log('[ErrorVault DB Export] ' . $message);
    }
}
