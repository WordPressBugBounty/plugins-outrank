<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * Plugin Name: Outrank
 * Plugin URI: https://outrank.so
 * Description: Get traffic and outrank competitors with automatic SEO-optimized content generation published to your WordPress site.
 * Version: 1.0.5
 * Author: Outrank
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.4
 * Tested up to: 6.8
*/

define('OUTRANK_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OUTRANK_PLUGIN_URL', plugin_dir_url(__FILE__));
require_once plugin_dir_path(__FILE__) . 'includes/image-functions.php';

/**
 * Create the outrank_manage table for a specific site.
 * Used during activation and when new sites are created in multisite.
 */
function outrank_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'outrank_manage';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
    CREATE TABLE {$table_name} (
        id INT(11) NOT NULL AUTO_INCREMENT,
        image TEXT NOT NULL,
        slug VARCHAR(191) NOT NULL,
        title TEXT NOT NULL,
        meta_description TEXT NOT NULL,
        status VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug_unique (slug)
    ) ENGINE=InnoDB $charset_collate;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Check if the outrank_manage table exists for the current site.
 *
 * @return bool True if table exists, false otherwise.
 */
function outrank_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'outrank_manage';

    $cache_key = 'outrank_table_exists_' . get_current_blog_id();
    $exists = wp_cache_get($cache_key, 'outrank');

    if ($exists === false) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        wp_cache_set($cache_key, $exists ? 1 : 0, 'outrank', 3600);
    }

    return (bool) $exists;
}

/**
 * Ensure the table exists, create it if not.
 * Call this before any table operations.
 */
function outrank_ensure_table_exists() {
    if (!outrank_table_exists()) {
        outrank_create_table();
        // Clear the cache after creating the table
        wp_cache_delete('outrank_table_exists_' . get_current_blog_id(), 'outrank');
    }
}

// Add admin menu pages
add_action('admin_menu', 'outrank_add_outrank_menu');
function outrank_add_outrank_menu() {
    add_menu_page(
        'Outrank Menu',
        'Outrank',
        'manage_options',
        'outrank',
        'outrank_page',
        'data:image/svg+xml;base64,' . base64_encode(file_get_contents(OUTRANK_PLUGIN_PATH . 'images/icon.svg')),
        60
    );
    add_submenu_page('outrank', 'Home', 'Home', 'manage_options', 'outrank', 'outrank_page');
    add_submenu_page('outrank', 'Manage', 'Manage', 'manage_options', 'outrank_manage', 'outrank_manage_page');
}

// Redirect to manage page if no API key is set
add_action('admin_init', 'outrank_check_api_key_redirect');
function outrank_check_api_key_redirect() {
    // Only redirect if we're on the Outrank home page
    if (isset($_GET['page']) && $_GET['page'] === 'outrank') {
        $apiKey = get_option('outrank_api_key');
        if (empty($apiKey)) {
            wp_safe_redirect(admin_url('admin.php?page=outrank_manage'));
            exit;
        }
    }
}

// Handle activation redirect
add_action('admin_init', 'outrank_activation_redirect');
function outrank_activation_redirect() {
    // Only redirect if transient exists
    if (get_transient('outrank_activation_redirect')) {
        delete_transient('outrank_activation_redirect');

        // Don't redirect on multi-site activations or bulk plugin activations
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            return;
        }

        // Redirect to manage page
        wp_safe_redirect(admin_url('admin.php?page=outrank_manage'));
        exit;
    }
}

// Include admin pages
function outrank_page() {
    include_once OUTRANK_PLUGIN_PATH . 'pages/home.php';
}

function outrank_manage_page() {
    include_once OUTRANK_PLUGIN_PATH . 'pages/manage.php';
}

// Activation hook: Create custom table
register_activation_hook(__FILE__, 'outrank_activate');
function outrank_activate($network_wide = false) {
    if (is_multisite() && $network_wide) {
        // Network activation: create tables for all existing sites
        $sites = get_sites(['fields' => 'ids']);
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);
            outrank_create_table();
            restore_current_blog();
        }
    } else {
        // Single site activation
        outrank_create_table();
    }

    // Set transient for activation redirect
    set_transient('outrank_activation_redirect', true, 30);
}

