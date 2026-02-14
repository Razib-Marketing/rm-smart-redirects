<?php
/**
 * Redirect List Table for RMSmart Redirects
 * Handles Search, Pagination, and the Rendering of data rows.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class RMSmart_Redirect_List_Table extends WP_List_Table {

    /**
     * 1. Define the columns for the table
     */
    function get_columns() {
        return array(
            'cb'            => '<input type="checkbox" />',
            'source_url'    => __( 'Source URL', 'rm-smart-redirects' ),
            'target_url'    => __( 'Destination', 'rm-smart-redirects' ),
            'redirect_type' => __( 'Type', 'rm-smart-redirects' ),
            'status'        => __( 'Status', 'rm-smart-redirects' ),
            'hits'          => __( 'Hits', 'rm-smart-redirects' ),
            'created_at'    => __( 'Date', 'rm-smart-redirects' )
        );
    }

    /**
     * 2. Define which columns are sortable
     */
    protected function get_sortable_columns() {
        return array(
            'hits'       => array( 'hits', false ),
            'created_at' => array( 'created_at', false )
        );
    }

    /**
     * 3. Define Bulk Actions
     */
    function get_bulk_actions() {
        return array(
            'bulk-delete' => __( 'Delete Permanently', 'rm-smart-redirects' )
        );
    }

    /**
     * 4. RENDERER: Checkbox Column
     */
    function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-delete[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * 5. RENDERER: Source URL Column (with Row Actions)
     */
    function column_source_url( $item ) {
        $actions = array(
            'edit' => sprintf( 
                '<a href="?page=%s&tab=manager&action=edit&id=%s">Edit</a>', 
                esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ), esc_attr( $item['id'] ) 
            ),
            'delete' => sprintf( 
                '<a href="?page=%s&action=%s&id=%s" class="rmsmart-delete-redirect" data-id="%s">Delete</a>', 
                esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ), 'delete', esc_attr( $item['id'] ), esc_attr( $item['id'] )
            ),
        );

        return sprintf( 
            '<strong><code>%1$s</code></strong> %2$s', 
            esc_html( $item['source_url'] ), 
            $this->row_actions( $actions ) 
        );
    }

    /**
     * 6. RENDERER: Other Columns
     */
    function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'target_url':
                // External URL Support: Add icon for external URLs
                $is_external = preg_match('/^https?:\/\//', $item['target_url']);
                $icon = $is_external ? ' <span style="color:#2271b1; font-size:12px;" title="External URL">🔗</span>' : '';
                return '<code>' . esc_html( $item['target_url'] ) . '</code>' . $icon;
            case 'status':
                $status = $item['status'];
                $label  = ucfirst( $status );
                return sprintf( 
                    '<span class="rmsmart-badge %s">%s</span>', 
                    esc_attr( $status ), 
                    esc_html( $label ) 
                );
            case 'redirect_type':
                $forced_icon = ( isset($item['is_forced']) && $item['is_forced'] == 1 ) 
                    ? ' <span title="Forced Redirect (Overrides Content)" style="cursor:help;">⚡</span>' 
                    : '';
                return sprintf( '<span class="rmsmart-badge redirect-%s">%s</span>%s', esc_attr($item['redirect_type']), esc_html($item['redirect_type']), $forced_icon );
            case 'hits':
                return esc_html( $item['hits'] );
            case 'created_at':
                return esc_html( $item['created_at'] );
            default:
                return esc_html( print_r( $item, true ) ); // Fallback (escaped) or better: return '';
        }
    }

    /**
     * 7. Logic Engine: Search, Sort, and Paginate
     */
    function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';

        // Set Pagination
        $per_page = 20; 
        $current_page = $this->get_pagenum();

        // Build Search Query
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $search_where = $wpdb->prepare( " WHERE status = %s ", 'active' ); // Manager tab only shows active
        
        if ( ! empty( $search ) ) {
            $search_where .= $wpdb->prepare( 
                " AND (source_url LIKE %s OR target_url LIKE %s)", 
                '%' . $wpdb->esc_like( $search ) . '%', 
                '%' . $wpdb->esc_like( $search ) . '%' 
            );
        }

        // Sorting
        $orderby = ( ! empty( $_GET['orderby'] ) ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'created_at';
        $order   = ( ! empty( $_GET['order'] ) ) ? sanitize_text_field( $_GET['order'] ) : 'DESC';

        // Total Count
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe, $search_where built via prepare()
        $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name $search_where" );

        // Fetch Data
        $offset = ( $current_page - 1 ) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe, $search_where built via prepare()
        $this->items = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT * FROM $table_name $search_where ORDER BY $orderby $order LIMIT %d OFFSET %d", 
                $per_page, 
                $offset 
            ), 
            ARRAY_A 
        );

        // Finalize Pagination
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ) );

        // CRITICAL FIX: Tell WP_List_Table what columns to use!
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array( $columns, $hidden, $sortable );
    }
}

