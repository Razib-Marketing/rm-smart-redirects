<?php
/**
 * AJAX Handler for RMSmart Redirects
 * Provides AJAX endpoints for all CRUD operations
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMSmart_Ajax {

    /**
     * Register AJAX hooks
     */
    public function __construct() {
        // Add/Update Redirect
        add_action( 'wp_ajax_rmsmart_save_redirect', array( $this, 'save_redirect' ) );
        
        // Delete Single Redirect
        add_action( 'wp_ajax_rmsmart_delete_redirect', array( $this, 'delete_redirect' ) );
        
        // Bulk Delete
        add_action( 'wp_ajax_rmsmart_bulk_delete', array( $this, 'bulk_delete' ) );
        
        // Accept Pending
        add_action( 'wp_ajax_rmsmart_accept_pending', array( $this, 'accept_pending' ) );
        
        // Discard Pending
        add_action( 'wp_ajax_rmsmart_discard_pending', array( $this, 'discard_pending' ) );
        
        // Delete 404 Log
        add_action( 'wp_ajax_rmsmart_delete_404', array( $this, 'delete_404' ) );
        
        // Bulk Delete 404 Logs
        add_action( 'wp_ajax_rmsmart_bulk_delete_404', array( $this, 'bulk_delete_404' ) );
        
        // Get Stats (for real-time updates)
        add_action( 'wp_ajax_rmsmart_get_stats', array( $this, 'get_stats' ) );
        
        // Test Redirect (Simulation Mode)
        add_action( 'wp_ajax_rmsmart_test_redirect', array( $this, 'test_redirect' ) );

        // Smart Slug Check (Smart UI Warning)
        add_action( 'wp_ajax_rmsmart_check_slug', array( $this, 'check_slug' ) );
    }

    /**
     * Test Redirect (Simulation Mode)
     * Checks if a URL matches any rule without executing the redirect.
     */
    public function test_redirect() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( $_POST['url'] ) : '';
        
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a URL to test.' ) );
        }

        // Normalize path
        $path = rtrim( wp_parse_url( $url, PHP_URL_PATH ), '/' ) . '/';
        
        // Use Interceptor Logic
        require_once RMSMART_PATH . 'includes/class-interceptor.php';
        $interceptor = new RMSmart_Interceptor();
        
        // We need to bypass the constructor's add_action if we just want to us helper methods? 
        // Actually, instantiating it is fine, the hook won't fire during AJAX request.
        
        $match = $interceptor->find_match( $path );
        
        if ( $match ) {
            wp_send_json_success( array(
                'found' => true,
                'match' => $match
            ) );
        } else {
            wp_send_json_success( array(
                'found' => false
            ) );
        }
    }

    /**
     * Check if BOTH Source and Target Slugs Exist (Strict "Dual-Sided" Check)
     */
    public function check_slug() {
        // Security: Verify nonce and user permissions
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        
        $source_url = isset( $_POST['source_url'] ) ? sanitize_text_field( wp_unslash( $_POST['source_url'] ) ) : '';
        $target_url = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '';

        if ( empty( $source_url ) || empty( $target_url ) ) {
            wp_send_json_success( array( 'exists' => false ) ); // Fail fast if either missing
            return;
        }

        $home_url = home_url();

        // --- HELPER: Check if a URL is a published local page ---
        $is_published = function($url) use ($home_url) {
            // 1. Must be local (start with home_url or be relative)
            if ( strpos( $url, 'http' ) === 0 && strpos( $url, $home_url ) !== 0 ) {
                return false; // External URL -> Not our page
            }

            $path = rtrim( wp_parse_url( $url, PHP_URL_PATH ), '/' ) . '/';

            // Check A: Post ID
            $post_id = url_to_postid( $home_url . $path );
            if ( $post_id > 0 && get_post_status( $post_id ) === 'publish' ) {
                return true;
            }

            // Check B: Page Object
            $page = get_page_by_path( $path, OBJECT, array('post', 'page', 'product') );
            if ( $page && $page->post_status === 'publish' ) {
                return true;
            }

            return false;
        };

        // --- CORE LOGIC: BOTH MUST BE TRUE ---
        if ( $is_published($source_url) && $is_published($target_url) ) {
            wp_send_json_success( array( 'exists' => true ) );
        } else {
            wp_send_json_success( array( 'exists' => false ) );
        }
    }

    /**
     * Save (Add or Update) Redirect
     */
    public function save_redirect() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $source = sanitize_text_field( $_POST['source_url'] );
        $target = sanitize_text_field( $_POST['target_url'] );
        $type = intval( $_POST['redirect_type'] );

        if ( empty( $source ) || empty( $target ) ) {
            wp_send_json_error( array( 'message' => 'Source and Target URLs are required.' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';

        // External URL Support: Detect if target is external
        $is_external_target = preg_match('/^https?:\/\//', $target);
        
        // For source: Always use path (source must be internal)
        $source_path = wp_parse_url($source, PHP_URL_PATH);
        // CRITICAL FIX: Add trailing slash to match interceptor logic
        $source_path = rtrim($source_path, '/') . '/';
        
        // SMART HANDLING: Check if target URL belongs to THIS site
        $home_url = home_url();
        $is_internal_domain = (strpos($target, $home_url) === 0);

        if ($is_internal_domain) {
            // It's a full URL but points to our own site -> Convert to relative path
            $target_path = wp_parse_url($target, PHP_URL_PATH);
            $target_path = rtrim($target_path, '/') . '/';
        } elseif ($is_external_target) {
            // It's a true external URL (e.g. google.com)
            $target_path = esc_url_raw($target); 
        } else {
            // It's already a relative path (e.g. /about/)
            $target_path = wp_parse_url($target, PHP_URL_PATH); 
            $target_path = rtrim($target_path, '/') . '/';
        }

        // Check if Forced
        $is_forced = isset( $_POST['is_forced'] ) ? 1 : 0;

        if ( $id > 0 ) {
            // UPDATE
            $wpdb->update(
                $table_name,
                array( 
                    'source_url'    => $source_path,
                    'target_url'    => $target_path,
                    'redirect_type' => $type,
                    'status'        => 'active',
                    'is_forced'     => $is_forced
                ),
                array( 'id' => $id ),
                array('%s', '%s', '%d', '%s', '%d'),
                array('%d')
            );
            wp_send_json_success( array( 'message' => 'Redirect updated successfully!' ) );
        } else {
            // INSERT
            RMSmart_Database::save_redirect( $source_path, $target_path, $type, 'active', $is_forced );
            wp_send_json_success( array( 'message' => 'Redirect added successfully!' ) );
        }
    }

    /**
     * Delete Single Redirect
     */
    public function delete_redirect() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $id = intval( $_POST['id'] );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => 'Redirect deleted successfully!' ) );
    }

    /**
     * Bulk Delete Redirects
     */
    public function bulk_delete() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : array();
        
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No redirects selected.' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        $id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe, placeholders are generated
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE id IN ($id_placeholders)", $ids ) );

        wp_send_json_success( array( 'message' => count($ids) . ' redirects deleted successfully!' ) );
    }

    /**
     * Accept Pending Redirect
     */
    public function accept_pending() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $id = intval( $_POST['id'] );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        $wpdb->update( $table_name, array( 'status' => 'active', 'redirect_type' => 301 ), array( 'id' => $id ) );

        wp_send_json_success( array( 'message' => 'Redirect accepted!' ) );
    }

    /**
     * Discard Pending Redirect
     */
    public function discard_pending() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $id = intval( $_POST['id'] );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => 'Redirect discarded!' ) );
    }

    /**
     * Delete Single Link from 404 Log
     */
    public function delete_404() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $id = intval( $_POST['id'] );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_404_logs';
        $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => '404 Log deleted!' ) );
    }

    /**
     * Bulk Delete 404 Logs
     */
    public function bulk_delete_404() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : array();
        
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_404_logs';
        $id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe, placeholders are generated
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE id IN ($id_placeholders)", $ids ) );

        wp_send_json_success( array( 'message' => count($ids) . ' logs deleted successfully!' ) );
    }

    /**
     * Get Current Stats (for real-time updates after actions)
     */
    public function get_stats() {
        check_ajax_referer( 'rmsmart_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $active_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE status = %s", 'active' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $pending_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE status = %s", 'pending' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $total_hits = $wpdb->get_var( "SELECT SUM(hits) FROM $table_name" );

        wp_send_json_success( array(
            'active' => $active_count ? $active_count : 0,
            'pending' => $pending_count ? $pending_count : 0,
            'hits' => $total_hits ? $total_hits : 0
        ) );
    }
}
