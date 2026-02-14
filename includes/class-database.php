<?php
/**
 * Database Controller for RMSmart Redirects
 * Handles table creation and data management.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMSmart_Database {

    /**
     * Create the custom redirects table.
     * This runs only once when the plugin is activated.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        $charset_collate = $wpdb->get_charset_collate();

        // source_url: The old/broken URL
        // target_url: The destination URL
        // type: 301 (Permanent) or 302 (Temporary)
        // is_forced: If 1, skip 404 check and force redirect
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            source_url varchar(255) NOT NULL,
            target_url varchar(255) NOT NULL,
            redirect_type smallint(5) DEFAULT 301 NOT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            is_forced tinyint(1) DEFAULT 0 NOT NULL,
            hits int(11) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_url (source_url)
        ) $charset_collate;";

        $table_logs = $wpdb->prefix . 'rmsmart_404_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            hits int(11) DEFAULT 1 NOT NULL,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url (url)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        dbDelta( $sql_logs );
    }

    /**
     * Auto-run DB update if table or column is missing
     */
    public static function check_update() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
            self::create_table();
            return;
        }

        // Check if new column 'is_forced' exists
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $col = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table_name LIKE %s", 'is_forced' ) );
        if ( empty( $col ) ) {
            // Column missing? Run dbDelta immediately.
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            self::create_table(); 
            
            // Double check and force alter if dbDelta fails (failsafe)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $col_retry = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table_name LIKE %s", 'is_forced' ) );
            if ( empty( $col_retry ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement, table name from $wpdb->prefix is safe
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN is_forced tinyint(1) DEFAULT 0 NOT NULL");
            }
        }
    }

    /**
     * Helper function to insert or update a redirect.
     * We use this for Layer 1 (Slug changes) and Layer 2 (Fallbacks).
     */
    public static function save_redirect($source, $target, $type = 301, $status = 'active', $is_forced = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';

        // External URL Support: Detect if target is external (starts with http:// or https://)
        // If external, store as-is. If internal, store as path (backward compatible).
        // Note: esc_url_raw() handles both cases safely.
        
        // Check if a redirect already exists for this source URL
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $existing = $wpdb->get_row( $wpdb->prepare( 
            "SELECT id FROM $table_name WHERE source_url = %s", 
            esc_url_raw($source) 
        ) );

        if ( $existing ) {
            // UPDATE existing record (preserves created_at)
            return $wpdb->update(
                $table_name,
                array(
                    'target_url'    => esc_url_raw($target),
                    'redirect_type' => intval($type),
                    'status'        => sanitize_text_field($status),
                    'is_forced'     => intval($is_forced)
                ),
                array( 'source_url' => esc_url_raw($source) ),
                array('%s', '%d', '%s', '%d'),
                array('%s')
            );
        } else {
            // INSERT new record with current timestamp
            return $wpdb->insert(
                $table_name,
                array(
                    'source_url'    => esc_url_raw($source),
                    'target_url'    => esc_url_raw($target),
                    'redirect_type' => intval($type),
                    'status'        => sanitize_text_field($status),
                    'is_forced'     => intval($is_forced),
                    'created_at'    => current_time('mysql')
                ),
                array('%s', '%s', '%d', '%s', '%d', '%s')
            );
        }
    }
}