class RMSmart_404_List_Table extends WP_List_Table {

    /**
     * 1. Define the columns for the table
     */
    function get_columns() {
        return array(
            'cb'        => '<input type="checkbox" />',
            'url'       => __( 'URL', 'rm-smart-redirects' ),
            'hits'      => __( 'Hits', 'rm-smart-redirects' ),
            'last_seen' => __( 'Last Seen', 'rm-smart-redirects' ),
            'actions'   => __( 'Actions', 'rm-smart-redirects' )
        );
    }

    /**
     * 2. Define which columns are sortable
     */
    protected function get_sortable_columns() {
        return array(
            'url'       => array( 'url', false ),
            'hits'      => array( 'hits', false ),
            'last_seen' => array( 'last_seen', false )
        );
    }

    /**
     * 3. Define Bulk Actions
     */
    function get_bulk_actions() {
        return array(
            'bulk-delete-404' => __( 'Delete Permanently', 'rm-smart-redirects' )
        );
    }

    /**
     * 4. RENDERER: Checkbox Column
     */
    function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-delete-404[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * 5. RENDERER: URL Column
     */
    function column_url( $item ) {
        $actions = array(
            'delete' => sprintf( 
                '<a href="#" class="rmsmart-delete-404" data-id="%s">Delete</a>', 
                $item['id']
            ),
        );

        return sprintf( 
            '<code style="color:#d63638">%1$s</code> %2$s', 
            esc_html( $item['url'] ), 
            $this->row_actions( $actions ) 
        );
    }

    /**
     * 6. RENDERER: Actions Column (Custom Fixed Badge logic)
     */
    function column_actions( $item ) {
        global $wpdb;
        $table_redirects = $wpdb->prefix . 'rmsmart_redirects';
        
        // Check if redirect exists
        $existing = $wpdb->get_var( $wpdb->prepare( 
            "SELECT id FROM $table_redirects WHERE source_url = %s", 
            $item['url'] 
        ) );

        if ( $existing ) {
            return '<span class="rmsmart-badge active">✓ Fixed</span>';
        } else {
            return sprintf( 
                '<a href="?page=%s&tab=manager&fix_404=%s" class="button button-small">Fix Redirect</a>', 
                esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ), 
                esc_attr( urlencode($item['url']) )
            );
        }
    }

    function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'hits':
            case 'last_seen':
                return esc_html( $item[$column_name] );
            default:
                return esc_html( print_r( $item, true ) ); // Fallback (escaped) or better: return '';
        }
    }

    /**
     * 7. Logic Engine: Search, Sort, and Paginate
     */
    function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_404_logs';

        // Set Pagination
        $per_page = 20; 
        $current_page = $this->get_pagenum();

        // Build Search Query
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
        $search_query = "";
        
        if ( ! empty( $search ) ) {
            $search_query = $wpdb->prepare( " WHERE url LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
        }

        // Sorting
        $orderby = ( ! empty( $_GET['orderby'] ) ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'last_seen';
        $order   = ( ! empty( $_GET['order'] ) ) ? sanitize_text_field( $_GET['order'] ) : 'DESC';

        // Total Count
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe, $search_query built via prepare()
        $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name $search_query" );

        // Fetch Data
        $offset = ( $current_page - 1 ) * $per_page;
        $this->items = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT * FROM $table_name $search_query ORDER BY $orderby $order LIMIT %d OFFSET %d", 
                $per_page, 
                $offset 
            ), 
            ARRAY_A 
        );

        // Finalize Pagination
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ) );

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array( $columns, $hidden, $sortable );
    }
}
