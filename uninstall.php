<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Plugin Uninstall
 *
 * This file handles the cleanup when the Kodanote plugin is uninstalled
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-kodanote-sitemap.php';
Kodanote_Sitemap::deactivate_cleanup();

// Clean up options
$kodanote_options_to_delete = array(
    'kodanote_api_key',
    'kodanote_api_key_set_time',
    'kodanote_author_box_remote_url',
    'kodanote_author_id',
    'kodanote_author_thumbnail_attachment_id',
    'kodanote_author_thumbnail_local_url',
    'kodanote_author_thumbnail_source_url',
    'kodanote_auto_publish',
    'kodanote_auto_verified_at',
    'kodanote_consecutive_sync_skips',
    'kodanote_db_version',
    'kodanote_debug_mode',
    'kodanote_last_permalink_structure',
    'kodanote_last_sync',
    'kodanote_last_sync_time',
    'kodanote_pending_auto_verification',
    'kodanote_pending_permalink_structure',
    'kodanote_permalink_rescan_after_id',
    'kodanote_post_category',
    'kodanote_show_setup_wizard',
    'kodanote_sitemap_robots_enabled',
    'kodanote_sitemap_status',
    'kodanote_sync_error',
);

foreach ($kodanote_options_to_delete as $kodanote_option) {
    delete_option($kodanote_option);
}

// Clean up database tables
global $wpdb;

$kodanote_tables_to_drop = array(
    $wpdb->prefix . 'kodanote_articles',
    $wpdb->prefix . 'kodanote_settings',
);

foreach ($kodanote_tables_to_drop as $kodanote_table) {
    // Table name is safe - constructed from $wpdb->prefix + hardcoded name
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS " . esc_sql($kodanote_table));
}

// Clean up scheduled events (legacy and current hooks)
wp_clear_scheduled_hook('kodanote_sync_articles');
wp_clear_scheduled_hook('kodanote_auto_sync');
wp_clear_scheduled_hook('kodanote_publish_scheduled_article');
wp_clear_scheduled_hook('kodanote_sitemap_check');
wp_clear_scheduled_hook('kodanote_rescan_published_urls');

// Clean up any transients
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_kodanote_') . '%'));
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_timeout_kodanote_') . '%'));

// Clean up post meta
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_kodanote_') . '%'));

// Clean up user meta (if any)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for uninstall cleanup
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like('kodanote_') . '%'));

$kodanote_schema_options = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('kodanote_schema_check_') . '%'
));
foreach ($kodanote_schema_options as $kodanote_option) {
    delete_option($kodanote_option);
}
