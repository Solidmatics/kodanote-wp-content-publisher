<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Admin
 * 
 * Handles admin-specific functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Kodanote_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        // Admin-specific hooks can be added here
    }

    /**
     * Get synced articles for display in dashboard
     * 
     * @param array $args Query arguments
     * @return array
     */
    public function get_synced_articles($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => 'all',
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'synced_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Whitelist allowed orderby columns to prevent SQL injection
        $allowed_orderby = array('synced_at', 'published_at', 'title');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) 
            ? $args['orderby'] 
            : 'synced_at';
        
        // Whitelist order direction
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build query based on status filter
        if ($args['status'] !== 'all') {
            // Query with status filter - use prepare for all dynamic values
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is from $wpdb->prefix, $orderby and $order are whitelisted
            $articles = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $args['status'],
                $args['limit'],
                $args['offset']
            ));

            // Get total count with status filter
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
                $args['status']
            ));
        } else {
            // Query without status filter
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is from $wpdb->prefix, $orderby and $order are whitelisted
            $articles = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            ));

            // Get total count without filter
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        return array(
            'articles' => $articles,
            'total' => (int) $total,
        );
    }

    /**
     * Get article statistics
     * 
     * @return array
     */
    public function get_statistics() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $stats = array(
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
            'published' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'published')),
            'pending' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'pending')),
            'linked' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'linked')),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $stats;
    }

    /**
     * Delete article from sync table
     * 
     * @param int $article_id Article ID from sync table
     * @return bool
     */
    public function delete_article($article_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        return $wpdb->delete(
            $table_name,
            array('id' => $article_id),
            array('%d')
        ) !== false;
    }

    /**
     * Format article data for display
     * 
     * @param object $article Article object from database
     * @return array
     */
    public function format_article_for_display($article) {
        $formatted = array(
            'id' => $article->id,
            'kodanote_id' => $article->kodanote_id,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'status' => $article->status,
            'synced_at' => $article->synced_at,
            'published_at' => $article->published_at,
        );

        // Add post information if published
        if ($article->post_id) {
            $post = get_post($article->post_id);
            if ($post) {
                $formatted['post_id'] = $post->ID;
                $formatted['post_url'] = get_permalink($post->ID);
                $formatted['post_edit_url'] = get_edit_post_link($post->ID);
            }
        }

        return $formatted;
    }

    /**
     * Get dashboard statistics
     * Static method for template compatibility
     * 
     * @return array Dashboard statistics including counts and recent articles
     */
    public static function get_dashboard_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Get counts for different statuses
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $total_synced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $published = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'published'));
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE status = %s", 'pending'));
        
        // Get last sync time (most recent synced_at timestamp)
        $last_sync = $wpdb->get_var("SELECT synced_at FROM {$table_name} ORDER BY synced_at DESC LIMIT 1");
        
        // Get recent articles (limit to 10)
        $recent_articles = $wpdb->get_results(
            "SELECT id, kodanote_id, title, status, synced_at, published_at 
             FROM {$table_name} 
             ORDER BY synced_at DESC 
             LIMIT 10"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array(
            'total_synced' => $total_synced,
            'published' => $published,
            'pending' => $pending,
            'last_sync' => $last_sync,
            'recent_articles' => !empty($recent_articles) ? $recent_articles : array(),
        );
    }
}


