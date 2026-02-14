<?php
/**
 * Slug Watcher for RMSmart Redirects
 * Monitors URL changes, deletions, and restorations.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMSmart_Watcher {

    /**
     * Constructor: Hook into WordPress lifecycle events.
     */
    public function __construct() {
        // Listen for slug updates
        add_action( 'post_updated', array( $this, 'detect_slug_change' ), 10, 3 );

        // Listen for trashing
        add_action( 'wp_trash_post', array( $this, 'handle_trash' ) );

        // ULTIMATE FIX: Listen for restoration AFTER it happens (to get clean permalinks)
        add_action( 'untrashed_post', array( $this, 'handle_restore' ), 20 );
    }

    /**
     * Handles slug changes.
     */
    public function detect_slug_change( $post_id, $post_after, $post_before ) {
        if ( empty( $post_before->post_name ) || $post_before->post_status === 'new' ) {
            return;
        }

        // SCENARIO 1: Slug Change (Both Published)
        if ( $post_before->post_status === 'publish' && $post_after->post_status === 'publish' ) {
            if ( $post_before->post_name !== $post_after->post_name ) {
                // STEP 1: Calculate OLD URL (the source of the redirect)
                $old_link = wp_parse_url( get_permalink( $post_before ), PHP_URL_PATH );
                
                // STEP 2: Calculate NEW URL (the target of the redirect)
                $new_link = wp_parse_url( get_permalink( $post_after ), PHP_URL_PATH );
    
                // VALIDATION: Ensure both URLs are valid before proceeding
                if ( empty( $old_link ) || $old_link === '/' || empty( $new_link ) || $new_link === '/' ) {
                    return; // Abort if either URL is invalid
                }

                if ( $old_link !== $new_link ) {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'rmsmart_redirects';

                    // SURGICAL FIX: Force the target URL's last segment to match the actual post_name
                    // This prevents WordPress's conflict resolution (-2-2) from polluting our redirects
                    $intended_slug = trim( $post_after->post_name, '/' );
                    $generated_slug = urldecode( basename( rtrim( $new_link, '/' ) ) );

                    if ( $intended_slug !== $generated_slug ) {
                        // Replace only the last segment with the correct slug
                        $new_link = str_replace( $generated_slug, $intended_slug, $new_link );
                    }

                    // CHAIN PREVENTION: Check if the OLD URL ($old_link) is the TARGET of any existing redirect
                    // If YES: Update those redirects to point to the NEW URL, don't create a new one
                    // If NO: Create the new redirect normally
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
                    $upstream_count = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_name WHERE target_url = %s AND status = %s AND is_forced = %d",
                        $old_link,
                        'active',
                        0
                    ) );

                    if ( $upstream_count > 0 ) {
                        // There are existing redirects pointing to $old_link
                        // Update them to point to $new_link instead (A→B becomes A→C)
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
                        $wpdb->query( $wpdb->prepare(
                            "UPDATE $table_name SET target_url = %s WHERE target_url = %s AND status = %s AND is_forced = %d",
                            $new_link,
                            $old_link,
                            'active',
                            0
                        ) );
                        // Don't create B→C (we just updated A→C)
                    } else {
                        // No existing redirects point to $old_link
                        // This is a fresh rename, create the redirect normally
                        RMSmart_Database::save_redirect( $old_link, $new_link, 301, 'active' );
                    }

                    set_transient( 'rmsmart_slug_changed_notice', true, 30 );
                }
            }
        }

        // SCENARIO 2: Unpublish Monitor (Publish -> Draft/Pending)
        // If a live page is taken offline, we create a Pending Redirect to catch traffic.
        if ( $post_before->post_status === 'publish' && in_array( $post_after->post_status, array('draft', 'pending', 'private') ) ) {
            $old_link = wp_parse_url( get_permalink( $post_before ), PHP_URL_PATH );
            
            // Fix: Ignored WordPress internal trash renaming (e.g. 'slug__trashed')
            if ( strpos( $post_after->post_name, '__trashed' ) !== false ) {
                return;
            }

            $target_link = home_url( '/' ); // Fallback to Home
            
            // Validate: Never redirect the Homepage itself (root)
            if ( ! $old_link || $old_link === '/' ) {
                return;
            }

            // Try to find a smarter fallback (Parent or Category)
            if ( ! empty( $post_before->post_parent ) ) {
                $target_link = get_permalink( $post_before->post_parent );
            } else {
                $categories = get_the_category( $post_id );
                if ( ! empty( $categories ) ) {
                    $target_link = get_category_link( $categories[0]->term_id );
                }
            }
            
            $target_path = wp_parse_url( $target_link, PHP_URL_PATH );

            // Validate: Prevent indefinite loops (Source == Target)
            if ( $old_link === $target_path ) {
                return;
            }

            RMSmart_Database::save_redirect( $old_link, $target_path, 302, 'pending' );
        }

        // SCENARIO 3: Republish Monitor (Draft/Pending -> Publish)
        // If a page comes back online, we delete any pending redirect for it.
        if ( in_array( $post_before->post_status, array('draft', 'pending', 'private') ) && $post_after->post_status === 'publish' ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'rmsmart_redirects';
            
            // Strategy 1: Try exact URL match first
            $permalink = get_permalink( $post_after );
            $path = wp_parse_url( $permalink, PHP_URL_PATH );
            
            // Strategy 2: Also match by slug (in case slug changed while unpublished)
            $slug = $post_after->post_name;
            $slug_pattern = '%/' . $wpdb->esc_like( $slug ) . '/%';
            
            // Delete pending redirects that match either the exact path OR end with this slug
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $wpdb->query( $wpdb->prepare( 
                "DELETE FROM $table_name WHERE status = %s AND (source_url = %s OR source_url LIKE %s)", 
                'pending',
                $path,
                $slug_pattern
            ) );
        }
    }

    /**
     * Handles trashing a post.
     */
    public function handle_trash( $post_id ) {
        // Force refresh post data to ensure we have the latest status (handled Restore race conditions)
        clean_post_cache( $post_id );
        
        $post = get_post( $post_id );
        
        // CRITICAL: Only create redirects for PUBLISHED pages
        // If trashing a Draft/Pending/Private page, do nothing
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        
        // Fix: Use raw permalink logic if standard one returns root (common for drafts)
        $permalink = get_permalink( $post_id );
        $path = wp_parse_url( $permalink, PHP_URL_PATH );
        
        // If path is root or empty, try to build it from available URI (Drafts often return /?p=123 which parses to /)
        if ( empty($path) || $path === '/' ) {
            // Fix: Use get_page_uri() to retrieve full hierarchy (parent/child) even for drafts
            $uri = get_page_uri( $post_id );
            if ( $uri ) {
                $path = '/' . $uri . '/';
            } else {
                $path = '/' . $post->post_name . '/';
            }
        }
        
        $old_link = rtrim( $path, '/' ) . '/';

        // Validate: Still Root? Ignore.
        if ( $old_link === '/' ) {
            return;
        }

        $target_link = home_url( '/' );
        if ( ! empty( $post->post_parent ) ) {
            $target_link = get_permalink( $post->post_parent );
        } else {
            $categories = get_the_category( $post_id );
            if ( ! empty( $categories ) ) {
                $target_link = get_category_link( $categories[0]->term_id );
            }
        }

        $target_path = wp_parse_url( $target_link, PHP_URL_PATH );
        $target_path = rtrim( $target_path, '/' ) . '/';

        // Fix: Before creating redirect, delete any OLD pending redirects for this slug
        // This prevents duplicates when trashing the same post multiple times
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        $slug = $post->post_name;
        
        // Delete any pending redirects that end with this slug (catches hierarchy changes)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_name WHERE status = %s AND source_url LIKE %s",
            'pending',
            '%/' . $wpdb->esc_like( $slug ) . '/'
        ) );

        RMSmart_Database::save_redirect( $old_link, $target_path, 302, 'pending' );
    }

    /**
     * ULTIMATE FIX: Cleans up redirects using a Slug-based Wildcard.
     * This is the most aggressive way to ensure the record is removed.
     */
    /**
     * Handles restoring a post from trash.
     * Uses multiple matching strategies to ensure the redirect is found and deleted.
     */
    public function handle_restore( $post_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';

        $post = get_post( $post_id );
        if ( ! $post ) return;

        // Fix: If restored post is NOT published (e.g. Draft), KEEP the redirect.
        // We only want to delete the redirect if the page is truly live again.
        if ( $post->post_status !== 'publish' ) {
            return;
        }

        // Strategy 1: Exact Path Match
        // We attempt to reconstruct the exact path that was saved during trash.
        $permalink = get_permalink( $post_id );
        $path = wp_parse_url( $permalink, PHP_URL_PATH );
        
        // Prepare variations (with and without trailing slash)
        $path_slash = rtrim( $path, '/' ) . '/';
        $path_no_slash = rtrim( $path, '/' );

        // Strategy 2: Slug Suffix Match
        // In case the parent hierarchy isn't available during the hook fire,
        // we look for URLs ending in the slug (e.g., .../child-slug/ or .../child-slug)
        $slug = $post->post_name;
        $slug_match_slash = '%/' . $wpdb->esc_like( $slug ) . '/';
        $slug_match_no_slash = '%/' . $wpdb->esc_like( $slug );

        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM $table_name WHERE 
            source_url = %s OR 
            source_url = %s OR 
            source_url LIKE %s OR 
            source_url LIKE %s", 
            $path_slash, 
            $path_no_slash,
            $slug_match_slash,
            $slug_match_no_slash
        ) );
    }
}