/**
 * Create table when a new site is added in multisite.
 * Hook into wp_initialize_site for WordPress 5.1+
 *
 * @param WP_Site $new_site New site object.
 */
add_action('wp_initialize_site', 'outrank_on_new_site', 10, 1);
function outrank_on_new_site($new_site) {
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
        return;
    }

    switch_to_blog($new_site->blog_id);
    outrank_create_table();
    restore_current_blog();
}

/**
 * Backwards compatibility for WordPress < 5.1
 * Create table when a new site is added using the older hook.
 *
 * @param int $blog_id The new blog ID.
 */
add_action('wpmu_new_blog', 'outrank_on_new_blog_legacy', 10, 1);
function outrank_on_new_blog_legacy($blog_id) {
    // Only run on WordPress < 5.1 (wp_initialize_site handles 5.1+)
    if (version_compare(get_bloginfo('version'), '5.1', '>=')) {
        return;
    }

    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
        return;
    }

    switch_to_blog($blog_id);
    outrank_create_table();
    restore_current_blog();
}

/**
 * Clean up when a site is deleted in multisite (WordPress 5.1+).
 *
 * @param WP_Site $old_site The site being deleted.
 */
add_action('wp_uninitialize_site', 'outrank_on_delete_site', 10, 1);
function outrank_on_delete_site($old_site) {
    $blog_id = is_object($old_site) ? $old_site->blog_id : $old_site;
    outrank_cleanup_site($blog_id);
}

/**
 * Backwards compatibility for WordPress < 5.1.
 * Clean up when a site is deleted using the older hook.
 *
 * @param int $blog_id The site ID being deleted.
 */
add_action('delete_blog', 'outrank_on_delete_blog_legacy', 10, 1);
function outrank_on_delete_blog_legacy($blog_id) {
    // Only run on WordPress < 5.1 (wp_uninitialize_site handles 5.1+)
    if (version_compare(get_bloginfo('version'), '5.1', '>=')) {
        return;
    }

    outrank_cleanup_site($blog_id);
}

/**
 * Clean up plugin data for a specific site.
 *
 * @param int $blog_id The site ID to clean up.
 */
function outrank_cleanup_site($blog_id) {
    global $wpdb;

    switch_to_blog($blog_id);

    $table_name = $wpdb->prefix . 'outrank_manage';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table_name));

    // Clean up options
    delete_option('outrank_api_key');
    delete_option('outrank_post_as_draft');

    restore_current_blog();
}

// Enqueue admin styles and scripts
add_action('admin_enqueue_scripts', 'outrank_add_plugin_assets');
function outrank_add_plugin_assets($hook_suffix = '') {
    if (strpos($hook_suffix, 'outrank') === false) return; // Only enqueue on outrank pages

    wp_enqueue_style('outrank-style', OUTRANK_PLUGIN_URL . 'css/manage.css', [], '1.0.5');
    wp_enqueue_style('outrank-home-style', OUTRANK_PLUGIN_URL . 'css/home.css', [], '1.0.5');

    wp_enqueue_script('outrank-script', OUTRANK_PLUGIN_URL . 'script/manage.js', ['jquery'], '1.0.5', true);
}

// Helper function to get all articles from DB
function outrank_get_articles() {
    global $wpdb;

    // Ensure table exists (handles multisite subsites)
    outrank_ensure_table_exists();

    $cache_key = 'outrank_all_articles_' . get_current_blog_id();
    $articles = wp_cache_get($cache_key, 'outrank');

    if ($articles === false) {
        $table_name = esc_sql($wpdb->prefix . 'outrank_manage');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $articles = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY created_at DESC", $table_name));
        wp_cache_set($cache_key, $articles, 'outrank', 300); // Cache for 5 minutes
    }

    return $articles;
}

require_once OUTRANK_PLUGIN_PATH . 'libs/api.php';

$api_file = OUTRANK_PLUGIN_PATH . 'libs/api.php';

if (file_exists($api_file)) {
    require_once $api_file;
    // if (defined('WP_DEBUG') && WP_DEBUG === true) {
    //     error_log("✅ api.php included from $api_file");
    // }
// } else {
    // if (defined('WP_DEBUG') && WP_DEBUG === true) {
    //     error_log("❌ api.php NOT found at $api_file");
    // }
}
