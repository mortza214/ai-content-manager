<?php
/** Actual Installer with DDL recorder only. Does not execute MySQL or WordPress dbDelta. */
define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
require __DIR__ . '/../includes/infrastructure/class-installer.php';
$options = array( 'acm_db_version' => '1.3.0', 'acm_capability_version' => '1' ); $migration_statements = array();
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; }
function get_role( $role ) { return null; }
function verify_migration( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'PASS: ' . $label . "\n"; }
$wpdb = new class {
    public $prefix = 'wp_'; public $last_error = ''; public $schema = array(); public $missing = false;
    public $old_rows = array( array( 'item_id' => 31, 'payload' => 'fixture payload', 'generated_output' => 'fixture article', 'post_id' => 101, 'draft_state' => 'ready' ) );
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function esc_like( $v ) { return $v; }
    public function prepare( $sql, $v ) { return str_replace( '%s', "'" . $v . "'", $sql ); }
    public function get_var( $sql ) { preg_match( "/LIKE '([^']+)'/", $sql, $m ); return isset( $this->schema[$m[1]] ) ? $m[1] : null; }
    public function get_col( $sql ) { $table = substr( $sql, strlen( 'SHOW COLUMNS FROM ' ) ); $c = $this->schema[$table]['columns'] ?? array(); return $this->missing ? array_diff( $c, array( 'campaign_stage' ) ) : $c; }
    public function get_results( $sql, $format ) { preg_match( "/SHOW INDEX FROM (\w+) WHERE Key_name = '([^']+)'/", $sql, $m ); return $this->schema[$m[1]]['indexes'][$m[2]] ?? array(); }
};
define( 'ARRAY_A', 'ARRAY_A' );
$before = serialize( $wpdb->old_rows ); $wpdb->missing = true;
AIContentManager\Infrastructure\Installer::maybe_upgrade();
verify_migration( '1.3.0' === $options['acm_db_version'], 'partial schema never acknowledges successful migration' );
$wpdb->missing = false; AIContentManager\Infrastructure\Installer::maybe_upgrade();
verify_migration( '1.4.0' === $options['acm_db_version'] && 8 === count( $migration_statements ), 'retry submits four additive table definitions and acknowledges complete schema' );
verify_migration( $before === serialize( $wpdb->old_rows ), 'migration contract preserves existing queue article draft link and payload' );
$count = count( $migration_statements ); AIContentManager\Infrastructure\Installer::maybe_upgrade();
verify_migration( $count === count( $migration_statements ), 'successful repeated bootstrap does not rerun migration' );
verify_migration( ! preg_match( '/\b(?:DROP|TRUNCATE|DELETE|REPLACE)\b/i', implode( "\n", $migration_statements ) ), 'DDL migration contains no destructive statement' );
