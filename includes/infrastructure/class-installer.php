<?php
/** Database installer and schema upgrades.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Creates and upgrades the internal queue tables. */
final class Installer {
    const DB_VERSION = '1.3.0';
    const DB_VERSION_OPTION = 'acm_db_version';

    /** Install or upgrade the queue schema. */
    public static function activate() {
        self::register_capability();
        self::maybe_upgrade();
    }

    /** Upgrade active installations once; never revoke explicitly assigned permissions. */
    public static function register_capability() {
        if ( '1' === get_option( 'acm_capability_version' ) ) { return; }
        $role = get_role( 'administrator' );
        if ( $role ) {
            $role->add_cap( 'manage_ai_content' );
            update_option( 'acm_capability_version', '1', false );
        }
    }

    /** Upgrade only when the stored schema version differs. */
    public static function maybe_upgrade() {
        self::register_capability();
        if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $queue_table  = $wpdb->prefix . 'acm_queue';
        $events_table = $wpdb->prefix . 'acm_queue_events';
        $collate      = $wpdb->get_charset_collate();

        $queue_sql = "CREATE TABLE {$queue_table} (
            item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_id char(36) NOT NULL,
            content_type varchar(32) NOT NULL,
            language varchar(35) NOT NULL,
            status varchar(32) NOT NULL,
            payload longtext NOT NULL,
            generated_output longtext DEFAULT NULL,
            api_metadata longtext DEFAULT NULL,
            processing_token char(36) DEFAULT NULL,
            draft_token char(36) DEFAULT NULL,
            draft_state varchar(20) NOT NULL DEFAULT 'none',
            trashed_at datetime DEFAULT NULL,
            trashed_by bigint(20) unsigned DEFAULT NULL,
            previous_status varchar(32) DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            scheduled_at datetime DEFAULT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (item_id),
            UNIQUE KEY draft_token (draft_token),
            KEY status_created (status,created_at),
            KEY status_updated (status,updated_at),
            KEY language_type (language,content_type),
            KEY batch_id (batch_id),
            KEY scheduled_at (scheduled_at)
        ) {$collate} ENGINE=InnoDB;";

        $events_sql = "CREATE TABLE {$events_table} (
            event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_id bigint(20) unsigned NOT NULL,
            status_from varchar(32) DEFAULT NULL,
            status_to varchar(32) NOT NULL,
            message text DEFAULT NULL,
            api_metadata longtext DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (event_id),
            KEY item_created (item_id,created_at),
            KEY created_at (created_at)
        ) {$collate} ENGINE=InnoDB;";

        dbDelta( $queue_sql );
        dbDelta( $events_sql );
        $queue_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue_table ) ) );
        $events_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $events_table ) ) );
        if ( $queue_table === $queue_exists && $events_table === $events_exists && self::schema_is_ready() ) {
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
        }
    }

    /** Verify every required column and index before acknowledging an additive migration. */
    public static function schema_is_ready() {
        global $wpdb;
        $columns = array(
            'acm_queue' => array( 'item_id', 'batch_id', 'content_type', 'language', 'status', 'payload', 'generated_output', 'api_metadata', 'processing_token', 'draft_token', 'draft_state', 'trashed_at', 'trashed_by', 'previous_status', 'created_by', 'created_at', 'updated_at', 'scheduled_at', 'post_id' ),
            'acm_queue_events' => array( 'event_id', 'item_id', 'status_from', 'status_to', 'message', 'api_metadata', 'created_by', 'created_at' ),
        );
        $indexes = array( 'acm_queue' => array( 'PRIMARY' => array( 'item_id' ), 'draft_token' => array( 'draft_token' ), 'status_created' => array( 'status', 'created_at' ), 'status_updated' => array( 'status', 'updated_at' ), 'language_type' => array( 'language', 'content_type' ), 'batch_id' => array( 'batch_id' ), 'scheduled_at' => array( 'scheduled_at' ) ),
            'acm_queue_events' => array( 'PRIMARY' => array( 'event_id' ), 'item_created' => array( 'item_id', 'created_at' ), 'created_at' => array( 'created_at' ) ) );
        foreach ( $columns as $suffix => $fields ) {
            $table = $wpdb->prefix . $suffix;
            $actual = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
            if ( $wpdb->last_error || array_diff( $fields, $actual ?: array() ) ) { return false; }
            foreach ( $indexes[ $suffix ] as $key => $expected ) {
                $rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ), ARRAY_A );
                if ( $wpdb->last_error || ! $rows ) { return false; }
                usort( $rows, static function ( $a, $b ) { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; } );
                if ( array_column( $rows, 'Column_name' ) !== $expected || ( in_array( $key, array( 'PRIMARY', 'draft_token' ), true ) && (int) $rows[0]['Non_unique'] !== 0 ) ) { return false; }
            }
        }
        return true;
    }
}
