<?php
/**
 * Admin Menu for RMSmart Redirects
 * Handles the dashboard UI, Sidebar Badges, Search, and Bulk Actions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMSmart_Menu {

    /**
     * Constructor: Hook into WordPress admin actions.
     */
    public function __construct() {
        // Register the sidebar menu with a notification badge
        add_action( 'admin_menu', array( $this, 'register_menu' ) );

        // Register the success notices (Yoast-style alerts)
        add_action( 'admin_notices', array( $this, 'show_slug_change_notice' ) );
        
        // Show general settings messages (e.g., "Redirect deleted")
        add_action( 'admin_notices', 'settings_errors' );
        
        // Enqueue Design Assets (CSS/JS)
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Handle Tools (Export/Import) - Must run on init before headers
        add_action( 'admin_init', array( $this, 'handle_tools_actions' ) );
    }

    /**
     * Load the modern UI styles
     */
    public function enqueue_admin_assets() {
        if ( isset( $_GET['page'] ) && sanitize_key( $_GET['page'] ) === 'rmsmart-redirects' ) {
            wp_enqueue_style( 'rmsmart-admin-css', plugin_dir_url( __FILE__ ) . 'assets/style.css', array(), '1.2.0' );
            wp_enqueue_script( 'rmsmart-admin-js', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array('jquery'), '1.2.0', true );
            
            wp_localize_script( 'rmsmart-admin-js', 'rmsmartAjax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'rmsmart_ajax_nonce' )
            ) );
        }
    }

    /**
     * Create the menu item in the WordPress Sidebar.
     * Includes a dynamic notification badge for pending redirects.
     */
    public function register_menu() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        
        // Count how many redirects are waiting for approval ('pending')
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $pending_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE status = %s", 'pending' ) );
        $badge = '';
        
        if ( $pending_count > 0 ) {
            $badge = ' <span class="update-plugins count-' . $pending_count . '"><span class="plugin-count">' . $pending_count . '</span></span>';
        }

        add_menu_page(
            'RMSmart Redirects',
            'Smart Redirects' . $badge, 
            'manage_options',
            'rmsmart-redirects',
            array( $this, 'render_dashboard' ),
            'dashicons-randomize',
            30
        );
    }

    /**
     * Listens for Delete, Accept, or Bulk Actions from the table.
     */
    public function handle_table_actions() {
        if ( ! isset( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'rmsmart-redirects' ) {
            return;
        }

        // Security: User must have admin capability
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';

        // 1. Handle Bulk Actions (requires nonce from table form)
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
        if ( $action === '-1' && isset( $_REQUEST['action2'] ) ) {
            $action = sanitize_key( $_REQUEST['action2'] );
        }

        if ( $action === 'bulk-delete' && isset( $_REQUEST['bulk-delete'] ) ) {
            // Verify nonce for bulk actions
            if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-' . 'redirects' ) ) {
                wp_die( 'Security check failed.' );
            }
            
            $ids = array_map( 'intval', $_REQUEST['bulk-delete'] );
            if ( ! empty( $ids ) ) {
                $id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe, placeholders are generated
                $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE id IN ($id_placeholders)", $ids ) );
                add_settings_error( 'rmsmart_messages', 'rmsmart_message', count( $ids ) . ' redirects deleted.', 'updated' );
            }
        }

        // 2. Handle "Clear All" for the Review Queue
        if ( isset( $_GET['action'] ) && sanitize_key( $_GET['action'] ) === 'clear_all' ) {
            // Verify nonce for clear_all action
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rmsmart_clear_all' ) ) {
                wp_die( 'Security check failed.' );
            }
            
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE status = %s", 'pending' ) );
            add_settings_error( 'rmsmart_messages', 'rmsmart_message', 'Review Queue cleared.', 'updated' );
        }

        // 3. Handle single actions (Delete/Accept via row links)
        if ( isset( $_GET['action'] ) && isset( $_GET['id'] ) ) {
            $id = intval( $_GET['id'] );
            $action = sanitize_key( $_GET['action'] );

            // Verify nonce for single actions
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rmsmart_action_' . $action . '_' . $id ) ) {
                // Skip if no valid nonce - this allows the page to load normally
                return;
            }

            if ( $action === 'delete' ) {
                $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
                add_settings_error( 'rmsmart_messages', 'rmsmart_message', 'Redirect removed.', 'updated' );
            } elseif ( $action === 'accept' ) {
                $wpdb->update( $table_name, array( 'status' => 'active', 'redirect_type' => 301 ), array( 'id' => $id ) );
                add_settings_error( 'rmsmart_messages', 'rmsmart_message', 'Redirect approved.', 'updated' );
            }
        }
    }

    /**
     * Handles POST requests for Adding or Editing Redirects
     */
    public function handle_crud_actions() {
        if ( isset( $_POST['rmsmart_submit_redirect'] ) && check_admin_referer( 'rmsmart_edit_nonce' ) ) {
            // Security: User must have admin capability
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'rmsmart_redirects';

            $source = sanitize_text_field( $_POST['source_url'] );
            $target = sanitize_text_field( $_POST['target_url'] );
            $type   = intval( $_POST['redirect_type'] );
            $id     = isset( $_POST['redirect_id'] ) ? intval( $_POST['redirect_id'] ) : 0;

            // Simple validation
            if ( empty( $source ) || empty( $target ) ) {
                add_settings_error( 'rmsmart_messages', 'rmsmart_error', 'Source and Target URLs are required.', 'error' );
                return;
            }

            // Clean path logic
            $source_path = wp_parse_url( $source, PHP_URL_PATH );
            $target_path = wp_parse_url( $target, PHP_URL_PATH );

            if ( $id > 0 ) {
                // UPDATE: Whether it was 'active' or 'pending', editing it confirms it.
                // So we force status='active' to "Accept" it essentially.
                $wpdb->update(
                    $table_name,
                    array( 
                        'source_url'    => $source_path,
                        'target_url'    => $target_path,
                        'redirect_type' => $type,
                        'status'        => 'active' // Auto-approve on edit
                    ),
                    array( 'id' => $id )
                );
                add_settings_error( 'rmsmart_messages', 'rmsmart_message', 'Redirect updated and activated.', 'updated' );
            } else {
                // INSERT
                RMSmart_Database::save_redirect( $source_path, $target_path, $type, 'active' );
                add_settings_error( 'rmsmart_messages', 'rmsmart_message', 'New redirect added.', 'updated' );
            }
        }
    }

    /**
     * Handles Export/Import Logic (Must run before headers sent)
     */
    public function handle_tools_actions() {
        // EXPORT
        if ( isset( $_POST['rmsmart_export_csv'] ) && check_admin_referer( 'rmsmart_tools_nonce' ) ) {
            // Security: User must have admin capability
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'rmsmart_redirects';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $rows = $wpdb->get_results( "SELECT source_url, target_url, redirect_type, status FROM $table_name", ARRAY_A );

            $filename = 'rmsmart-export-' . gmdate('Y-m-d') . '.csv';
            
            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for CSV export to php://output
            $output = fopen( 'php://output', 'w' );
            fputcsv( $output, array( 'source_url', 'target_url', 'redirect_type', 'status' ) );
            
            foreach ( $rows as $row ) {
                fputcsv( $output, $row );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $output );
            exit;
        }

        // IMPORT
        if ( isset( $_POST['rmsmart_import_csv'] ) && check_admin_referer( 'rmsmart_tools_nonce' ) ) {
            if ( ! empty( $_FILES['import_file']['tmp_name'] ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for reading uploaded CSV
                $file = fopen( $_FILES['import_file']['tmp_name'], 'r' );
                $count = 0;
                
                // Skip header
                fgetcsv( $file );

                while ( ($data = fgetcsv($file, 1000, ",")) !== FALSE ) {
                    if ( count($data) >= 2 ) {
                        $source = sanitize_text_field( $data[0] );
                        $target = sanitize_text_field( $data[1] );
                        $type   = isset($data[2]) ? intval($data[2]) : 301;
                        $status = isset($data[3]) ? sanitize_text_field($data[3]) : 'active';
                        
                        RMSmart_Database::save_redirect( $source, $target, $type, $status );
                        $count++;
                    }
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose( $file );
                add_settings_error( 'rmsmart_messages', 'rmsmart_message', "$count redirects imported.", 'updated' );
            } else {
                add_settings_error( 'rmsmart_messages', 'rmsmart_error', 'Please upload a valid CSV file.', 'error' );
            }
        }
    }

    /**
     * The Main Dashboard View
     */
    public function render_dashboard() {
        // Handle actions
        $this->handle_tools_actions();
        $this->handle_table_actions();
        $this->handle_crud_actions(); 

        // Load the Table Class
        if ( ! class_exists( 'RMSmart_Redirect_List_Table' ) ) {
            require_once RMSMART_PATH . 'admin/class-list-table.php';
        }

        // 1. Get Current Tab
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        
        // Database stats
        global $wpdb;
        $table_name = $wpdb->prefix . 'rmsmart_redirects';
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $active_count  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE status = %s", 'active' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $pending_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE status = %s", 'pending' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
        $total_hits    = $wpdb->get_var( "SELECT SUM(hits) FROM $table_name" );
        $total_hits    = $total_hits ? $total_hits : 0;

        // 404 Logs Dynamic Badge Logic
        $table_404 = $wpdb->prefix . 'rmsmart_404_logs';
        $last_viewed = get_option('rmsmart_404_last_viewed', '0000-00-00 00:00:00');
        
        if ( isset($_GET['tab']) && sanitize_key( $_GET['tab'] ) === '404s' ) {
            // User is viewing 404s: Mark all current items as seen
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
            $max_seen = $wpdb->get_var( "SELECT MAX(last_seen) FROM $table_404" );
            $max_seen = $max_seen ? $max_seen : '0000-00-00 00:00:00';
            
            update_option('rmsmart_404_last_viewed', $max_seen);
            $logs_count = 0;
        } else {
            // User is elsewhere: Show count of NEW items (newer than what we last recorded)
            $logs_count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(id) FROM $table_404 WHERE last_seen > %s",
                $last_viewed
            ) );
        }

        ?>
        <div class="rmsmart-wrap">
            
            <!-- BRAND HEADER -->
            <div class="rmsmart-header">
                <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/logo.png' ); ?>" class="rmsmart-logo" alt="RM Smart Redirects">
                <h1 class="rmsmart-title">RM Smart Redirects</h1>
            </div>

            <!-- SEO HEALTH CHECK (Chain Detection) -->
            <?php
            $health_report = RMSmart_Health::get_health_report();
            if ( $health_report['has_issues'] ):
                $chain_count = count( $health_report['chains'] );
                $loop_count = count( $health_report['loops'] );
            ?>
                <div class="notice notice-warning is-dismissible" style="margin: 20px 0; padding: 15px; border-left: 4px solid #ffb900;">
                    <p style="margin: 0 0 10px 0; font-weight: 600;">
                        ⚠️ SEO Health Alert
                    </p>
                    <?php if ( $chain_count > 0 ): ?>
                        <p style="margin: 5px 0;">
                            <strong>Redirect Chains Detected:</strong> Found <?php echo esc_html( $chain_count ); ?> redirect chain<?php echo esc_html( $chain_count > 1 ? 's' : '' ); ?>.
                            Chains waste HTTP requests and hurt SEO rankings.
                        </p>
                        <?php foreach ( array_slice( $health_report['chains'], 0, 3 ) as $chain ): ?>
                            <code style="display: block; margin: 3px 0; color: #d63638; font-size: 12px;">
                                <?php echo esc_html( $chain->step1_source ); ?> → <?php echo esc_html( $chain->step1_target ); ?> → <?php echo esc_html( $chain->step2_target ); ?>
                            </code>
                        <?php endforeach; ?>
                        <?php if ( $chain_count > 3 ): ?>
                            <em style="font-size: 12px; color: #666;">...and <?php echo esc_html( $chain_count - 3 ); ?> more</em>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ( $loop_count > 0 ): ?>
                        <p style="margin: 10px 0 5px 0;">
                            <strong style="color: #d63638;">🔴 Infinite Loops Detected:</strong> Found <?php echo esc_html( $loop_count ); ?> redirect loop<?php echo esc_html( $loop_count > 1 ? 's' : '' ); ?>.
                            These will cause redirect errors!
                        </p>
                        <?php foreach ( $health_report['loops'] as $loop ): ?>
                            <code style="display: block; margin: 3px 0; color: #d63638; font-size: 12px;">
                                <?php echo esc_html( $loop->url_a ); ?> ⇄ <?php echo esc_html( $loop->url_b ); ?>
                            </code>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <p style="margin: 10px 0 0 0; font-size: 12px;">
                        <strong>Fix:</strong> Edit or delete the intermediate redirects to create direct A→C redirects instead.
                    </p>
                </div>
            <?php endif; ?>

            <div class="rmsmart-tabs">
                <?php
                // Define base tabs
                $tabs = array(
                    'dashboard' => 'Dashboard',
                    'manager'   => 'Redirect Manager',
                    'logs'      => 'Review Queue',
                    '404s'      => '404 Logs',
                    'tools'     => 'Tools',
                    'settings'  => 'Settings'
                );
                
                // Allow PRO to add tabs
                $tabs = apply_filters( 'rmsmart_admin_tabs', $tabs );
                
                // Render tabs
                foreach ( $tabs as $tab_key => $tab_label ) {
                    $active_class = ( $active_tab == $tab_key ) ? 'active' : '';
                    $badge = '';
                    
                    // Add badges for specific tabs
                    if ( $tab_key === 'logs' && $pending_count > 0 ) {
                        $badge = " <span class='update-plugins' style='padding:0px 5px; border-radius:10px; font-size:10px; background:#d63638; color:#fff;'>$pending_count</span>";
                    } elseif ( $tab_key === '404s' && $logs_count > 0 ) {
                        $badge = " <span class='update-plugins' style='padding:0px 5px; border-radius:10px; font-size:10px; background:#d63638; color:#fff;'>$logs_count</span>";
                    }
                    
                    echo '<a href="?page=rmsmart-redirects&tab=' . esc_attr($tab_key) . '" class="rmsmart-tab ' . esc_attr( $active_class ) . '">' . wp_kses_post( $tab_label ) . wp_kses_post( $badge ) . '</a>';
                }
                ?>
            </div>

            <!-- NOTIFICATIONS -->
            <?php settings_errors( 'rmsmart_messages' ); ?>

            <div class="rmsmart-content">
                <?php
                if ( $active_tab == 'dashboard' ) {
                    // --- TAB 1: DASHBOARD (Home) ---
                    
                    // Queries for Widgets
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
                    $top_redirects = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE hits > %d ORDER BY hits DESC LIMIT %d", 0, 5 ) );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
                    $recent_404s   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_404 ORDER BY last_seen DESC LIMIT %d", 5 ) );
                    ?>
                    
                    <!-- STATS CARDS (Moved here) -->
                    <div class="rmsmart-stats-grid">
                        <div class="rmsmart-stat-card green">
                            <span class="dashicons dashicons-update rmsmart-stat-icon" style="color:#accf02;"></span>
                            <div class="rmsmart-stat-info">
                                <h4>Active Redirects</h4>
                                <div class="count"><?php echo esc_html( number_format_i18n( $active_count ) ); ?></div>
                            </div>
                        </div>
                        <div class="rmsmart-stat-card orange">
                            <span class="dashicons dashicons-clock rmsmart-stat-icon" style="color:#fd7e14;"></span>
                            <div class="rmsmart-stat-info">
                                <h4>Pending Review</h4>
                                <div class="count"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></div>
                            </div>
                        </div>
                        <div class="rmsmart-stat-card blue">
                            <span class="dashicons dashicons-chart-bar rmsmart-stat-icon" style="color:#004e9a;"></span>
                            <div class="rmsmart-stat-info">
                                <h4>Total Hits</h4>
                                <div class="count"><?php echo esc_html( number_format_i18n( $total_hits ) ); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- DASHBOARD WIDGETS -->
                    <div class="rmsmart-dashboard-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top:20px;">
                        
                        <!-- Top 5 Redirects -->
                        <div class="rmsmart-card">
                            <h3>🏆 Top 5 Performing Redirects</h3>
                            <?php if($top_redirects): ?>
                                <table class="wp-list-table widefat fixed striped" style="border:none;">
                                    <thead><tr><th>Source</th><th>Target</th><th>Hits</th></tr></thead>
                                    <tbody>
                                    <?php foreach($top_redirects as $r): ?>
                                        <tr>
                                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr($r->source_url); ?>">
                                                <code><?php echo esc_html($r->source_url); ?></code>
                                            </td>
                                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr($r->target_url); ?>">
                                                <?php echo esc_html($r->target_url); ?>
                                            </td>
                                            <td style="font-weight:bold; color:#004e9a;"><?php echo esc_html( number_format_i18n($r->hits) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p>No hits recorded yet.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Recent 404s -->
                        <div class="rmsmart-card">
                            <h3>⚠️ Recent 404 Errors</h3>
                            <?php if($recent_404s): ?>
                                <table class="wp-list-table widefat fixed striped" style="border:none;">
                                    <thead><tr><th>URL</th><th>Times</th><th>Last Seen</th></tr></thead>
                                    <tbody>
                                    <?php foreach($recent_404s as $l): ?>
                                        <tr>
                                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr($l->url); ?>">
                                                <code style="color:#d63638;"><?php echo esc_html($l->url); ?></code>
                                            </td>
                                            <td><?php echo esc_html( number_format_i18n($l->hits) ); ?></td>
                                            <td style="color:#666; font-size:12px;">
                                                <?php echo esc_html( human_time_diff( strtotime($l->last_seen), current_time('timestamp') ) . ' ago' ); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div style="margin-top:10px; text-align:right;">
                                    <a href="?page=rmsmart-redirects&tab=404s" style="text-decoration:none; font-size:13px;">View All &rarr;</a>
                                </div>
                            <?php else: ?>
                                <p style="color:#46b450;">No 404 errors found! Good job.</p>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php
                
                } elseif ( $active_tab == 'manager' || ( isset( $_GET['action'] ) && sanitize_key( $_GET['action'] ) === 'edit' ) ) {
                    // ... (Manager Logic) ...
                    // CHECK FOR EDIT MODE
                    $edit_mode = false;
                    $edit_data = null;
                    if ( isset( $_GET['action'] ) && sanitize_key( $_GET['action'] ) === 'edit' && isset( $_GET['id'] ) ) {
                        $edit_id = intval( $_GET['id'] );
                        $edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $edit_id ) );
                        if ( $edit_data ) { 
                            $edit_mode = true; 
                        }
                    }

                    // CHECK FOR "FIX 404" MODE
                    $fix_source = '';
                    if ( isset( $_GET['fix_404'] ) ) {
                        $fix_source = esc_url_raw( $_GET['fix_404'] );
                    }

                    // ADD / EDIT FORM (WRAPPED IN CARD)
                    ?>
                    <div class="rmsmart-card">
                        <h3><?php echo $edit_mode ? 'Edit Redirect' : 'Add New Redirect'; ?></h3>
                        <form method="post" id="rmsmart-redirect-form">
                            <?php wp_nonce_field( 'rmsmart_edit_nonce' ); ?>
                            <input type="hidden" name="redirect_id" value="<?php echo $edit_mode ? esc_attr( $edit_data->id ) : '0'; ?>">
                            
                            <div class="rmsmart-form-row" style="display:flex; gap:15px; align-items:flex-end;">
                                <div style="flex:1;">
                                    <label style="display:block; margin-bottom:5px; font-weight:600;">Source URL</label>
                                    <input type="text" name="source_url" class="widefat" placeholder="/old-link/" value="<?php echo $edit_mode ? esc_attr( $edit_data->source_url ) : esc_attr($fix_source); ?>" required>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block; margin-bottom:5px; font-weight:600;">Target URL</label>
                                    <input type="text" name="target_url" class="widefat" placeholder="/new-link/ or https://example.com" value="<?php echo $edit_mode ? esc_attr( $edit_data->target_url ) : ''; ?>" required>
                                </div>
                                <div style="width:120px;">
                                    <label style="display:block; margin-bottom:5px; font-weight:600;">Type</label>
                                    <select name="redirect_type" class="widefat">
                                        <option value="301" <?php echo esc_attr( ($edit_mode && $edit_data->redirect_type == 301) ? 'selected' : '' ); ?>>301 Permanent</option>
                                        <option value="302" <?php echo esc_attr( ($edit_mode && $edit_data->redirect_type == 302) ? 'selected' : '' ); ?>>302 Temporary</option>
                                    </select>
                                </div>
                                
                                <!-- Force Redirect Wrapper (Hidden by Default) -->
                                <div id="rmsmart-force-wrapper" style="<?php echo esc_attr( ($edit_mode && $edit_data->is_forced) ? 'display:flex;' : 'display:none;' ); ?> align-items:center;">
                                    <label for="rmsmart-is-forced" style="font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; color:#d63638;" title="Override existing pages (Layer 0 Priority)">
                                        <input type="checkbox" name="is_forced" id="rmsmart-is-forced" value="1" <?php checked( $edit_mode ? $edit_data->is_forced : 0, 1 ); ?>>
                                        Force Redirect ⚡
                                    </label>
                                </div>

                                <div>
                                    <button type="submit" name="rmsmart_submit_redirect" class="button button-primary rmsmart-btn" style="margin-bottom:1px;">
                                        <?php echo esc_html( $edit_mode ? 'Update & Activate' : 'Add Redirect' ); ?>
                                    </button>
                                    <?php if($edit_mode): ?>
                                        <a href="?page=rmsmart-redirects&tab=manager" class="button">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php

                    // Only show the table if we are rightfully on the manager tab
                    if ( $active_tab == 'manager' ) {
                        // Initialize and prepare table data
                        $redirect_table = new RMSmart_Redirect_List_Table();
                        $redirect_table->prepare_items();
                        ?>
                        <div class="rmsmart-card">
                            <form method="post" action="?page=rmsmart-redirects&tab=manager">
                                <?php
                                $redirect_table->search_box( __( 'Search Redirects', 'rm-smart-redirects' ), 'search_id' );
                                $redirect_table->display(); 
                                ?>
                            </form>
                        </div>
                        <?php
                    }
                } elseif ( $active_tab == 'logs' ) {
                    // ... (Review Queue Logic) ...
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe
                    $pending_items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE status = %s ORDER BY created_at DESC", 'pending' ) );
                    ?>
                    <div class="rmsmart-card">
                        <h3>Review Queue: Action Required</h3>
                        <?php if ( $pending_items ): ?>
                            <div style='margin-bottom:15px;'>
                                <a href='<?php echo esc_url( wp_nonce_url( '?page=rmsmart-redirects&tab=logs&action=clear_all', 'rmsmart_clear_all' ) ); ?>' class='button' onclick='return confirm("Discard all pending redirects?")' style='color:#a00;'>Discard All Pending</a>
                            </div>
                            <table class="wp-list-table widefat fixed striped"><thead><tr><th>Old URL</th><th>Suggested Parent</th><th>Hits</th><th>Action</th></tr></thead><tbody>
                            <?php foreach ( $pending_items as $item ): ?>
                                <tr>
                                    <td><code><?php echo esc_html($item->source_url); ?></code></td>
                                    <td><code><?php echo esc_html($item->target_url); ?></code></td>
                                    <td><?php echo esc_html($item->hits); ?></td>
                                    <td>
                                        <a href='<?php echo esc_url( wp_nonce_url( '?page=rmsmart-redirects&tab=logs&action=accept&id=' . $item->id, 'rmsmart_action_accept_' . $item->id ) ); ?>' class='button button-primary rmsmart-btn rmsmart-accept-pending' data-id="<?php echo esc_attr( $item->id ); ?>">Accept</a>
                                        <a href='?page=rmsmart-redirects&tab=manager&action=edit&id=<?php echo esc_attr( $item->id ); ?>' class='button'>Edit & Accept</a>
                                        <a href='<?php echo esc_url( wp_nonce_url( '?page=rmsmart-redirects&tab=logs&action=delete&id=' . $item->id, 'rmsmart_action_delete_' . $item->id ) ); ?>' class='button rmsmart-discard-pending' style='color:#a00;' data-id="<?php echo esc_attr( $item->id ); ?>">Discard</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table>
                        <?php else: ?>
                            <div class="notice notice-info inline"><p>No 404s need reviewing!</p></div>
                        <?php endif; ?>
                    </div>
                    <?php
                } elseif ( $active_tab == '404s' ) {
                    // --- TAB 3: 404 LOGS ---
                    
                    // Allow PRO to inject content (e.g., Scanner UI)
                    do_action( 'rmsmart_before_404_table' );
                    
                    // Initialize List Table
                    $rmsmart_404_table = new RMSmart_404_List_Table();
                    $rmsmart_404_table->prepare_items();
                    ?>
                    <div class="rmsmart-card">
                        <h3>404 Error Log</h3>
                        <p>These are URLs that visitors tried to access but failed (and our Smart Fallback couldn't assist).</p>

                        <form method="get">
                            <input type="hidden" name="page" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ); ?>" />
                            <input type="hidden" name="tab" value="404s" />
                            <?php $rmsmart_404_table->search_box( 'Search 404s', 'search_id' ); ?>
                        </form>

                        <form method="post">
                            <?php $rmsmart_404_table->display(); ?>
                        </form>
                    </div>
                    <?php
                } elseif ( $active_tab == 'tools' ) {
                    // --- TAB 4: IMPORT / EXPORT TOOLS ---
                    ?>
                    <div class="rmsmart-card">
                        <h3>🧪 Redirect Testing Tool</h3>
                        <p>Simulate a visit to a URL to see if (and where) it redirects, without actually changing your browser page.</p>
                        
                        <div style="display:flex; gap:10px; margin-top:15px; align-items:flex-start;">
                            <input type="text" id="rmsmart-test-url" class="widefat" placeholder="Enter URL to test (e.g. /my-old-post/)" style="max-width:400px;">
                            <button type="button" id="rmsmart-test-btn" class="button button-primary rmsmart-btn">Test URL</button>
                        </div>
                        
                        <div id="rmsmart-test-result" style="margin-top:20px; display:none; padding:15px; border-radius:5px; border:1px solid #ddd;"></div>
                    </div>

                    <div class="rmsmart-card">
                        <h3>Import / Export Redirects</h3>
                        <div class="rmsmart-tools-grid">
                            
                            <!-- EXPORT -->
                            <div style="background:#f9f9f9; padding:20px; border:1px solid #ddd; border-radius:5px;">
                                <h4>Export to CSV</h4>
                                <p>Download a backup of all your redirects (Active & Pending).</p>
                                <form method="post">
                                    <?php wp_nonce_field( 'rmsmart_tools_nonce' ); ?>
                                    <button type="submit" name="rmsmart_export_csv" class="button button-primary rmsmart-btn">Download CSV</button>
                                </form>
                            </div>

                            <!-- IMPORT -->
                            <div style="background:#f9f9f9; padding:20px; border:1px solid #ddd; border-radius:5px;">
                                <h4>Import from CSV</h4>
                                <p>Upload a CSV file with columns: <code>source_url, target_url, redirect_type, status</code>.</p>
                                <form method="post" enctype="multipart/form-data">
                                    <?php wp_nonce_field( 'rmsmart_tools_nonce' ); ?>
                                    <input type="file" name="import_file" accept=".csv" required style="margin-bottom:10px;">
                                    <br>
                                    <button type="submit" name="rmsmart_import_csv" class="button button-primary rmsmart-btn">Import CSV</button>
                                </form>
                            </div>

                        </div>
                    </div>
                    <?php
                } elseif ( $active_tab == 'settings' ) {
                    // --- TAB 5: SETTINGS ---
                    if ( isset( $_POST['rmsmart_save_settings'] ) && check_admin_referer( 'rmsmart_settings_nonce' ) ) {
                        update_option( 'rmsmart_enable_fallback', isset( $_POST['enable_fallback'] ) ? '1' : '0' );
                        update_option( 'rmsmart_default_type', sanitize_text_field( $_POST['default_type'] ) );
                        echo '<div class="updated"><p>Settings saved!</p></div>';
                    }

                    $fallback_enabled = get_option( 'rmsmart_enable_fallback', '1' );
                    $default_type = get_option( 'rmsmart_default_type', '302' );
                    ?>
                    <div class="rmsmart-card">
                        <h3>General Settings</h3>
                        <form method="post">
                            <?php wp_nonce_field( 'rmsmart_settings_nonce' ); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Enable Hierarchical Fallback</th>
                                    <td>
                                        <input type="checkbox" name="enable_fallback" value="1" <?php checked( $fallback_enabled, '1' ); ?>>
                                        <span class="description">Turn on Layer 3 (Smart URL Peeling).</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Default Type</th>
                                    <td>
                                        <select name="default_type">
                                            <option value="301" <?php selected( $default_type, '301' ); ?>>301 (Permanent)</option>
                                            <option value="302" <?php selected( $default_type, '302' ); ?>>302 (Temporary)</option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                            <input type="submit" name="rmsmart_save_settings" class="button-primary rmsmart-btn" value="Save Settings">
                        </form>
                    </div>
                    <?php
                } else {
                    // --- CUSTOM TABS (PRO or other extensions) ---
                    // Allow PRO to render custom tab content
                    do_action( 'rmsmart_render_tab_' . $active_tab );
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function show_slug_change_notice() {
        if ( get_transient( 'rmsmart_slug_changed_notice' ) ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong>RMSmart Redirects:</strong> URL change detected. A 301 redirect has been auto-created! 
                    <a href="?page=rmsmart-redirects" class="button button-secondary" style="margin-left:10px;">Manage</a>
                </p>
            </div>
            <?php
            delete_transient( 'rmsmart_slug_changed_notice' );
        }
    }
}
