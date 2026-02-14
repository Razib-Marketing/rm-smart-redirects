<?php
/**
 * Health Checker for RMSmart Redirects
 * Detects SEO issues like redirect chains and loops.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMSmart_Health {

    /**
     * Detect redirect chains (A→B→C patterns)
     * Returns array of chains found
     */
    public static function detect_chains() {
        global $wpdb;
        $table = $wpdb->prefix . 'rmsmart_redirects';
        
        // Find all redirects where target_url is ALSO a source_url (chain pattern)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $chains = $wpdb->get_results( $wpdb->prepare( "
            SELECT 
                r1.id as chain_id,
                r1.source_url as step1_source,
                r1.target_url as step1_target,
                r2.target_url as step2_target,
                r1.hits as hits
            FROM $table r1
            INNER JOIN $table r2 ON r1.target_url = r2.source_url
            WHERE r1.status = %s AND r2.status = %s
            ORDER BY r1.hits DESC
        ", 'active', 'active' ) );
        
        return $chains;
    }

    /**
     * Detect infinite loops (A→B→A patterns)
     * Returns array of loops found
     */
    public static function detect_loops() {
        global $wpdb;
        $table = $wpdb->prefix . 'rmsmart_redirects';
        
        // Find redirects where A→B and B→A exist
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $loops = $wpdb->get_results( $wpdb->prepare( "
            SELECT 
                r1.id as redirect1_id,
                r1.source_url as url_a,
                r1.target_url as url_b,
                r2.id as redirect2_id
            FROM $table r1
            INNER JOIN $table r2 
                ON r1.source_url = r2.target_url 
                AND r1.target_url = r2.source_url
            WHERE r1.status = %s AND r2.status = %s
            AND r1.id < r2.id
        ", 'active', 'active' ) );
        
        return $loops;
    }

    /**
     * Get comprehensive health report
     * Returns array with all issues found
     */
    public static function get_health_report() {
        return array(
            'chains' => self::detect_chains(),
            'loops' => self::detect_loops(),
            'has_issues' => ( count( self::detect_chains() ) > 0 || count( self::detect_loops() ) > 0 )
        );
    }

    /**
     * Get user-friendly summary message
     */
    public static function get_summary_message() {
        $report = self::get_health_report();
        $chain_count = count( $report['chains'] );
        $loop_count = count( $report['loops'] );
        
        if ( ! $report['has_issues'] ) {
            return '✅ No SEO issues detected. All redirects are healthy!';
        }
        
        $messages = array();
        
        if ( $chain_count > 0 ) {
            $messages[] = sprintf( '⚠️ Found %d redirect chain%s', $chain_count, $chain_count > 1 ? 's' : '' );
        }
        
        if ( $loop_count > 0 ) {
            $messages[] = sprintf( '🔴 Found %d redirect loop%s', $loop_count, $loop_count > 1 ? 's' : '' );
        }
        
        return implode( ' | ', $messages );
    }
}
