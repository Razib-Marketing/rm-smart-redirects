<?php
/**
 * Plugin Name: RM Smart Redirects
 * Description: An intelligent SEO-focused redirect manager with hierarchical fallback and auto-slug monitoring.
 * Version: 3.1.0
 * Author: Razib Marketing
 * Author URI: https://razibmarketing.com/
 * Text Domain:       rm-smart-redirects
 * Domain Path:       /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define constants for easy file paths
define( 'RMSMART_PATH', plugin_dir_path( __FILE__ ) );

/**
 * 1. THE DATABASE LOADER
 * We will create this file next. It handles the table creation.
 */
require_once RMSMART_PATH . 'includes/class-database.php';
require_once RMSMART_PATH . 'includes/class-health.php';

// Initialize Database on Activation
register_activation_hook( __FILE__, array( 'RMSmart_Database', 'create_table' ) );

// Auto-check for table updates (fixes 404 logs table missing issue)
add_action( 'plugins_loaded', array( 'RMSmart_Database', 'check_update' ) );

/**
 * 2. THE ADMIN MENU LOADER
 * This handles the UI inside WordPress.
 */
if ( is_admin() ) {
    require_once RMSMART_PATH . 'admin/class-menu.php';
    $rmsmart_menu = new RMSmart_Menu();
}

// Load the AJAX Handler (for modern, instant interactions)
if ( is_admin() ) {
    require_once RMSMART_PATH . 'admin/class-ajax.php';
    new RMSmart_Ajax();
}

/**
 * 3. THE REDIRECT ENGINE (The Engine Room)
 * This handles the actual 404 catching.
 */
require_once RMSMART_PATH . 'includes/class-interceptor.php';
$rmsmart_engine = new RMSmart_Interceptor();


// Load the Watcher Logic
require_once RMSMART_PATH . 'includes/class-watcher.php';
$rmsmart_watcher = new RMSmart_Watcher();
