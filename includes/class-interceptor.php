<?php
/**
 * Redirect Interceptor for RMSmart Redirects
 * Catches 404s and performs hierarchical fallbacks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMSmart_Interceptor {

    public function __construct() {
        // We use template_redirect because it fires just before WordPress decides which page to show.
        add_action( 'template_redirect', array( $this, 'check_for_redirect' ) );
    }

/**
 * Professional Interceptor Logic
 * Fully updated to ignore newly created or scheduled pages.
 */
public function check_for_redirect() {
        if ( is_admin() || wp_doing_ajax() ) return;
        
        // Get current path safely
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $current_path = rtrim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' ) . '/';

    // === PRIORITY 0: CONDITIONAL REDIRECTS (PRO) ===
    // Allow PRO to check location, device, browser conditions FIRST
    $conditional_target = apply_filters( 'rmsmart_conditional_redirect_check', false, $current_path );
    if ( $conditional_target ) {
        // Use 302 (temporary) for conditional redirects - conditions can change
        // Skip saving to review queue - these are managed in the conditional redirects table
        $this->execute_redirect( $conditional_target, 302, $current_path, true );
        return;
    }

    // 2. LAYER 0 (FORCE REDIRECTS) - VIP LANE
    // Check if there is a "Forced" redirect strictly for this path.
    // This MUST run before is_404() to override existing pages.
    $forced_match = $this->find_match( $current_path, true );
    if ( $forced_match ) {
        $this->execute_redirect( $forced_match['target'], $forced_match['type'], $current_path );
        return;
    }

    // 3. Only run if WordPress signals a 404 (Normal Mode)
    if ( ! is_404() ) {
        return;
    }

    // 4. Clear internal cache to get the freshest data from the DB
    wp_cache_flush();

    // 4. IMPROVED GHOST CHECK:
    // Check if a post, page, or attachment exists with this slug, regardless of status.
    $post_id = url_to_postid( home_url( $current_path ) );
    
    // If we found a Post ID, check its status.
    // If it's PUBLISHED, we stop (let WP handle it).
    // If it's DRAFT/PENDING, we treat it as "missing" so our Pending Redirects can work.
    if ( $post_id > 0 ) {
        $status = get_post_status( $post_id );
        if ( $status === 'publish' ) {
            return;
        }
    }

    // Secondary check for Pages (url_to_postid can sometimes miss pages)
    // Same logic: Only stop if the found page is actually PUBLIC.
    $page_obj = get_page_by_path( $current_path, OBJECT, array('post', 'page', 'product') );
    if ( $page_obj && $page_obj->post_status === 'publish' ) {
        return;
    }

    // 5. LAYER 1 & 6. LAYER 2: Uses find_match() helper (Normal Mode)
    
    // === PRIORITY 2: REGEX REDIRECTS (PRO) ===
    // Allow PRO to check regex patterns before exact match
    $regex_target = apply_filters( 'rmsmart_regex_redirect_check', false, $current_path );
    if ( $regex_target ) {
        // Skip saving for PRO regex - managed in separate table
        $this->execute_redirect( $regex_target, 301, $current_path, true );
        return;
    }
    
    $match = $this->find_match( $current_path );
    
    if ( $match ) {
        $this->execute_redirect( $match['target'], $match['type'], $current_path );
        return;
    }

    // 7. LAYER 4: LOG THE 404 (If we reached here, no redirect happened)
    $this->log_404( $current_path );
}

    /**
     * Helper to FIND a redirect match without executing it.
     * Used by both the main interceptor and the Test Tool.
     * 
     * @param string $current_path Path to check
     * @param bool   $forced_only  If true, only look for matches with is_forced=1
     * @return array|false Returns match info {target, type, source_type} or false
     */
    public function find_match( $current_path, $forced_only = false ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        
        // Prepare Query
        if ( $forced_only ) {
            // LAYER 0: Precise Forced Match Only
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $query = $wpdb->prepare(
                "SELECT target_url, redirect_type FROM $table_name WHERE source_url = %s AND is_forced = 1",
                $current_path
            );
        } else {
            // LAYER 1: Normal Match (Explicitly exclude forced ones to avoid double matching, though not strictly necessary)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $query = $wpdb->prepare(
                "SELECT target_url, redirect_type FROM $table_name WHERE source_url = %s",
                $current_path
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name is safely constructed, query is already prepared
        $redirect = $wpdb->get_row( $query );

        if ( $redirect ) {
            return array(
                'target' => $redirect->target_url,
                'type' => $redirect->redirect_type,
                'source' => $forced_only ? 'Forced Redirect' : 'Database Match'
            );
        }

        // 6. LAYER 2: Hierarchical Fallback (only if enabled AND not looking for forced only)
        // We do NOT do fallback logic for forced redirects to avoid accidents.
        if ( ! $forced_only && get_option( 'rmsmart_enable_fallback', '1' ) === '1' ) {
            $path_segments = explode( '/', trim( $current_path, '/' ) );

            while ( count( $path_segments ) > 0 ) {
                array_pop( $path_segments );
                $parent_path = '/' . implode( '/', $path_segments );
                if ( $parent_path !== '/' ) { $parent_path .= '/'; }

                // FIX: Don't fallback to Root/Home. If we hit root, it's a true 404.
                if ( $parent_path === '/' ) break;

                if ( $this->url_exists( $parent_path ) ) {
                    return array(
                        'target' => $parent_path,
                        'type' => get_option( 'rmsmart_default_type', '302' ),
                        'source' => 'Smart Fallback'
                    );
                }
            }
        }
        
        return false;
    }

    /**
     * Logs the 404 error to the database.
     */
    private function log_404( $path ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_404_logs';
        
        // Check if exists today? No, just upsert.
        // We use ON DUPLICATE KEY UPDATE to increment hits and update last_seen
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $sql = "INSERT INTO $table_name (url, hits, last_seen) 
                VALUES (%s, 1, NOW()) 
                ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()";
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name is safely constructed, SQL is manually prepared above
        $wpdb->query( $wpdb->prepare( $sql, $path ) );
    }

    /**
     * Checks if a specific path exists in the WordPress database.
     */
    private function url_exists( $path ) {
        // get_page_by_path is a built-in WP function to check if a slug belongs to a page/post
        $page = get_page_by_path( $path, OBJECT, array('page', 'post', 'product') );
        
        if ( $page ) {
            return true;
        }

        // Also check if it's a valid category or term
        $path_parts = explode( '/', trim( $path, '/' ) );
        $last_segment = end( $path_parts );
        if ( term_exists( $last_segment ) ) {
            return true;
        }

        return false;
    }

    /**
     * Performs the actual redirect and updates the hit counter.
     * @param string $target Target URL to redirect to
     * @param int $type Redirect type (301, 302, etc.)
     * @param string $source Source URL path
     * @param bool $skip_save If true, skip saving to redirects table (for conditional/PRO redirects)
     */
    private function execute_redirect( $target, $type, $source, $skip_save = false ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rmsmart_redirects';

    // Skip database operations for conditional redirects (PRO feature)
    // These are managed in their own table and shouldn't pollute the review queue
    if ( ! $skip_save ) {
        // Before we redirect, check if this is a "Layer 3" guess (not in DB yet)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE source_url = %s", $source ) );

        if ( ! $exists ) {
            // If it's a new "guess", we save it as PENDING so it shows in the Review Queue
            RMSmart_Database::save_redirect( $source, $target, $type, 'pending' );
        } else {
            // If it's already there, just update the hit count
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $wpdb->query( $wpdb->prepare( "UPDATE $table_name SET hits = hits + 1 WHERE source_url = %s", $source ) );
        }
    }

    // Now do the redirect
    // External URL Support: Check if target is external
    $is_external = preg_match( '/^https?:\/\//', $target );
    
    if ( $is_external ) {
        // Append query string if present
        if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
            $separator = ( strpos( $target, '?' ) !== false ) ? '&' : '?';
            $target .= $separator . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
        }
        
        // WP.org Security: Ensure the final URL is safe
        // esc_url_raw() is appropriate for database storage and redirects
        wp_redirect( esc_url_raw( $target ), $type );
        exit;
    } else {
        // Internal redirect - prepend home URL (EXISTING LOGIC)
        if ( ! empty( $query_string ) ) {
            $separator = ( strpos( $target, '?' ) !== false ) ? '&' : '?';
            $target .= $separator . $query_string;
        }
        
        wp_redirect( home_url( $target ), $type );
        exit;
    }
    }
}
