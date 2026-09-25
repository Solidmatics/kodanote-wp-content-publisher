<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote API Client
 * 
 * Handles communication with the Kodanote API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Kodanote_API {

    /**
     * API Base URL
     */
    private $api_base_url;

    /**
     * API Key
     */
    private $api_key;

    /**
     * Whether the sync table supports 4-byte UTF-8 (utf8mb4)
     */
    private $db_supports_utf8mb4 = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_base_url = rtrim(trim((string) KODANOTE_API_BASE_URL), '/');
        $this->api_key = get_option('kodanote_api_key', '');
    }

    /**
     * Check if the kodanote_articles table supports utf8mb4 (4-byte Unicode like emoji).
     * Caches the result per request.
     */
    private function check_utf8mb4_support() {
        if ($this->db_supports_utf8mb4 !== null) {
            return $this->db_supports_utf8mb4;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'");
        if ($row && isset($row->Collation)) {
            $this->db_supports_utf8mb4 = (strpos($row->Collation, 'utf8mb4') !== false);
        } else {
            $this->db_supports_utf8mb4 = false;
        }

        return $this->db_supports_utf8mb4;
    }

    /**
     * Strip 4-byte Unicode characters (emoji etc.) from text when the database
     * uses utf8 instead of utf8mb4. MySQL utf8 only supports up to 3-byte chars.
     */
    private function sanitize_for_db($text) {
        if (!is_string($text) || $text === '') {
            return $text;
        }

        if ($this->check_utf8mb4_support()) {
            return $text;
        }

        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /**
     * Sync articles from Kodanote API
     * 
     * @param bool $force_resync Force resync all articles (ignores last sync timestamp)
     * @param array|null $pushed_articles Articles pushed directly from the server (bypasses API call)
     * @return array|WP_Error
     */
    public function sync_articles($force_resync = false, $pushed_articles = null, $deleted_article_ids = null, $auto_publish = null) {
        global $wpdb;

        if (!is_array($pushed_articles) && $this->api_base_url === '') {
            return new WP_Error('no_api_url', __('Configure the Kodanote publishing API URL in wp-config.php before using outbound sync.', 'kodanote-content-publisher'));
        }

        if (empty($this->api_key) && $pushed_articles === null) {
            return new WP_Error('no_api_key', __('API key is not configured', 'kodanote-content-publisher'));
        }

        // Publishing a large article can take a long time on slow shared hosting.
        // Without this, PHP kills the request mid-sync and the article never lands.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        // Prevent concurrent sync operations (race condition → duplicate posts).
        // Uses a DB row lock: INSERT succeeds only for the first caller; others bail out.
        // Pushes deliberately share this lock with pull syncs: letting them run side
        // by side would let both create a post for the same article. A push that loses
        // the race reports a conflict and Kodanote retries it shortly.
        $lock_table = $wpdb->prefix . 'kodanote_settings';
        $lock_key   = 'sync_lock';
        $lock_value = time() . '|' . wp_generate_uuid4();
        $lock_max_age = 300; // seconds

        // Clean up expired locks using TWO strategies for robustness:
        // 1. Parse timestamp from lock value (works even without created_at column)
        // 2. Fall back to created_at column if available
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_lock = $wpdb->get_row($wpdb->prepare(
            "SELECT id, setting_value FROM {$lock_table} WHERE setting_key = %s LIMIT 1",
            $lock_key
        ));

        if ($existing_lock) {
            $should_clear = false;
            $lock_parts = explode('|', $existing_lock->setting_value, 2);

            if (is_numeric($lock_parts[0]) && abs(time() - intval($lock_parts[0])) > $lock_max_age) {
                // abs() also catches locks stamped in the future by a skewed clock,
                // which would otherwise never expire and block syncing permanently.
                $should_clear = true;
                $this->log_debug(sprintf(
                    'Clearing expired sync lock (age: %ds, max: %ds)',
                    time() - intval($lock_parts[0]),
                    $lock_max_age
                ));
            } elseif (!is_numeric($lock_parts[0])) {
                // Legacy lock without timestamp — clear unconditionally since
                // we can't determine its age (created_at column may not exist)
                $should_clear = true;
                $this->log_debug('Clearing legacy sync lock (no embedded timestamp)');
            }

            if ($should_clear) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->delete($lock_table, array('id' => $existing_lock->id), array('%d'));
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lock_acquired = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$lock_table} (setting_key, setting_value) VALUES (%s, %s)",
            $lock_key,
            $lock_value
        ));

        // Distinguish "someone else holds the lock" (INSERT IGNORE affected 0 rows)
        // from "the lock query itself failed" (false — e.g. the settings table is
        // missing). Treating a broken lock table as a held lock would silently
        // disable syncing forever, so in that case we continue without a lock.
        if ($lock_acquired === false) {
            $this->log_debug('Sync lock unavailable (' . $wpdb->last_error . ') - continuing without a lock');
            $lock_held = false;
        } elseif (!$lock_acquired) {
            $this->log_debug('Sync skipped: another sync operation is already in progress');
            return array(
                'success' => false,
                'skipped' => true,
                'message' => __('Sync skipped: another sync is already in progress', 'kodanote-content-publisher'),
                'synced_count' => 0,
                'errors' => array(),
            );
        } else {
            $lock_held = true;
        }

        // A PHP fatal error or an execution timeout aborts the request without
        // running finally blocks, which used to strand the lock and block every
        // later sync. Shutdown functions still run, so release it there too.
        if ($lock_held) {
            register_shutdown_function(array($this, 'release_sync_lock'), $lock_key, $lock_value);
        }

        try {
            return $this->do_sync_articles($force_resync, $pushed_articles, $deleted_article_ids, $auto_publish);
        } finally {
            if ($lock_held) {
                $this->release_sync_lock($lock_key, $lock_value);
            }
        }
    }

    /**
     * Release a sync lock row. Safe to call more than once: it only deletes the
     * row this request created, so it can never release someone else's lock.
     *
     * @param string $lock_key   Lock row key
     * @param string $lock_value Lock value owned by this request
     */
    public function release_sync_lock($lock_key, $lock_value) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->delete(
            $wpdb->prefix . 'kodanote_settings',
            array('setting_key' => $lock_key, 'setting_value' => $lock_value),
            array('%s', '%s')
        );
    }

    /**
     * Internal sync implementation (called after lock is acquired)
     *
     * @param bool $force_resync Force resync all articles
     * @param array|null $pushed_articles Articles pushed directly from the server (bypasses API call)
     * @return array|WP_Error
     */
    private function do_sync_articles($force_resync = false, $pushed_articles = null, $deleted_article_ids_from_trigger = null, $auto_publish_override = null) {
        global $wpdb;

        // In push mode, the server pushes images separately after articles are processed.
        // Skip outbound image downloads to avoid timeouts on servers with restricted connectivity.
        $is_push_mode = ($pushed_articles !== null && is_array($pushed_articles));

        $deleted_article_ids = is_array($deleted_article_ids_from_trigger) ? $deleted_article_ids_from_trigger : array();

        // auto_publish: determines whether new articles should be published to WordPress
        // or just stored in the sync table. Comes from either the API response (pull mode)
        // or the trigger-sync request parameter (push mode).
        $auto_publish = ($auto_publish_override !== null) ? $auto_publish_override : true;

        if ($is_push_mode) {
            $this->log_debug(sprintf('Processing %d pushed articles from server (no API call needed, images will be pushed separately)', count($pushed_articles)));
            $articles = $pushed_articles;
        } else {
            // Standard pull: fetch articles from the Kodanote API
            $last_sync = $force_resync ? null : get_option('kodanote_last_sync_time');
            
            $url = $this->api_base_url . '/articles/sync';
            if ($last_sync) {
                $url .= '?since=' . urlencode($last_sync);
            }

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                    'X-Kodanote-Plugin-Version' => KODANOTE_VERSION,
                    'X-WordPress-Site-URL' => home_url(),
                ),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code !== 200) {
                $error_message = isset($data['message']) ? $data['message'] : __('API request failed', 'kodanote-content-publisher');
                return new WP_Error('api_error', $error_message, array('status' => $status_code));
            }

            if (!isset($data['articles']) || !is_array($data['articles'])) {
                return new WP_Error('invalid_response', __('Invalid API response format', 'kodanote-content-publisher'));
            }

            $articles = $data['articles'];
            $api_deleted_ids = isset($data['deleted_article_ids']) && is_array($data['deleted_article_ids'])
                ? $data['deleted_article_ids']
                : array();
            $deleted_article_ids = array_unique(array_merge($deleted_article_ids, $api_deleted_ids));

            // Server tells us whether auto-publish is enabled for this site
            if (isset($data['auto_publish'])) {
                $auto_publish = (bool) $data['auto_publish'];
            }
        }
        $table_name = $wpdb->prefix . 'kodanote_articles';
        $synced_count = 0;
        $errors = array();

        // Self-heal: if the articles table is missing (failed activation, DB
        // restore, host migration, manual drop) every insert below would fail with
        // "Table doesn't exist". Recreate it now so this sync can proceed instead
        // of waiting for the hourly init-time schema check.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name
            && class_exists('Kodanote_Plugin')) {
            Kodanote_Plugin::get_instance()->create_articles_table_if_missing();
        }

        // Trash WordPress posts for articles deleted from the Kodanote dashboard
        if (!empty($deleted_article_ids)) {
            $this->trash_deleted_articles($deleted_article_ids, $table_name);
        }

        // Save sync time BEFORE processing to prevent timeout-induced loops.
        // If the plugin times out mid-processing, the next sync will use 'since'
        // and only fetch changed articles instead of re-fetching everything.
        // When we deliberately defer articles below we roll this back, so the
        // deferred ones are picked up again on the next run.
        $previous_sync_time = get_option('kodanote_last_sync_time');
        update_option('kodanote_last_sync_time', current_time('c'));

        // Stop before PHP does. A large backlog on slow hosting used to consume the
        // whole request and get killed mid-article, so nothing was ever published.
        // Deferred articles are simply picked up by the next sync.
        $time_budget = (int) apply_filters('kodanote_sync_time_budget', 60, $is_push_mode);
        $started_at = microtime(true);
        $deferred_count = 0;

        // Recover articles stuck in 'publishing' status (process crashed mid-publish).
        // Reset to 'pending' if they've been in 'publishing' for over 300 seconds.
        // Increased from 60s to 300s to match sync lock timeout and prevent
        // resetting articles while image downloads are still in progress.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET status = 'pending' WHERE status = 'publishing' AND synced_at < %s",
            gmdate('Y-m-d H:i:s', time() - 300)
        ));

        // Check if this is the first sync (no articles in table yet)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $is_first_sync = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}") == 0;

        // Catch permalink structure changes made outside the normal settings UI
        // (CLI/imports/security plugins) and the first run after a plugin upgrade.
        // The settings hook schedules a scan too, but sync is a reliable fallback
        // because it runs on every connected site.
        //
        // IMPORTANT: we only DETECT and SCHEDULE here. The actual rescan reports
        // one webhook per published post, and send_webhook() is a blocking 15s
        // request. Running it inline would fire N blocking calls during this sync
        // request and time out on sites with many posts. Instead we defer to the
        // background wp-cron handler (kodanote_rescan_published_urls), which works
        // through posts in small batches and reschedules itself until done.
        $current_permalink_structure = (string) get_option('permalink_structure', '');
        $last_permalink_structure = get_option('kodanote_last_permalink_structure', null);
        $permalink_uninitialized = ($last_permalink_structure === null || $last_permalink_structure === false);

        if ($permalink_uninitialized || (string) $last_permalink_structure !== $current_permalink_structure) {
            // Only start a fresh rescan if one isn't already pending for this exact
            // structure. This preserves the background cursor (kodanote_permalink_rescan_after_id)
            // so frequent syncs don't restart the scan from the beginning. We deliberately
            // do NOT update kodanote_last_permalink_structure here: leaving it stale means
            // sync keeps re-scheduling the cron (a retry safety net for sites whose wp-cron
            // is unreliable), and the cron sets the baseline once it finishes cleanly.
            $pending_permalink_structure = get_option('kodanote_pending_permalink_structure', null);
            if ((string) $pending_permalink_structure !== $current_permalink_structure) {
                update_option('kodanote_pending_permalink_structure', $current_permalink_structure, false);
                update_option('kodanote_permalink_rescan_after_id', 0, false);
            }

            if (!wp_next_scheduled('kodanote_rescan_published_urls')) {
                wp_schedule_single_event(time() + 30, 'kodanote_rescan_published_urls');
            }

            $this->log_debug(sprintf(
                'Permalink structure %s during sync; scheduled background published URL rescan',
                $permalink_uninitialized ? 'baseline initialization' : 'change detected'
            ));
        }

        // Pre-check column existence so INSERT/UPDATE never references
        // a column the local DB table doesn't have yet (handles upgrades
        // where the schema migration hasn't run).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $has_language_col = !empty($wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'language'
        )));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $has_source_article_id_col = !empty($wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'source_article_id'
        )));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $has_slug_col = !empty($wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'slug'
        )));

        Kodanote_Publisher::start_batch();

        try {

        foreach ($articles as $article) {
            // Never defer a directed push: the server sends only the articles it
            // specifically needs published, and there is no later run to catch them.
            if (!$is_push_mode && $time_budget > 0 && (microtime(true) - $started_at) > $time_budget) {
                $deferred_count++;
                continue;
            }

            try {
                // Validate required fields
                if (empty($article['id']) || empty($article['title'])) {
                    $errors[] = __('Article missing required fields (id or title)', 'kodanote-content-publisher');
                    continue;
                }

                // Pre-compute previous_article_ids JSON for storage
                $previous_article_ids_json = null;
                if (!empty($article['previous_article_ids']) && is_array($article['previous_article_ids'])) {
                    $previous_article_ids_json = wp_json_encode($article['previous_article_ids']);
                }

                // Check if article already exists in our sync table
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE kodanote_id = %s",
                    $article['id']
                ));

                // DUPLICATE PREVENTION (meta-based): Check if a post with this kodanote_id already exists via post meta
                // This is the most reliable check - immune to title encoding issues and sync table resets
                if ($is_first_sync || !$existing) {
                    $meta_query = new WP_Query(array(
                        'post_type'              => 'post',
                        'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                        'posts_per_page'         => 1,
                        'no_found_rows'          => true,
                        'ignore_sticky_posts'    => true,
                        'update_post_term_cache' => false,
                        'update_post_meta_cache' => false,
                        'meta_query'             => array(
                            array(
                                'key'   => '_kodanote_article_id',
                                'value' => $article['id'],
                            ),
                        ),
                    ));
                    $existing_post = !empty($meta_query->posts) ? $meta_query->posts[0] : null;
                    wp_reset_postdata();

                    // Fallback: check previous article versions (feedback rewrites create new IDs)
                    $matched_via_previous_version = false;
                    if (!$existing_post && !empty($article['previous_article_ids']) && is_array($article['previous_article_ids'])) {
                        foreach ($article['previous_article_ids'] as $prev_id) {
                            $prev_meta_query = new WP_Query(array(
                                'post_type'              => 'post',
                                'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                                'posts_per_page'         => 1,
                                'no_found_rows'          => true,
                                'ignore_sticky_posts'    => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'meta_query'             => array(
                                    array(
                                        'key'   => '_kodanote_article_id',
                                        'value' => (string) $prev_id,
                                    ),
                                ),
                            ));
                            $existing_post = !empty($prev_meta_query->posts) ? $prev_meta_query->posts[0] : null;
                            wp_reset_postdata();

                            if ($existing_post) {
                                update_post_meta($existing_post->ID, '_kodanote_article_id', (string) $article['id']);
                                $matched_via_previous_version = true;
                                $this->log_debug(sprintf(
                                    'Article "%s" (ID: %s) matched existing post via previous version %s (Post ID: %d) - updated meta',
                                    $article['title'],
                                    $article['id'],
                                    $prev_id,
                                    $existing_post->ID
                                ));
                                break;
                            }
                        }
                    }

                    // Fallback: check by title if no meta or previous version match found
                    if (!$existing_post) {
                        $title_query = new WP_Query(array(
                            'post_type'              => 'post',
                            'title'                  => $article['title'],
                            'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                            'posts_per_page'         => 1,
                            'no_found_rows'          => true,
                            'ignore_sticky_posts'    => true,
                            'update_post_term_cache' => false,
                            'update_post_meta_cache' => false,
                        ));
                        $existing_post = !empty($title_query->posts) ? $title_query->posts[0] : null;
                        wp_reset_postdata();
                    }

                    if ($existing_post) {
                        $this->log_debug(sprintf(
                            'Skipping article "%s" (ID: %s) - WordPress post already exists (Post ID: %d)',
                            $article['title'],
                            $article['id'],
                            $existing_post->ID
                        ));
                        
                        if (!$existing) {
                            // Use epoch synced_at for previous version matches as a safety
                            // net: if the immediate content update below fails, the next
                            // sync cycle will retry because api_updated_at > epoch.
                            $linked_synced_at = $matched_via_previous_version
                                ? '2000-01-01 00:00:00'
                                : current_time('mysql');

                            $linked_data = array(
                                    'kodanote_id' => $article['id'],
                                    'post_id' => $existing_post->ID,
                                    'title' => $this->sanitize_for_db($article['title']),
                                    'content' => $this->sanitize_for_db($article['content']),
                                    'content_markdown' => $this->sanitize_for_db($article['content_markdown'] ?? null),
                                    'excerpt' => $this->sanitize_for_db($article['excerpt'] ?? ''),
                                    'keywords' => is_array($article['keywords']) ? implode(',', $article['keywords']) : '',
                                    'meta_description' => $this->sanitize_for_db($article['meta_description'] ?? null),
                                    'meta_keywords' => $article['meta_keywords'] ?? null,
                                    'wordpress_tags' => $article['wordpress_tags'] ?? null,
                                    'featured_image_url' => $article['featured_image_url'] ?? null,
                                    'hero_image_url' => $article['hero_image_url'] ?? null,
                                    'hero_image_alt' => $this->sanitize_for_db($article['hero_image_alt'] ?? null),
                                    'infographic_html' => $this->sanitize_for_db($article['infographic_html'] ?? null),
                                    'infographic_image_url' => $article['infographic_image_url'] ?? null,
                                    'status' => 'linked',
                                    'synced_at' => $linked_synced_at,
                                    'previous_article_ids' => $previous_article_ids_json ?? null,
                            );
                            if ($has_language_col) {
                                $linked_data['language'] = $article['language'] ?? null;
                            }
                            if ($has_source_article_id_col) {
                                $linked_data['source_article_id'] = $article['source_article_id'] ?? null;
                            }
                            if ($has_slug_col) {
                                $linked_data['slug'] = $this->sanitize_for_db($article['slug'] ?? null);
                            }
                            $linked_formats = array_fill(0, count($linked_data), '%s');
                            $linked_formats[1] = '%d'; // post_id
                            $wpdb->insert($table_name, $linked_data, $linked_formats);

                            // Clean up stale sync table rows for replaced article versions
                            if (!empty($article['previous_article_ids']) && is_array($article['previous_article_ids'])) {
                                foreach ($article['previous_article_ids'] as $old_id) {
                                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                    $wpdb->delete($table_name, array('kodanote_id' => (string) $old_id), array('%s'));
                                }
                            }

                            // For previous version matches: refresh $existing and fall
                            // through to the update path so the rewritten content is
                            // pushed to WordPress in the same sync cycle.
                            if ($matched_via_previous_version) {
                                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                $existing = $wpdb->get_row($wpdb->prepare(
                                    "SELECT * FROM {$table_name} WHERE kodanote_id = %s",
                                    $article['id']
                                ));
                            }
                        }
                        
                        if (!$matched_via_previous_version) {
                            continue;
                        }
                    }
                }

                // Articles trashed by the WordPress user should not be published
                // again during normal sync. In push mode, still refresh the local
                // copy so dashboard edits are ready if the user later republishes.
                $should_keep_trashed = $existing && $existing->status === 'trashed';
                if ($should_keep_trashed && !$is_push_mode) {
                    $this->log_debug(sprintf(
                        'Skipping trashed article "%s" (trashed by WordPress user)',
                        $article['title']
                    ));
                    $synced_count++;
                    continue;
                }

                // Check if article already published to WordPress (has a post_id)
                // Also treat 'publishing' status as already-in-progress to avoid race conditions
                $is_already_published = $existing && !$should_keep_trashed && (!empty($existing->post_id) || $existing->status === 'publishing');
                
                // Prepare article data
                // If already published, keep 'published' status to avoid re-triggering publish_article()
                // which could create duplicates if the title has changed
                
                // Parse the intended publication date from the API
                // This is the date the article should show as published on WordPress
                $intended_published_at = null;
                if (!empty($article['published_at'])) {
                    // Convert ISO 8601 date to MySQL format in WordPress timezone
                    $timestamp = strtotime($article['published_at']);
                    if ($timestamp) {
                        $intended_published_at = gmdate('Y-m-d H:i:s', $timestamp);
                    }
                }
                
                $article_data = array(
                    'kodanote_id' => $article['id'],
                    'title' => $this->sanitize_for_db($article['title']),
                    'content' => $this->sanitize_for_db($article['content']),
                    'content_markdown' => $this->sanitize_for_db($article['content_markdown'] ?? null),
                    'excerpt' => $this->sanitize_for_db($article['excerpt'] ?? ''),
                    'keywords' => is_array($article['keywords']) ? implode(',', $article['keywords']) : '',
                    'meta_description' => $this->sanitize_for_db($article['meta_description'] ?? null),
                    'meta_keywords' => $article['meta_keywords'] ?? null,
                    'wordpress_tags' => $article['wordpress_tags'] ?? null,
                    'featured_image_url' => $article['featured_image_url'] ?? null,
                    'hero_image_url' => $article['hero_image_url'] ?? null,
                    'hero_image_alt' => $this->sanitize_for_db($article['hero_image_alt'] ?? null),
                    'infographic_html' => $this->sanitize_for_db($article['infographic_html'] ?? null),
                    'infographic_image_url' => $article['infographic_image_url'] ?? null,
                    'status' => $should_keep_trashed ? 'trashed' : ($is_already_published ? 'published' : 'pending'),
                    'synced_at' => current_time('mysql'),
                    'intended_published_at' => $intended_published_at,
                    'faq_schema' => isset($article['faq_schema']) ? wp_json_encode($article['faq_schema']) : null,
                    'previous_article_ids' => $previous_article_ids_json,
                );
                if ($has_language_col) {
                    $article_data['language'] = $article['language'] ?? null;
                }
                if ($has_source_article_id_col) {
                    $article_data['source_article_id'] = $article['source_article_id'] ?? null;
                }
                if ($has_slug_col) {
                    $article_data['slug'] = $this->sanitize_for_db($article['slug'] ?? null);
                }

                if ($existing) {
                    // For already-published articles, preserve old synced_at during the
                    // initial DB update. Only bump it after successful processing.
                    // This ensures failed updates (e.g. image download timeout) are
                    // retried on the next sync instead of being permanently skipped.
                    $update_data = $article_data;
                    if ($is_already_published) {
                        $update_data['synced_at'] = $existing->synced_at;
                    }

                    // Update existing article in sync table
                    $update_formats = array_fill(0, count($update_data), '%s');
                    $update_result = $wpdb->update(
                        $table_name,
                        $update_data,
                        array('kodanote_id' => $article['id']),
                        $update_formats,
                        array('%s')
                    );
                    
                    if ($update_result === false) {
                        $this->log_debug('Database update failed: ' . $wpdb->last_error);
                        $errors[] = sprintf('Database update failed for article "%s": %s', $article['title'], $wpdb->last_error);
                        continue;
                    }

                    if ($should_keep_trashed) {
                        $this->log_debug(sprintf(
                            'Updated local copy for trashed article "%s" without republishing',
                            $article['title']
                        ));
                        $synced_count++;
                        continue;
                    }
                    
                    // If already published, check that the WP post is still alive,
                    // then update it only if content has changed.
                    if ($is_already_published) {
                        // FIRST: verify the WordPress post still exists and isn't trashed.
                        // This must run before the skip-unchanged check, otherwise trashed
                        // posts are never detected and the article stays in limbo forever.
                        // Use a direct DB query alongside get_post() to bypass WP's object
                        // cache which can return stale data for deleted/phantom posts.
                        $wp_post = get_post($existing->post_id);
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $db_post_status = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' LIMIT 1",
                            $existing->post_id
                        ));
                        if (!$db_post_status) {
                            $wp_post = null;
                            wp_cache_delete($existing->post_id, 'posts');
                        } elseif ($db_post_status === 'trash') {
                            $wp_post = get_post($existing->post_id);
                            if ($wp_post) {
                                $wp_post->post_status = 'trash';
                            }
                        }
                        if (!$wp_post || $wp_post->post_status === 'trash') {
                            // Before declaring this article trashed, fall back to a meta
                            // lookup by _kodanote_article_id. The sync table's post_id can
                            // become stale (e.g. after duplicate cleanup, a manual trash +
                            // re-publish cycle, or an object-cache blip) even though a
                            // different, live post still represents this article on the
                            // site. If such a live post exists, re-link to it instead of
                            // firing a false "article_trashed" webhook.
                            $alive_query = new WP_Query(array(
                                'post_type'              => 'post',
                                'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
                                'posts_per_page'         => 1,
                                'no_found_rows'          => true,
                                'ignore_sticky_posts'    => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'meta_query'             => array(
                                    array(
                                        'key'   => '_kodanote_article_id',
                                        'value' => (string) $article['id'],
                                    ),
                                ),
                            ));
                            $alive_post = !empty($alive_query->posts) ? $alive_query->posts[0] : null;
                            wp_reset_postdata();

                            if ($alive_post) {
                                $this->log_debug(sprintf(
                                    'Stale post_id for article "%s": sync table had %s (trashed/missing), re-linking to live post %d via meta lookup',
                                    $article['title'],
                                    $existing->post_id ?? 'null',
                                    $alive_post->ID
                                ));

                                $wpdb->update(
                                    $table_name,
                                    array('post_id' => $alive_post->ID),
                                    array('id' => $existing->id),
                                    array('%d'),
                                    array('%d')
                                );

                                $existing->post_id = $alive_post->ID;
                                $wp_post = $alive_post;
                                // Fall through to the normal update path below.
                            } else {
                                // WP post genuinely deleted — no live post carries this kodanote_id.
                                $current_recreate_count = isset($existing->recreate_count) ? (int) $existing->recreate_count : 0;

                                if ($current_recreate_count < 2) {
                                    // Auto-recover: reset to pending so the article is
                                    // republished in this same sync cycle. This handles
                                    // accidental deletions, security-plugin cleanup, and
                                    // sites where Cloudflare blocks our push sync so we
                                    // can't force-republish from the server.
                                    $this->log_debug(sprintf(
                                        'WordPress post %s for article "%s" was deleted, auto-recreating (attempt %d/2)',
                                        $existing->post_id ?? 'null',
                                        $article['title'],
                                        $current_recreate_count + 1
                                    ));

                                    // Split into two updates: core fields first (always
                                    // succeed), then recreate_count separately (column may
                                    // not exist yet on older schemas — prevents the entire
                                    // UPDATE from failing).
                                    $wpdb->update(
                                        $table_name,
                                        array('post_id' => null, 'status' => 'pending'),
                                        array('id' => $existing->id),
                                        array('%s', '%s'),
                                        array('%d')
                                    );
                                    $wpdb->update(
                                        $table_name,
                                        array('recreate_count' => $current_recreate_count + 1),
                                        array('id' => $existing->id),
                                        array('%d'),
                                        array('%d')
                                    );

                                    $article_data['status'] = 'pending';
                                    // Fall through to the auto-publish block below.
                                } else {
                                    // Already auto-recreated twice — treat as deliberate
                                    // deletion. Mark as trashed and notify Kodanote.
                                    $this->log_debug(sprintf(
                                        'WordPress post %s for article "%s" was deleted again after %d auto-recreations, marking as trashed',
                                        $existing->post_id ?? 'null',
                                        $article['title'],
                                        $current_recreate_count
                                    ));
                                    $wpdb->update(
                                        $table_name,
                                        array('post_id' => null, 'status' => 'trashed'),
                                        array('id' => $existing->id),
                                        array('%s', '%s'),
                                        array('%d')
                                    );

                                    $this->send_webhook('article_trashed', array(
                                        'article_id' => $article['id'],
                                    ));

                                    $synced_count++;
                                    continue;
                                }
                            }
                        }

                        if ($article_data['status'] === 'pending') {
                            // Auto-recreate path: skip the alive-post update logic
                            // and fall through to the auto-publish block below.
                        } else {

                        // At this point $wp_post is alive (either from the sync table's
                        // post_id or re-linked via the meta-based fallback above).
                        // If the API reports it never received our published URL,
                        // re-send the webhook so published_url gets set
                        $needs_url_confirmation = !empty($article['needs_url_confirmation']);

                        // Post is alive -- skip update if article content hasn't changed.
                        // API updated_at is UTC ISO 8601; synced_at is WordPress local time, so convert to UTC for comparison.
                        $api_updated_at = !empty($article['updated_at']) ? strtotime($article['updated_at']) : 0;
                        $synced_at_utc = !empty($existing->synced_at) ? strtotime(get_gmt_from_date($existing->synced_at)) : 0;

                        $missing_assets = false;
                        if (!$is_push_mode) {
                            if (!empty($article['hero_image_url']) && !has_post_thumbnail($wp_post->ID)) {
                                $missing_assets = true;
                                $this->log_debug(sprintf(
                                    'Article "%s" is unchanged but missing its hero image - retrying asset download',
                                    $article['title']
                                ));
                            }
                            if (!empty($article['infographic_image_url']) && !get_post_meta($wp_post->ID, '_kodanote_infographic_image_id', true)) {
                                $missing_assets = true;
                                $this->log_debug(sprintf(
                                    'Article "%s" is unchanged but missing its infographic image - retrying asset download',
                                    $article['title']
                                ));
                            }
                        }

                        // FAQ schema is stored separately from post content, so a post can
                        // look up-to-date while its JSON-LD meta was never saved. Restore
                        // the meta in place. Forcing a full content refresh instead turned
                        // every affected post into a slow rewrite, and on slow hosts the
                        // sync ran out of execution time before reaching new articles.
                        if (!empty($article['faq_schema'])) {
                            $stored_faq_schema = get_post_meta($wp_post->ID, '_kodanote_faq_schema', true);
                            $stored_faqs = is_string($stored_faq_schema)
                                ? json_decode($stored_faq_schema, true)
                                : $stored_faq_schema;

                            if (empty($stored_faqs) || !is_array($stored_faqs)) {
                                update_post_meta($wp_post->ID, '_kodanote_faq_schema', $article['faq_schema']);
                                $this->log_debug(sprintf(
                                    'Restored missing FAQ schema meta for article "%s" without rewriting the post',
                                    $article['title']
                                ));
                            }
                        }

                        $force_content_update = !empty($article['force_content_update']);

                        // Older syncs can leave markdown/plain text in post_content while the
                        // API now sends proper HTML with paragraph tags. Re-push when the
                        // stored post is missing paragraphs but incoming content has them.
                        $stored_has_paragraphs = (bool) preg_match('/<p\b/i', $wp_post->post_content);
                        $incoming_has_paragraphs = (bool) preg_match('/<p\b/i', $article['content'] ?? '');
                        $needs_paragraph_repair = $incoming_has_paragraphs
                            && !$stored_has_paragraphs
                            && preg_match('/<(h[1-6]|div|ul|ol)\b/i', $wp_post->post_content);

                        if ($needs_paragraph_repair) {
                            $force_content_update = true;
                            $this->log_debug(sprintf(
                                'Article "%s" stored without paragraph tags — forcing content refresh',
                                $article['title']
                            ));
                        }

                        if ($api_updated_at > 0
                            && $synced_at_utc > 0
                            && $api_updated_at <= $synced_at_utc
                            && !$missing_assets
                            && !$force_content_update
                        ) {
                            if ($needs_url_confirmation) {
                                $publisher = new Kodanote_Publisher();
                                $published_url = $publisher->get_post_permalink($wp_post->ID);
                                $webhook_data = array(
                                    'article_id' => $article['id'],
                                    'wordpress_post_id' => $wp_post->ID,
                                    'published_url' => $published_url,
                                );
                                if (Kodanote_Publisher::is_batching()) {
                                    Kodanote_Publisher::add_to_batch($webhook_data);
                                } else {
                                    $this->send_webhook('article_published', $webhook_data);
                                }
                                $this->log_debug(sprintf(
                                    'Re-sending URL confirmation webhook for article "%s" (URL: %s)',
                                    $article['title'],
                                    $published_url
                                ));
                            } else {
                                $this->log_debug(sprintf(
                                    'Skipping unchanged article "%s" (API updated_at: %s, synced_at: %s UTC)',
                                    $article['title'],
                                    $article['updated_at'] ?? 'unknown',
                                    gmdate('Y-m-d H:i:s', $synced_at_utc)
                                ));
                            }
                            $synced_count++;
                            continue;
                        }

                        $skip_webhook = !$needs_url_confirmation;
                        $publisher = new Kodanote_Publisher();
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $refreshed_article = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$table_name} WHERE id = %d",
                            $existing->id
                        ));
                        if ($refreshed_article) {
                            $refreshed_article->force_content_update = $force_content_update;
                        }
                        $update_result = $publisher->update_existing_article($existing->id, $refreshed_article, $wp_post, $skip_webhook, $is_push_mode);
                        if (!is_wp_error($update_result)) {
                            // In push mode, images are pushed separately by the server
                            // after the trigger-sync response, so skip asset checks.
                            $assets_complete = true;
                            if (!$is_push_mode) {
                                if (!empty($article['infographic_image_url']) && !get_post_meta($wp_post->ID, '_kodanote_infographic_image_id', true)) {
                                    $assets_complete = false;
                                    $this->log_debug(sprintf(
                                        'Infographic download incomplete for post %d ("%s") - will retry on next sync',
                                        $wp_post->ID,
                                        $article['title']
                                    ));
                                }
                                if (!empty($article['hero_image_url']) && !has_post_thumbnail($wp_post->ID)) {
                                    $assets_complete = false;
                                    $this->log_debug(sprintf(
                                        'Hero image download incomplete for post %d ("%s") - will retry on next sync',
                                        $wp_post->ID,
                                        $article['title']
                                    ));
                                }
                            }

                            if ($assets_complete) {
                                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                $wpdb->update(
                                    $table_name,
                                    array('synced_at' => current_time('mysql')),
                                    array('id' => $existing->id),
                                    array('%s'),
                                    array('%d')
                                );
                            }
                            $synced_count++;
                            $this->log_debug(sprintf(
                                'Updated existing WordPress post %d for article "%s"%s',
                                $existing->post_id,
                                $article['title'],
                                $assets_complete ? '' : ' (assets incomplete, synced_at preserved for retry)'
                            ));
                        } else {
                            $this->log_debug(sprintf(
                                'Failed to update article "%s" - synced_at preserved for retry on next sync',
                                $article['title']
                            ));
                            $errors[] = sprintf(
                                /* translators: 1: article title, 2: error message */
                                __('Failed to update article "%1$s": %2$s', 'kodanote-content-publisher'),
                                $article['title'],
                                $update_result->get_error_message()
                            );
                        }

                        } // end alive-post update path
                    }
                } else {
                    // Insert new article
                    $insert_formats = array_fill(0, count($article_data), '%s');
                    $insert_result = $wpdb->insert(
                        $table_name,
                        $article_data,
                        $insert_formats
                    );
                    
                    if ($insert_result === false) {
                        $this->log_debug('Database insert failed: ' . $wpdb->last_error);
                        $errors[] = sprintf('Database insert failed for article "%s": %s', $article['title'], $wpdb->last_error);
                        continue;
                    }
                }

                // Store author box thumbnail URL from API as a site-level option
                if (array_key_exists('author_box_thumbnail_url', $article)) {
                    update_option('kodanote_author_box_remote_url', $article['author_box_thumbnail_url'] ?: '');
                }

                // Auto-publish NEW articles (not already published ones)
                // Only publish if the site has auto_publish enabled
                if ($article_data['status'] === 'pending' && $auto_publish) {
                    $article_table_id = $existing ? $existing->id : $wpdb->insert_id;
                    
                    $publisher = new Kodanote_Publisher();
                    $publish_result = $publisher->publish_article($article_table_id, $is_push_mode);
                    
                    if (!is_wp_error($publish_result)) {
                        $synced_count++;
                    } else {
                        $errors[] = sprintf(
                            /* translators: 1: article title, 2: error message */
                            __('Failed to publish article "%1$s": %2$s', 'kodanote-content-publisher'),
                            $article['title'],
                            $publish_result->get_error_message()
                        );
                    }
                } elseif ($article_data['status'] === 'pending' && !$auto_publish) {
                    $synced_count++;
                    $this->log_debug(sprintf(
                        'Article "%s" synced but not published (auto_publish disabled)',
                        $article['title']
                    ));
                }

            } catch (Exception $e) {
                $errors[] = sprintf(
                    /* translators: 1: article title, 2: error message */
                    __('Error syncing article "%1$s": %2$s', 'kodanote-content-publisher'),
                    $article['title'] ?? 'Unknown',
                    $e->getMessage()
                );
            }
        }

        } finally {
            $batched_webhooks = Kodanote_Publisher::end_batch();
        }

        if (!empty($batched_webhooks)) {
            $this->send_webhook('articles_batch_published', array(
                'articles' => $batched_webhooks,
            ));
            $this->log_debug(sprintf('Sent batched webhook for %d articles', count($batched_webhooks)));
        }

        if ($deferred_count > 0) {
            // Rewind the cursor so the deferred articles are fetched again next run.
            if ($previous_sync_time) {
                update_option('kodanote_last_sync_time', $previous_sync_time);
            } else {
                delete_option('kodanote_last_sync_time');
            }

            $this->log_debug(sprintf(
                'Deferred %d article(s) to the next sync after %ds time budget',
                $deferred_count,
                $time_budget
            ));
        }

        return array(
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of articles synced */
                __('%d article(s) synced successfully', 'kodanote-content-publisher'),
                $synced_count
            ),
            'synced_count' => $synced_count,
            'deferred_count' => $deferred_count,
            'errors' => $errors,
        );
    }

    /**
     * Trash WordPress posts for articles deleted from the Kodanote dashboard.
     *
     * @param array  $deleted_article_ids Kodanote article IDs that were deleted
     * @param string $table_name          wp_kodanote_articles table name
     */
    private function trash_deleted_articles($deleted_article_ids, $table_name) {
        global $wpdb;

        foreach ($deleted_article_ids as $deleted_id) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE kodanote_id = %s",
                (string) $deleted_id
            ));

            if (!$existing) {
                continue;
            }

            if ($existing->status === 'trashed') {
                $this->log_debug(sprintf('Article %s already trashed locally, skipping', $deleted_id));
                continue;
            }

            if (!empty($existing->post_id)) {
                $wp_post = get_post($existing->post_id);
                if ($wp_post && $wp_post->post_status !== 'trash') {
                    // Bypass the plugin's own trash-prevention hook
                    global $kodanote_allow_trash;
                    $kodanote_allow_trash = true;
                    wp_trash_post($existing->post_id);
                    $kodanote_allow_trash = false;
                    $this->log_debug(sprintf(
                        'Trashed WordPress post %d for deleted article %s ("%s")',
                        $existing->post_id,
                        $deleted_id,
                        $existing->title
                    ));
                }
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->update(
                $table_name,
                array('post_id' => null, 'status' => 'trashed'),
                array('id' => $existing->id),
                array('%s', '%s'),
                array('%d')
            );
        }
    }

    /**
     * Test API connection
     * 
     * @return array|WP_Error
     */
    public function test_connection() {
        if ($this->api_base_url === '') {
            return new WP_Error('no_api_url', __('Configure the Kodanote publishing API URL in wp-config.php before testing the connection.', 'kodanote-content-publisher'));
        }

        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('API key is not configured', 'kodanote-content-publisher'));
        }

        $url = $this->api_base_url . '/articles/sync?limit=1';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'X-Kodanote-Plugin-Version' => KODANOTE_VERSION,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return array(
                'success' => true,
                'message' => __('Connection successful!', 'kodanote-content-publisher'),
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $error_message = isset($data['message']) ? $data['message'] : __('Connection failed', 'kodanote-content-publisher');

        return new WP_Error('connection_failed', $error_message, array('status' => $status_code));
    }

    /**
     * Send webhook to Kodanote API
     * 
     * @param string $event Event name
     * @param array $data Event data
     * @return bool|WP_Error
     */
    public function send_webhook($event, $data = array()) {
        if ($this->api_base_url === '') {
            return new WP_Error('no_api_url', __('Configure the Kodanote publishing API URL in wp-config.php before sending webhooks.', 'kodanote-content-publisher'));
        }

        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('API key is not configured', 'kodanote-content-publisher'));
        }

        // Per-article deduplication: skip if we sent the same event for this
        // article_id within the last 15 minutes
        $dedup_key = null;
        if ($event === 'article_published' && !empty($data['article_id'])) {
            $dedup_key = 'kodanote_wh_' . md5($event . '_' . $data['article_id']);
            if (get_transient($dedup_key)) {
                $this->log_debug(sprintf(
                    'Webhook deduplicated: %s for article %s (sent recently)',
                    $event,
                    $data['article_id']
                ));
                return true;
            }
        }

        $url = $this->api_base_url . '/webhooks/wordpress';

        $payload = array(
            'event' => $event,
            'data' => $data,
            'timestamp' => current_time('c'),
            'wordpress_site_url' => home_url(),
        );

        $json_body = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $json_body, $this->api_key);

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'X-Kodanote-Plugin-Version' => KODANOTE_VERSION,
                'X-Kodanote-Signature' => $signature,
            ),
            'body' => $json_body,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            $this->log_debug('Webhook send failed: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            if ($dedup_key) {
                set_transient($dedup_key, time(), 900);
            }
            $this->log_debug('Webhook sent successfully: ' . $event);
            return true;
        }

        $body = wp_remote_retrieve_body($response);
        $this->log_debug('Webhook send failed (status ' . $status_code . '): ' . $body);

        return new WP_Error('webhook_failed', __('Webhook delivery failed', 'kodanote-content-publisher'), array('status' => $status_code));
    }

    /**
     * Log debug message (only if debug mode is enabled)
     * 
     * @param string $message
     */
    private function log_debug($message) {
        $debug_mode = get_option('kodanote_debug_mode', '0');
        if ($debug_mode === '1') {
            error_log('[Kodanote] ' . $message);
        }
    }
}





