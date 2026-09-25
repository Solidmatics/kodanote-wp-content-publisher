<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Publisher
 * 
 * Handles publishing of synced articles to WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Kodanote_Publisher {

    private static $batch_mode = false;
    private static $batched_webhooks = array();

    public static function start_batch() {
        if (self::$batch_mode) {
            return; // Already batching — don't reset collected webhooks
        }
        self::$batch_mode = true;
        self::$batched_webhooks = array();
    }

    public static function is_batching() {
        return self::$batch_mode;
    }

    public static function get_batched_webhooks() {
        return self::$batched_webhooks;
    }

    public static function add_to_batch($webhook_data) {
        self::$batched_webhooks[] = $webhook_data;
    }

    public static function end_batch() {
        $webhooks = self::$batched_webhooks;
        self::$batch_mode = false;
        self::$batched_webhooks = array();
        return $webhooks;
    }

    public function get_post_permalink($post_id) {
        return $this->get_published_url($post_id);
    }

    /**
     * Re-check published Kodanote post URLs and notify the API when WordPress now
     * resolves a different permalink. This catches site-wide permalink structure
     * changes that do not change individual post slugs.
     *
     * @param int  $limit Maximum posts to scan in one run.
     * @param bool $force_report_untracked Send URL updates even when no last reported URL is stored.
     * @param int  $after_id Continue scanning after this wp_kodanote_articles row ID.
     * @return array
     */
    public function rescan_published_urls($limit = 500, $force_report_untracked = false, $after_id = 0) {
        global $wpdb;

        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            return array('checked' => 0, 'updated' => 0, 'errors' => 0, 'last_id' => (int) $after_id, 'has_more' => false);
        }

        $table_name = $wpdb->prefix . 'kodanote_articles';
        $limit = max(1, min((int) $limit, 1000));
        $after_id = max(0, (int) $after_id);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT id, kodanote_id, post_id FROM {$table_name} WHERE status = %s AND id > %d AND post_id IS NOT NULL AND post_id != 0 ORDER BY id ASC LIMIT %d",
            'published',
            $after_id,
            $limit
        ));

        if (empty($articles)) {
            return array('checked' => 0, 'updated' => 0, 'errors' => 0, 'last_id' => $after_id, 'has_more' => false);
        }

        $api = new Kodanote_API();
        $checked = 0;
        $updated = 0;
        $errors = 0;
        $last_id = $after_id;

        foreach ($articles as $article) {
            $last_id = max($last_id, (int) $article->id);
            $post_id = (int) $article->post_id;
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
                continue;
            }

            $kodanote_id = get_post_meta($post_id, '_kodanote_article_id', true);
            if (empty($kodanote_id)) {
                $kodanote_id = $article->kodanote_id;
            }
            if (empty($kodanote_id)) {
                continue;
            }

            $checked++;
            $current_url = $this->get_published_url($post_id);
            if (empty($current_url)) {
                continue;
            }

            $last_reported_url = get_post_meta($post_id, '_kodanote_last_reported_url', true);
            if (empty($last_reported_url) && !$force_report_untracked) {
                update_post_meta($post_id, '_kodanote_last_reported_url', esc_url_raw($current_url));
                continue;
            }

            if (!empty($last_reported_url) && $last_reported_url === $current_url) {
                continue;
            }

            $result = $api->send_webhook('article_url_updated', array(
                'article_id'        => (string) $kodanote_id,
                'wordpress_post_id' => $post_id,
                'published_url'     => $current_url,
                'old_url'           => $last_reported_url ?: null,
            ));

            if (is_wp_error($result)) {
                $errors++;
                $this->log_debug(sprintf(
                    'Published URL rescan failed for post %d: %s',
                    $post_id,
                    $result->get_error_message()
                ));
                continue;
            }

            update_post_meta($post_id, '_kodanote_last_reported_url', esc_url_raw($current_url));
            $updated++;
        }

        return array(
            'checked' => $checked,
            'updated' => $updated,
            'errors' => $errors,
            'last_id' => $last_id,
            'has_more' => count($articles) === $limit,
        );
    }

    /**
     * Publish an article from the sync table to WordPress
     * 
     * @param int $article_table_id ID from wp_kodanote_articles table
     * @param bool $skip_image_downloads When true, skips outbound image downloads (images pushed separately by server)
     * @return array|WP_Error
     */
    public function publish_article($article_table_id, $skip_image_downloads = false) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Atomic claim: UPDATE status to 'publishing' only if currently 'pending'.
        // If another process already claimed this article, rows_affected = 0 → bail out.
        // This prevents two concurrent publish_article() calls from both creating WP posts.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET status = 'publishing' WHERE id = %d AND status = 'pending'",
            $article_table_id
        ));

        if (!$claimed) {
            // Either another process is publishing, or the article is already published.
            // Re-read to decide: if it has a post_id, update the existing post instead.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $article = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $article_table_id
            ));

            if ($article && $article->post_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $db_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' LIMIT 1",
                    $article->post_id
                ));
                if ($db_status && $db_status !== 'trash') {
                    $existing_post = get_post($article->post_id);
                    if ($existing_post) {
                        return $this->update_existing_article($article_table_id, $article, $existing_post, false, $skip_image_downloads);
                    }
                }
            }

            $this->log_debug(sprintf(
                'Publish skipped for article table ID %d: could not claim (status: %s)',
                $article_table_id,
                $article ? $article->status : 'not found'
            ));
            return new WP_Error('publish_skipped', __('Article already being published by another process', 'kodanote-content-publisher'));
        }

        // Re-read article after claiming
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $article_table_id
        ));

        if (!$article) {
            return new WP_Error('article_not_found', __('Article not found in sync table', 'kodanote-content-publisher'));
        }

        // Check if article already has a WordPress post linked
        if ($article->post_id) {
            $existing_post = get_post($article->post_id);
            // Direct DB check bypasses WP object cache which can return stale/phantom posts
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $db_post_status = $wpdb->get_var($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' LIMIT 1",
                $article->post_id
            ));
            if (!$db_post_status) {
                $this->log_debug(sprintf(
                    'Article "%s" (ID: %d) - WordPress post %d is phantom (cache says exists but DB says no), will create new post',
                    $article->title,
                    $article->kodanote_id,
                    $article->post_id
                ));
                wp_cache_delete($article->post_id, 'posts');
                $existing_post = null;
            }
            if ($existing_post) {
                $effective_status = $db_post_status ?: $existing_post->post_status;
                if ($effective_status === 'trash') {
                    $this->log_debug(sprintf(
                        'Article "%s" (ID: %d) - WordPress post %d is trashed, will create new post',
                        $article->title,
                        $article->kodanote_id,
                        $article->post_id
                    ));
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->update(
                        $table_name,
                        array('post_id' => null),
                        array('id' => $article_table_id),
                        array('%s'),
                        array('%d')
                    );
                } else {
                    $this->log_debug(sprintf(
                        'Article "%s" (ID: %d) already published - updating WordPress post (Post ID: %d, status: %s, db_status: %s)',
                        $article->title,
                        $article->kodanote_id,
                        $article->post_id,
                        $existing_post->post_status,
                        $db_post_status ?: 'unknown'
                    ));
                    return $this->update_existing_article($article_table_id, $article, $existing_post, false, $skip_image_downloads);
                }
            }
            if (!$existing_post) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update(
                    $table_name,
                    array('post_id' => null),
                    array('id' => $article_table_id),
                    array('%s'),
                    array('%d')
                );
            }
        }

        // DUPLICATE PREVENTION (meta-based): Check if a post with this kodanote_id already exists
        // This is the most reliable check because _kodanote_article_id is set atomically
        // during wp_insert_post via meta_input, and is immune to title encoding issues
        if (!empty($article->kodanote_id)) {
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
                        'value' => $article->kodanote_id,
                    ),
                ),
            ));
            $existing_post_by_meta = !empty($meta_query->posts) ? $meta_query->posts[0] : null;
            wp_reset_postdata();

            if ($existing_post_by_meta && $existing_post_by_meta->post_status !== 'trash') {
                $wpdb->update(
                    $table_name,
                    array(
                        'post_id' => $existing_post_by_meta->ID,
                        'status' => 'published',
                    ),
                    array('id' => $article_table_id),
                    array('%d', '%s'),
                    array('%d')
                );

                $this->log_debug(sprintf(
                    'Article "%s" (kodanote_id: %s) found existing post by meta (Post ID: %d) - linking and updating',
                    $article->title,
                    $article->kodanote_id,
                    $existing_post_by_meta->ID
                ));

                return $this->update_existing_article($article_table_id, $article, $existing_post_by_meta, false, $skip_image_downloads);
            }
        }

        // DUPLICATE PREVENTION (previous versions): Check if this article replaces a previous version.
        // When users submit feedback, Kodanote creates a new article version with a new ID.
        // The previous_article_ids array contains all ancestor IDs so we can find the existing
        // WordPress post and update it instead of creating a duplicate.
        if (!empty($article->previous_article_ids)) {
            $prev_ids = is_string($article->previous_article_ids) 
                ? json_decode($article->previous_article_ids, true) 
                : (array) $article->previous_article_ids;
            
            if (!empty($prev_ids)) {
                foreach ($prev_ids as $prev_id) {
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
                    $existing_post_by_prev = !empty($prev_meta_query->posts) ? $prev_meta_query->posts[0] : null;
                    wp_reset_postdata();

                    if ($existing_post_by_prev && $existing_post_by_prev->post_status !== 'trash') {
                        update_post_meta($existing_post_by_prev->ID, '_kodanote_article_id', $article->kodanote_id);

                        $wpdb->update(
                            $table_name,
                            array(
                                'post_id' => $existing_post_by_prev->ID,
                                'status' => 'published',
                            ),
                            array('id' => $article_table_id),
                            array('%d', '%s'),
                            array('%d')
                        );

                        $this->log_debug(sprintf(
                            'Article "%s" (kodanote_id: %s) found existing post via previous version %s (Post ID: %d) - updating meta and content',
                            $article->title,
                            $article->kodanote_id,
                            $prev_id,
                            $existing_post_by_prev->ID
                        ));

                        return $this->update_existing_article($article_table_id, $article, $existing_post_by_prev, false, $skip_image_downloads);
                    }
                }
            }
        }

        // DUPLICATE PREVENTION (title-based): Fallback check for posts without _kodanote_article_id meta
        // Excludes trashed posts so articles can be republished after user deletes old copies
        $title_query = new WP_Query(array(
            'post_type'              => 'post',
            'title'                  => $article->title,
            'post_status'            => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ));
        $existing_post_by_title = !empty($title_query->posts) ? $title_query->posts[0] : null;
        wp_reset_postdata();
        
        if ($existing_post_by_title) {
            $wpdb->update(
                $table_name,
                array(
                    'post_id' => $existing_post_by_title->ID,
                    'status' => 'published',
                ),
                array('id' => $article_table_id),
                array('%d', '%s'),
                array('%d')
            );

            $this->log_debug(sprintf(
                'Article "%s" found existing WordPress post by title (ID: %d) - linking and updating content',
                $article->title,
                $existing_post_by_title->ID
            ));

            return $this->update_existing_article($article_table_id, $article, $existing_post_by_title, false, $skip_image_downloads);
        }

        // Get default settings
        $default_category = get_option('kodanote_post_category', 1);
        $default_author = get_option('kodanote_author_id', 0);
        
        // Ensure we have a valid author - fall back to first admin if not set
        // During cron execution, get_current_user_id() returns 0, which causes failures
        if (empty($default_author) || $default_author == 0) {
            // Get the first administrator as fallback
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $default_author = !empty($admins) ? $admins[0] : 1;
        }

        // Prepare post data
        // Explicitly set post_name (slug) to prevent WordPress from sometimes failing to auto-generate it
        // This ensures proper SEO-friendly URLs instead of fallback ?p=ID format.
        // Prefer the slug supplied by Kodanote (an English slug for non-Latin titles like
        // Traditional Chinese) so the permalink is readable instead of a URL-encoded title.
        // Keep WordPress post names portable: lowercase ASCII letters, digits, and hyphens only.
        $raw_slug = !empty($article->slug) ? $article->slug : $article->title;
        $post_slug = $this->sanitize_post_slug($raw_slug, 'article-' . absint($article->kodanote_id));

        $post_data = array(
            'post_title' => $article->title,
            'post_name' => $post_slug,
            'post_content' => $this->normalize_youtube_embed_markup($article->content),
            'post_excerpt' => $article->excerpt,
            'post_status' => 'publish',
            'post_author' => $default_author,
            'post_category' => array($default_category),
            'post_type' => 'post',
            'meta_input' => array(
                '_kodanote_article_id' => $article->kodanote_id,
                '_kodanote_managed' => '1',
                '_kodanote_webhook_sent' => (string) time(),
            ),
        );
        
        // Set the post date to the intended publication date from Kodanote
        // This ensures blog posts show the correct date instead of the sync time
        // If the date is in the future, cap to current time — WordPress auto-converts
        // post_status from 'publish' to 'future' for future dates, which breaks publishing.
        if (!empty($article->intended_published_at)) {
            $intended_gmt = $article->intended_published_at;
            $now_gmt = gmdate('Y-m-d H:i:s');
            if (strtotime($intended_gmt) > strtotime($now_gmt)) {
                $this->log_debug(sprintf(
                    'Intended date %s is in the future — using current time to avoid WordPress scheduling',
                    $intended_gmt
                ));
                $intended_gmt = $now_gmt;
            }
            $post_data['post_date_gmt'] = $intended_gmt;
            $post_data['post_date'] = get_date_from_gmt($intended_gmt);
            $this->log_debug(sprintf(
                'Setting post date from Kodanote: GMT=%s, Local=%s',
                $intended_gmt,
                $post_data['post_date']
            ));
        }

        // Handle hero image (preferred for featured image)
        // Skip downloads when images will be pushed separately by the server
        $featured_image_id = null;
        $hero_image_url = null;
        if (!$skip_image_downloads) {
            $hero_alt = !empty($article->hero_image_alt) ? $article->hero_image_alt : $article->title;
            if (!empty($article->hero_image_url)) {
                $hero_image_url = $article->hero_image_url;
                $featured_image_id = $this->download_and_attach_image(
                    $article->hero_image_url,
                    $article->title . ' - Hero Image',
                    null,
                    $hero_alt
                );
            } elseif (!empty($article->featured_image_url)) {
                $hero_image_url = $article->featured_image_url;
                $featured_image_id = $this->download_and_attach_image(
                    $article->featured_image_url,
                    $article->title . ' - Featured Image',
                    null,
                    $hero_alt
                );
            }
        } else {
            $this->log_debug('Skipping hero image download - images will be pushed separately by server');
        }

        // Insert the post - pass true to return WP_Error on failure
        Kodanote_Plugin::allow_content_updates();
        try {
            $post_id = wp_insert_post($post_data, true);
        } finally {
            Kodanote_Plugin::disallow_content_updates();
        }

        if (is_wp_error($post_id)) {
            $this->log_debug('Failed to create WordPress post: ' . $post_id->get_error_message());
            return $post_id;
        }
        
        // Also check for 0 return (shouldn't happen with true param, but be safe)
        if (empty($post_id)) {
            $this->log_debug('Failed to create WordPress post: wp_insert_post returned empty');
            return new WP_Error('insert_failed', __('Failed to create WordPress post', 'kodanote-content-publisher'));
        }

        // Some themes/plugins auto-inject page-builder meta on wp_insert_post hooks.
        // Clean it immediately so the post renders via standard post_content.
        $this->clear_page_builder_meta($post_id);

        // Assign the post's translation language (WPML or Polylang) and, when the
        // source article is known, link it to that translation group so
        // multilingual URLs (/de/, /fr/, …) and hreflang/language switchers work.
        if (!empty($article->language)) {
            $source_article_id = isset($article->source_article_id) ? $article->source_article_id : null;
            $this->set_post_language($post_id, $article->language, $source_article_id);
        }

        // Set featured image if we have one
        if ($featured_image_id) {
            set_post_thumbnail($post_id, $featured_image_id);
            if ($hero_image_url) {
                update_post_meta($post_id, '_kodanote_hero_image_url', $hero_image_url);
            }
            update_post_meta($post_id, '_kodanote_hero_attachment_id', $featured_image_id);
        }

        // Handle infographic image (download and attach to post)
        if (!$skip_image_downloads && !empty($article->infographic_image_url)) {
            $infographic_image_id = $this->download_and_attach_image(
                $article->infographic_image_url,
                $article->title . ' - Infographic',
                $post_id,
                $article->title
            );
            
            if ($infographic_image_id) {
                update_post_meta($post_id, '_kodanote_infographic_image_id', $infographic_image_id);
                update_post_meta($post_id, '_kodanote_infographic_image_url', $article->infographic_image_url);
            }
        } elseif ($skip_image_downloads && !empty($article->infographic_image_url)) {
            $this->log_debug('Skipping infographic image download - images will be pushed separately by server');
        }

        // Handle author box thumbnail — download once and replace URL in content
        if (!$skip_image_downloads) {
            $author_thumb_url = get_option('kodanote_author_box_remote_url', '');
            if (!empty($author_thumb_url)) {
                $this->handle_author_thumbnail($post_id, $author_thumb_url);
            }
        }

        // Store or clear infographic HTML as post meta
        if (!empty($article->infographic_html)) {
            update_post_meta($post_id, '_kodanote_infographic_html', $article->infographic_html);
            $this->bake_infographic_into_content($post_id);
        } else {
            $this->strip_infographic_from_content($post_id);
        }

        // Store keywords as post meta
        if (!empty($article->keywords)) {
            $keywords_array = explode(',', $article->keywords);
            $keywords_array = array_map('trim', $keywords_array);
            update_post_meta($post_id, '_kodanote_keywords', $keywords_array);
        }

        // Store markdown content for LLM-friendly .md URLs
        if (!empty($article->content_markdown)) {
            update_post_meta($post_id, '_kodanote_content_markdown', $article->content_markdown);
        }

        // Store meta description and keywords for SEO
        $this->set_seo_meta_fields($post_id, $article);

        // Set WordPress tags
        $this->set_wordpress_tags($post_id, $article);

        $this->sync_content_to_acf_fields($post_id);

        // Update sync table with post_id and published status
        $wpdb->update(
            $table_name,
            array(
                'post_id' => $post_id,
                'status' => 'published',
                'published_at' => current_time('mysql'),
            ),
            array('id' => $article_table_id),
            array('%d', '%s', '%s'),
            array('%d')
        );

        // Get the published URL (handles future/scheduled posts correctly)
        $published_url = $this->get_published_url($post_id);

        // Refresh the webhook_sent timestamp (was initially set via meta_input before
        // wp_insert_post hooks fired, to prevent transition_post_status duplicates)
        update_post_meta($post_id, '_kodanote_webhook_sent', (string) time());

        $webhook_data = array(
            'article_id' => $article->kodanote_id,
            'wordpress_post_id' => $post_id,
            'published_url' => $published_url,
        );

        if (self::$batch_mode) {
            self::$batched_webhooks[] = $webhook_data;
            update_post_meta($post_id, '_kodanote_last_reported_url', esc_url_raw($published_url));
        } else {
            $api = new Kodanote_API();
            $result = $api->send_webhook('article_published', $webhook_data);
            if (!is_wp_error($result)) {
                update_post_meta($post_id, '_kodanote_last_reported_url', esc_url_raw($published_url));
            }
        }

        // Enfold and similar themes can attach empty ALB meta during save_post hooks.
        $this->clear_page_builder_meta($post_id);

        $this->log_debug(sprintf(
            'Article "%s" published successfully (Post ID: %d, URL: %s, webhook: %s)',
            $article->title,
            $post_id,
            $published_url,
            self::$batch_mode ? 'batched' : 'sent'
        ));

        return array(
            'success' => true,
            'message' => __('Article published successfully', 'kodanote-content-publisher'),
            'post_id' => $post_id,
            'published_url' => $published_url,
        );
    }

    /**
     * Bake infographic image directly into post_content so it doesn't depend
     * on the_content filter injection (which some themes/page-builders break).
     *
     * The the_content filter (inject_infographic_image_into_content) remains as
     * a backward-compatible fallback — it checks for 'kodanote-infographic-container'
     * in the content and skips if already present.
     */
    public function bake_infographic_into_content($post_id) {
        $infographic_image_id = get_post_meta($post_id, '_kodanote_infographic_image_id', true);
        if (empty($infographic_image_id)) {
            return;
        }

        if (!wp_get_attachment_url($infographic_image_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return;
        }

        $content = $post->post_content;

        // Strip any previously baked infographic so we can re-position it
        // if the article content changed during a sync update.
        // Try comment-wrapped version first (robust against nested divs from
        // lazy-loading plugins), then fall back to bare-div regex.
        $content = preg_replace(
            '/<!-- kodanote-infographic -->.*?<!-- \/kodanote-infographic -->\s*/s',
            '',
            $content
        );
        $content = preg_replace(
            '/<div class="kodanote-infographic-container">.*?<\/div>\s*/s',
            '',
            $content
        );

        // Safety: if the class still appears after stripping (e.g. nested-div
        // edge case), skip injection to avoid duplicates.
        if (strpos($content, 'kodanote-infographic-container') !== false) {
            $this->log_debug(sprintf(
                'Skipped baking infographic for post %d - container class still present after strip',
                $post_id
            ));
            return;
        }

        $infographic_alt = get_post_meta($infographic_image_id, '_wp_attachment_image_alt', true);
        if (empty($infographic_alt)) {
            $infographic_alt = $post->post_title;
        }

        $infographic_html = wp_get_attachment_image(
            $infographic_image_id,
            'full',
            false,
            $this->get_infographic_image_attributes($infographic_alt)
        );

        if (empty($infographic_html)) {
            return;
        }

        // Comment markers make future stripping reliable regardless of
        // nested HTML from lazy-loading or image-optimization plugins.
        $infographic_block = '<!-- kodanote-infographic -->'
            . '<div class="kodanote-infographic-container">' . $infographic_html . '</div>'
            . '<!-- /kodanote-infographic -->';

        $content = $this->insert_block_at_preferred_article_position($content, $infographic_block);

        Kodanote_Plugin::allow_content_updates();
        try {
            wp_update_post(array(
                'ID'           => $post_id,
                'post_content' => $content,
            ));
        } finally {
            Kodanote_Plugin::disallow_content_updates();
        }

        $this->log_debug(sprintf('Baked infographic into post_content for post %d', $post_id));
    }

    /**
     * Attributes that keep infographic images out of theme/plugin lazy loaders.
     */
    private function get_infographic_image_attributes($alt_text) {
        return array(
            'class'          => 'kodanote-infographic-image skip-lazy no-lazy',
            'alt'            => $alt_text,
            'loading'        => 'eager',
            'decoding'       => 'async',
            'data-no-lazy'   => '1',
            'data-skip-lazy' => '1',
        );
    }

    /**
     * Normalize YouTube iframe markup before storing it in post_content.
     */
    private function normalize_youtube_embed_markup($content) {
        if (stripos($content, 'youtube') === false) {
            return $content;
        }

        $blocks = array();
        $content = preg_replace_callback(
            '/<div\b[^>]*class=["\'][^"\']*\byoutube-embed\b[^"\']*["\'][^>]*>.*?<\/div>/is',
            function($matches) use (&$blocks) {
                $placeholder = '%%KODANOTE_YOUTUBE_EMBED_' . count($blocks) . '%%';
                $blocks[$placeholder] = $this->build_responsive_youtube_embed_from_html($matches[0]);
                return $placeholder;
            },
            $content
        );

        $content = preg_replace_callback(
            '/<iframe\b[^>]*src=["\']https?:\/\/(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*>.*?<\/iframe>/is',
            function($matches) {
                return $this->build_responsive_youtube_embed_from_html($matches[0]);
            },
            $content
        );

        return strtr($content, $blocks);
    }

    private function build_responsive_youtube_embed_from_html($html) {
        if (!preg_match('/src=["\']([^"\']*youtube(?:-nocookie)?\.com\/embed\/[^"\']*)["\']/i', $html, $src_match)) {
            return $html;
        }

        $title = 'Related Video';
        if (preg_match('/title=["\']([^"\']*)["\']/i', $html, $title_match)) {
            $title = html_entity_decode($title_match[1], ENT_QUOTES, 'UTF-8');
        }

        return $this->build_responsive_youtube_embed(
            $this->normalize_youtube_embed_url(html_entity_decode($src_match[1], ENT_QUOTES, 'UTF-8')),
            $title
        );
    }

    private function normalize_youtube_embed_url($src) {
        $src = trim(preg_replace('/^\/\//', 'https://', $src));

        if (!preg_match('/youtube(?:-nocookie)?\.com\/embed\/([a-zA-Z0-9_-]{11})/i', $src, $id_match)) {
            return $src;
        }

        $query = wp_parse_url($src, PHP_URL_QUERY);
        $params = array();
        if (!empty($query)) {
            wp_parse_str($query, $params);
        }
        if (empty($params['rel'])) {
            $params['rel'] = '0';
        }

        return 'https://www.youtube.com/embed/' . $id_match[1] . '?' . http_build_query($params, '', '&');
    }

    private function build_responsive_youtube_embed($src, $title) {
        return '<div class="youtube-embed" style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%; margin: 1.5em 0;">'
            . '<iframe src="' . esc_url($src) . '" title="' . esc_attr($title ?: 'Related Video') . '" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>'
            . '</div>';
    }

    private function insert_block_at_preferred_article_position($content, $block) {
        $insert_position = $this->find_preferred_article_insert_position($content);
        $block = rtrim($block) . "\n\n";

        if ($insert_position === null) {
            return rtrim($content) . "\n\n" . rtrim($block);
        }

        return substr($content, 0, $insert_position) . $block . substr($content, $insert_position);
    }

    private function find_preferred_article_insert_position($content) {
        $content_length = strlen($content);
        if ($content_length === 0) {
            return null;
        }

        foreach (array('h2', 'h3') as $tag) {
            $headings = $this->get_heading_matches($content, $tag);
            $candidates = array_values(array_filter($headings, function($heading) {
                return !$this->is_non_body_heading($heading['text']);
            }));

            if (!empty($candidates)) {
                $target = $content_length * 0.5;
                usort($candidates, function($a, $b) use ($target) {
                    return abs($a['offset'] - $target) <=> abs($b['offset'] - $target);
                });

                return $candidates[0]['offset'];
            }
        }

        return $this->fallback_paragraph_insert_position($content, $content_length);
    }

    private function get_heading_matches($content, $tag) {
        preg_match_all('/<' . $tag . '[^>]*>(.*?)<\/' . $tag . '>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        $headings = array();

        foreach (($matches[0] ?? array()) as $match) {
            $headings[] = array(
                'offset' => $match[1],
                'text'   => trim(html_entity_decode(wp_strip_all_tags($match[0]), ENT_QUOTES, 'UTF-8')),
            );
        }

        return $headings;
    }

    private function is_non_body_heading($heading) {
        return preg_match('/\b(key\s*takeaways?|table\s*of\s*contents?|faq|frequently\s*asked|summary|conclusion|final\s*thoughts?|recap|wrap\s*up|references?|sources?)\b/i', $heading) === 1;
    }

    private function fallback_paragraph_insert_position($content, $content_length) {
        preg_match_all('/<\/p>/i', $content, $paragraphs, PREG_OFFSET_CAPTURE);
        if (empty($paragraphs[0])) {
            return null;
        }

        $target = (int) floor($content_length * 0.45);
        foreach ($paragraphs[0] as $paragraph) {
            $position = $paragraph[1] + strlen($paragraph[0]);
            if ($position >= $target) {
                return $position;
            }
        }

        $last = end($paragraphs[0]);
        return $last ? $last[1] + strlen($last[0]) : null;
    }

    /**
     * Remove any previously baked infographic from post_content and clear
     * related post meta. Called when the server sends null infographic data
     * (user disabled infographics on the site).
     */
    public function strip_infographic_from_content($post_id) {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return;
        }

        $content = $post->post_content;
        $original = $content;

        $content = preg_replace(
            '/<!-- kodanote-infographic -->.*?<!-- \/kodanote-infographic -->\s*/s',
            '',
            $content
        );
        $content = preg_replace(
            '/<div class="kodanote-infographic-container">.*?<\/div>\s*/s',
            '',
            $content
        );

        if ($content !== $original) {
            Kodanote_Plugin::allow_content_updates();
            try {
                wp_update_post(array(
                    'ID'           => $post_id,
                    'post_content' => $content,
                ));
            } finally {
                Kodanote_Plugin::disallow_content_updates();
            }
            $this->log_debug(sprintf('Stripped infographic from post_content for post %d', $post_id));
        }

        delete_post_meta($post_id, '_kodanote_infographic_html');
        delete_post_meta($post_id, '_kodanote_infographic_image_id');
        delete_post_meta($post_id, '_kodanote_infographic_image_url');
    }

    /**
     * Download and attach an image from URL
     * 
     * @param string $image_url URL of the image
     * @param string $title Title for the attachment
     * @param int|null $post_id Post ID to attach to (optional)
     * @return int|false Attachment ID or false on failure
     */
    private function download_and_attach_image($image_url, $title = '', $post_id = null, $alt_text = '') {
        if (empty($image_url)) {
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Download the image
        $tmp_file = download_url($image_url);

        if (is_wp_error($tmp_file)) {
            $this->log_debug('Failed to download image: ' . $tmp_file->get_error_message());
            return false;
        }

        // Get file extension from URL
        $file_ext = pathinfo(wp_parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($file_ext)) {
            $file_ext = 'jpg'; // Default to jpg
        }

        // Prepare file array
        $file_array = array(
            'name' => sanitize_file_name($title . '.' . $file_ext),
            'tmp_name' => $tmp_file,
        );

        // Check for download errors
        if (!file_exists($file_array['tmp_name'])) {
            wp_delete_file($file_array['tmp_name']);
            $this->log_debug('Downloaded image file does not exist');
            return false;
        }

        // Upload the image to WordPress media library
        $attachment_id = media_handle_sideload($file_array, $post_id, $title);

        // Check for handle sideload errors
        if (is_wp_error($attachment_id)) {
            wp_delete_file($file_array['tmp_name']);
            $this->log_debug('Failed to sideload image: ' . $attachment_id->get_error_message());
            return false;
        }

        // Set alt text on the attachment
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        return $attachment_id;
    }

    /**
     * Handle author box thumbnail: download once to WP media library,
     * store in options, and replace the remote URL in post content with the local one.
     * Only re-downloads if the source URL has changed.
     */
    private function handle_author_thumbnail($post_id, $remote_url) {
        $stored_source_url = get_option('kodanote_author_thumbnail_source_url', '');
        $stored_local_url = get_option('kodanote_author_thumbnail_local_url', '');
        $stored_attachment_id = get_option('kodanote_author_thumbnail_attachment_id', 0);

        $needs_download = false;

        if (empty($stored_source_url) || $remote_url !== $stored_source_url) {
            $needs_download = true;
        } elseif (!empty($stored_attachment_id) && !wp_get_attachment_url($stored_attachment_id)) {
            $needs_download = true;
            $this->log_debug('Author thumbnail attachment missing — will re-download');
        }

        if ($needs_download) {
            $attachment_id = $this->download_and_attach_image(
                $remote_url,
                'Author Thumbnail',
                null,
                'Author photo'
            );

            if ($attachment_id) {
                $local_url = wp_get_attachment_url($attachment_id);
                update_option('kodanote_author_thumbnail_source_url', $remote_url);
                update_option('kodanote_author_thumbnail_local_url', $local_url);
                update_option('kodanote_author_thumbnail_attachment_id', $attachment_id);
                $stored_local_url = $local_url;
                $this->log_debug(sprintf(
                    'Author thumbnail downloaded (attachment %d) — replacing URL in content',
                    $attachment_id
                ));
            } else {
                $this->log_debug('Failed to download author thumbnail — keeping remote URL');
                return;
            }
        }

        if (!empty($stored_local_url) && $stored_local_url !== $remote_url) {
            $post = get_post($post_id);
            if ($post && strpos($post->post_content, $remote_url) !== false) {
                $updated_content = str_replace($remote_url, $stored_local_url, $post->post_content);
                Kodanote_Plugin::allow_content_updates();
                try {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $updated_content,
                    ));
                } finally {
                    Kodanote_Plugin::disallow_content_updates();
                }
                $this->log_debug(sprintf(
                    'Replaced author thumbnail URL in post %d content',
                    $post_id
                ));
            }
        }
    }

    /**
     * Update an existing WordPress post with new article content
     * 
     * @param int $article_table_id ID from wp_kodanote_articles table
     * @param object $article Article data from sync table
     * @param WP_Post $existing_post Existing WordPress post
     * @param bool $skip_webhook Skip sending the article_published webhook (used during bulk sync to avoid timeouts)
     * @param bool $skip_image_downloads When true, skips outbound image downloads (images pushed separately by server)
     * @return array|WP_Error
     */
    public function update_existing_article($article_table_id, $article, $existing_post, $skip_webhook = false, $skip_image_downloads = false) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';
        
        // Validate inputs
        if (!$existing_post || !$existing_post->ID) {
            $this->log_debug('update_existing_article failed: Invalid existing_post object');
            return new WP_Error('invalid_post', __('Invalid existing post object', 'kodanote-content-publisher'));
        }
        
        if (empty($article->content)) {
            $this->log_debug('update_existing_article failed: Article content is empty');
            return new WP_Error('empty_content', __('Article content is empty', 'kodanote-content-publisher'));
        }
        
        // Get the author - keep existing author, with fallback to first admin
        $post_author = $existing_post->post_author;
        if (empty($post_author) || $post_author == 0) {
            // Get first administrator as fallback
            $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
            $post_author = !empty($admins) ? $admins[0] : 1;
            $this->log_debug(sprintf(
                'Post %d has invalid author, using fallback author ID: %d',
                $existing_post->ID,
                $post_author
            ));
        }

        // Don't set post_category on update - preserve whatever category the user has chosen
        $post_data = array(
            'ID' => $existing_post->ID,
            'post_title' => !empty($article->title) ? $article->title : $existing_post->post_title,
            'post_content' => $this->normalize_youtube_embed_markup($article->content),
            'post_excerpt' => isset($article->excerpt) ? $article->excerpt : '',
            'post_status' => 'publish',
            'post_author' => $post_author,
            'meta_input' => array(
                '_kodanote_article_id' => $article->kodanote_id,
                '_kodanote_managed' => '1',
            ),
        );

        // Update post date if intended_published_at is provided and differs from current.
        // Cap to current time if in the future — WordPress auto-converts post_status
        // from 'publish' to 'future' for future dates, which breaks force-republish.
        if (!empty($article->intended_published_at)) {
            $new_gmt = $article->intended_published_at;
            $now_gmt = gmdate('Y-m-d H:i:s');
            if (strtotime($new_gmt) > strtotime($now_gmt)) {
                $this->log_debug(sprintf(
                    'Intended date %s is in the future for post %d — using current time to avoid WordPress scheduling',
                    $new_gmt,
                    $existing_post->ID
                ));
                $new_gmt = $now_gmt;
            }
            $current_gmt = $existing_post->post_date_gmt;
            if ($new_gmt !== $current_gmt) {
                $post_data['post_date_gmt'] = $new_gmt;
                $post_data['post_date'] = get_date_from_gmt($new_gmt);
                $this->log_debug(sprintf(
                    'Updating post date for post %d: old GMT=%s, new GMT=%s, new local=%s',
                    $existing_post->ID,
                    $current_gmt,
                    $new_gmt,
                    $post_data['post_date']
                ));
            }
        }

        // Slug policy: keep the existing slug for normal content updates to
        // preserve SEO equity and avoid breaking bookmarked links. However, when
        // a replacement article (rewrite with new topic) takes over this post,
        // the old slug no longer reflects the content and must be updated.
        // $skip_webhook === false means the API flagged needs_url_confirmation,
        // i.e. this article has never had a confirmed published URL — it is a
        // new version claiming an existing post via previous_article_ids or
        // title-based duplicate prevention.
        if (!$skip_webhook && !empty($article->slug)) {
            $new_slug = $this->sanitize_post_slug($article->slug);

            if ($new_slug !== '' && $new_slug !== $existing_post->post_name) {
                $post_data['post_name'] = $new_slug;
                $this->log_debug(sprintf(
                    'Updating slug for post %d: "%s" → "%s" (replacement article %s needs URL confirmation)',
                    $existing_post->ID,
                    $existing_post->post_name,
                    $new_slug,
                    $article->kodanote_id
                ));
            }
        }

        // If the user has edited this post with a page builder (Elementor, Divi,
        // etc.) or manually changed the WordPress body since we last published
        // it, respect their changes: skip the content update and keep user
        // authored body content intact.
        $page_builder = self::has_page_builder_content($existing_post->ID);
        $manual_content_override = get_post_meta($existing_post->ID, '_kodanote_manual_content_override', true);
        $force_content_update = !empty($article->force_content_update);
        $force_page_builder_content_update = !empty($article->force_page_builder_content_update);

        if ($force_page_builder_content_update && $page_builder) {
            $this->clear_page_builder_meta($existing_post->ID);
            $this->log_debug(sprintf(
                'Post %d page-builder metadata cleared for explicit Kodanote force republish',
                $existing_post->ID
            ));
            $page_builder = false;
        }

        if ($force_content_update && $manual_content_override && !$page_builder) {
            delete_post_meta($existing_post->ID, '_kodanote_manual_content_override');
            delete_post_meta($existing_post->ID, '_kodanote_manual_content_override_at');
            $manual_content_override = false;
            $this->log_debug(sprintf(
                'Post %d manual content override cleared for forced Kodanote dashboard update',
                $existing_post->ID
            ));
        }

        if ($page_builder || $manual_content_override) {
            // Only update title and meta — leave post_content and post_excerpt
            // untouched. We avoid wp_update_post here because it merges with
            // the existing row and re-runs content_save_pre (KSES), which can
            // degrade user-authored HTML over many syncs.
            $title = !empty($article->title) ? $article->title : $existing_post->post_title;
            $wpdb->update(
                $wpdb->posts,
                array('post_title' => $title, 'post_author' => $post_author, 'post_status' => 'publish'),
                array('ID' => $existing_post->ID),
                array('%s', '%d', '%s'),
                array('%d')
            );
            clean_post_cache($existing_post->ID);
            update_post_meta($existing_post->ID, '_kodanote_article_id', $article->kodanote_id);
            update_post_meta($existing_post->ID, '_kodanote_managed', '1');

            // A user explicitly applying a changed author box must also work on
            // Divi (and other builder) posts. Merge only the server-generated
            // author-box fragment directly into the stored builder content, so
            // the rest of the customer's layout remains untouched.
            if ($force_content_update && $page_builder && !$manual_content_override) {
                $this->sync_author_box_into_page_builder_content(
                    $existing_post->ID,
                    $existing_post->post_content,
                    $article->content
                );
            }

            $this->log_debug(sprintf(
                'Post %d has %s edits — preserving user content, updating title + metadata only',
                $existing_post->ID,
                $page_builder ? $page_builder : 'manual WordPress content'
            ));
        } else {
            $this->clear_page_builder_meta($existing_post->ID);
            $this->log_debug(sprintf(
                'Updating WordPress post %d with content length: %d bytes',
                $existing_post->ID,
                strlen($article->content)
            ));

            Kodanote_Plugin::allow_content_updates();
            try {
                $result = wp_update_post($post_data, true);
            } finally {
                Kodanote_Plugin::disallow_content_updates();
            }

            if (is_wp_error($result)) {
                $this->log_debug('Failed to update WordPress post: ' . $result->get_error_message());
                return $result;
            }
            
            if (empty($result) || $result === 0) {
                $this->log_debug('wp_update_post returned empty/zero for post ' . $existing_post->ID);
                return new WP_Error('update_failed', __('WordPress post update returned invalid result', 'kodanote-content-publisher'));
            }

            // Verify the post is actually published in the DB after the update.
            // wp_update_post can return the ID even when the underlying UPDATE
            // affected 0 rows (phantom post scenario).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $verify_status = $wpdb->get_var($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                $result
            ));
            if ($verify_status !== 'publish') {
                $this->log_debug(sprintf(
                    'Post %d update appeared to succeed but post_status is "%s" (expected "publish") — post may be phantom',
                    $result,
                    $verify_status ?: 'NOT FOUND'
                ));
                return new WP_Error('phantom_post', sprintf(
                    __('Post %d does not exist or is not published after update (status: %s)', 'kodanote-content-publisher'),
                    $result,
                    $verify_status ?: 'not found'
                ));
            }
        }
        
        $this->log_debug(sprintf(
            'Successfully updated WordPress post %d',
            $existing_post->ID
        ));

        // Assign the post's translation language (WPML or Polylang) and, when the
        // source article is known, link it to that translation group so
        // multilingual URLs (/de/, /fr/, …) and hreflang/language switchers work.
        if (!empty($article->language)) {
            $source_article_id = isset($article->source_article_id) ? $article->source_article_id : null;
            $this->set_post_language($existing_post->ID, $article->language, $source_article_id);
        }

        // Handle hero/featured image - ONLY download if URL has ACTUALLY changed
        // When $skip_image_downloads is true, images are pushed separately by the server
        if (!$skip_image_downloads) {
            $current_hero_url = get_post_meta($existing_post->ID, '_kodanote_hero_image_url', true);
            $new_hero_url = !empty($article->hero_image_url) ? $article->hero_image_url : $article->featured_image_url;
            $has_featured_image = has_post_thumbnail($existing_post->ID);
            
            // Validate that the featured image attachment actually exists (file not deleted)
            if ($has_featured_image) {
                $thumbnail_id = get_post_thumbnail_id($existing_post->ID);
                if ($thumbnail_id && !wp_get_attachment_url($thumbnail_id)) {
                    $has_featured_image = false;
                    delete_post_meta($existing_post->ID, '_thumbnail_id');
                    delete_post_meta($existing_post->ID, '_kodanote_hero_image_url');
                    delete_post_meta($existing_post->ID, '_kodanote_hero_attachment_id');
                    $current_hero_url = '';
                    $this->log_debug(sprintf(
                        'Hero image attachment %d for post %d is missing/deleted - will re-download',
                        $thumbnail_id,
                        $existing_post->ID
                    ));
                }
            }
            
            $should_download_hero = false;
            
            if (!empty($new_hero_url)) {
                if (!$has_featured_image) {
                    $should_download_hero = true;
                    $this->log_debug(sprintf(
                        'Hero image download needed for post %d - no featured image exists',
                        $existing_post->ID
                    ));
                } elseif (!empty($current_hero_url) && $new_hero_url !== $current_hero_url) {
                    $should_download_hero = true;
                    $this->log_debug(sprintf(
                        'Hero image download needed for post %d - URL changed from "%s" to "%s"',
                        $existing_post->ID,
                        $current_hero_url,
                        $new_hero_url
                    ));
                } elseif (empty($current_hero_url) && $has_featured_image) {
                    // Post has a featured image but no Kodanote tracking URL.
                    // The existing thumbnail may have been set by the WordPress theme,
                    // another plugin, or from a pre-existing post matched by title —
                    // not necessarily the correct Kodanote hero image.
                    // Re-download to ensure the correct hero image is used.
                    $should_download_hero = true;
                    $this->log_debug(sprintf(
                        'Hero image download needed for post %d - featured image exists but no Kodanote tracking URL (ensuring correct hero image)',
                        $existing_post->ID
                    ));
                } else {
                    // URL unchanged — verify the thumbnail was actually set by Kodanote.
                    $kodanote_attachment_id = get_post_meta($existing_post->ID, '_kodanote_hero_attachment_id', true);
                    $current_thumbnail_id = get_post_thumbnail_id($existing_post->ID);
                    if (empty($kodanote_attachment_id)) {
                        // Legacy post — no tracking yet (pre-fix). Re-download once to
                        // ensure the thumbnail is actually our hero image and set tracking.
                        $should_download_hero = true;
                        $this->log_debug(sprintf(
                            'Hero image re-download needed for post %d - URL tracked but no verified attachment (legacy post)',
                            $existing_post->ID
                        ));
                    } elseif ((int) $kodanote_attachment_id !== (int) $current_thumbnail_id) {
                        // Kodanote set the thumbnail, but it was changed afterward
                        // (user manually changed it in WP or a plugin swapped it).
                        // Respect the change — update tracking to match current thumbnail.
                        update_post_meta($existing_post->ID, '_kodanote_hero_attachment_id', $current_thumbnail_id);
                        $this->log_debug(sprintf(
                            'Hero image thumbnail changed externally for post %d - respecting change (was: %s, now: %s)',
                            $existing_post->ID,
                            $kodanote_attachment_id,
                            $current_thumbnail_id
                        ));
                    } else {
                        $this->log_debug(sprintf(
                            'Skipping hero image download for post %d - URL unchanged and thumbnail verified ("%s")',
                            $existing_post->ID,
                            $new_hero_url
                        ));
                    }
                }
            }
            
            if ($should_download_hero) {
                $hero_alt = !empty($article->hero_image_alt) ? $article->hero_image_alt : $article->title;
                $featured_image_id = $this->download_and_attach_image(
                    $new_hero_url,
                    $article->title . ' - Hero Image',
                    null,
                    $hero_alt
                );
                
                if ($featured_image_id) {
                    set_post_thumbnail($existing_post->ID, $featured_image_id);
                    update_post_meta($existing_post->ID, '_kodanote_hero_image_url', $new_hero_url);
                    update_post_meta($existing_post->ID, '_kodanote_hero_attachment_id', $featured_image_id);
                }
            } else {
                // Even when not re-downloading, update alt text on existing attachment
                $hero_alt = !empty($article->hero_image_alt) ? $article->hero_image_alt : $article->title;
                $thumbnail_id = get_post_thumbnail_id($existing_post->ID);
                if ($thumbnail_id && !empty($hero_alt)) {
                    $existing_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
                    if (empty($existing_alt)) {
                        update_post_meta($thumbnail_id, '_wp_attachment_image_alt', sanitize_text_field($hero_alt));
                    }
                }
            }
        } else {
            $this->log_debug(sprintf(
                'Skipping hero image download for post %d - images will be pushed separately by server',
                $existing_post->ID
            ));
        }

        // Handle infographic image - ONLY download if URL has ACTUALLY changed
        if (!$skip_image_downloads) {
        $current_infographic_url = get_post_meta($existing_post->ID, '_kodanote_infographic_image_url', true);
        $current_infographic_id = get_post_meta($existing_post->ID, '_kodanote_infographic_image_id', true);
        
        // Validate that the infographic attachment actually exists (file not deleted)
        if (!empty($current_infographic_id) && !wp_get_attachment_url($current_infographic_id)) {
            $this->log_debug(sprintf(
                'Infographic attachment %d for post %d is missing/deleted - will re-download',
                $current_infographic_id,
                $existing_post->ID
            ));
            delete_post_meta($existing_post->ID, '_kodanote_infographic_image_id');
            delete_post_meta($existing_post->ID, '_kodanote_infographic_image_url');
            $current_infographic_id = '';
            $current_infographic_url = '';
        }
        
        // Determine if we should download the infographic
        $should_download_infographic = false;
        
        if (!empty($article->infographic_image_url)) {
            if (empty($current_infographic_id)) {
                // No infographic exists yet - download it
                $should_download_infographic = true;
                $this->log_debug(sprintf(
                    'Infographic download needed for post %d - no infographic exists',
                    $existing_post->ID
                ));
            } elseif (!empty($current_infographic_url) && $article->infographic_image_url !== $current_infographic_url) {
                // URL has changed from a KNOWN previous value - re-download
                $should_download_infographic = true;
                $this->log_debug(sprintf(
                    'Infographic download needed for post %d - URL changed',
                    $existing_post->ID
                ));
            } elseif (empty($current_infographic_url) && !empty($current_infographic_id)) {
                // Infographic exists but URL meta doesn't (pre-v1.3.5 post)
                // DON'T download, just store the URL
                update_post_meta($existing_post->ID, '_kodanote_infographic_image_url', $article->infographic_image_url);
                $this->log_debug(sprintf(
                    'Skipping infographic download for post %d - infographic exists (pre-v1.3.5 post), storing URL for future tracking',
                    $existing_post->ID
                ));
            } else {
                $this->log_debug(sprintf(
                    'Skipping infographic download for post %d - URL unchanged',
                    $existing_post->ID
                ));
            }
        }
        
        if ($should_download_infographic) {
            $infographic_image_id = $this->download_and_attach_image(
                $article->infographic_image_url,
                $article->title . ' - Infographic',
                $existing_post->ID,
                $article->title
            );
            
            if ($infographic_image_id) {
                update_post_meta($existing_post->ID, '_kodanote_infographic_image_id', $infographic_image_id);
                update_post_meta($existing_post->ID, '_kodanote_infographic_image_url', $article->infographic_image_url);
            }
        }
        } else {
            $this->log_debug(sprintf(
                'Skipping infographic image download for post %d - images will be pushed separately by server',
                $existing_post->ID
            ));
        }

        // Skip content-modifying operations when user has page-builder or
        // manual WordPress body edits.
        if (!$page_builder && !$manual_content_override) {
            // Handle author box thumbnail — download once and replace URL in content
            if (!$skip_image_downloads) {
                $author_thumb_url = get_option('kodanote_author_box_remote_url', '');
                if (!empty($author_thumb_url)) {
                    $this->handle_author_thumbnail($existing_post->ID, $author_thumb_url);
                }
            }

            // Update or clear infographic HTML
            if (!empty($article->infographic_html)) {
                update_post_meta($existing_post->ID, '_kodanote_infographic_html', $article->infographic_html);
                $this->bake_infographic_into_content($existing_post->ID);
            } else {
                $this->strip_infographic_from_content($existing_post->ID);
            }
        }

        // Update keywords
        if (!empty($article->keywords)) {
            $keywords_array = explode(',', $article->keywords);
            $keywords_array = array_map('trim', $keywords_array);
            update_post_meta($existing_post->ID, '_kodanote_keywords', $keywords_array);
        }

        // Update markdown content for LLM-friendly .md URLs
        if (!empty($article->content_markdown)) {
            update_post_meta($existing_post->ID, '_kodanote_content_markdown', $article->content_markdown);
        }

        // Update meta description and keywords for SEO
        $this->set_seo_meta_fields($existing_post->ID, $article);

        // Update WordPress tags
        $this->set_wordpress_tags($existing_post->ID, $article);

        if (!$page_builder && !$manual_content_override) {
            $this->sync_content_to_acf_fields($existing_post->ID);
        }

        // Update sync table
        $wpdb->update(
            $table_name,
            array(
                'status' => 'published',
                'published_at' => current_time('mysql'),
            ),
            array('id' => $article_table_id),
            array('%s', '%s'),
            array('%d')
        );

        $published_url = $this->get_published_url($existing_post->ID);

        if (!$skip_webhook) {
            $webhook_data = array(
                'article_id' => $article->kodanote_id,
                'wordpress_post_id' => $existing_post->ID,
                'published_url' => $published_url,
            );

            if (self::$batch_mode) {
                self::$batched_webhooks[] = $webhook_data;
                update_post_meta($existing_post->ID, '_kodanote_last_reported_url', esc_url_raw($published_url));
            } else {
                $api = new Kodanote_API();
                $result = $api->send_webhook('article_published', $webhook_data);
                if (!is_wp_error($result)) {
                    update_post_meta($existing_post->ID, '_kodanote_last_reported_url', esc_url_raw($published_url));
                }
            }
        }

        // Enfold and similar themes can re-attach empty ALB meta during the
        // save_post hooks fired by wp_update_post / bake_infographic above.
        // Only clear when we actually managed the content — never when we are
        // intentionally preserving a user's page-builder or manual edits.
        if (!$page_builder && !$manual_content_override) {
            $this->clear_page_builder_meta($existing_post->ID);
        }

        $this->log_debug(sprintf(
            'Article "%s" updated successfully (Post ID: %d, URL: %s, webhook: %s)',
            $article->title,
            $existing_post->ID,
            $published_url,
            $skip_webhook ? 'skipped' : (self::$batch_mode ? 'batched' : 'sent')
        ));

        return array(
            'success' => true,
            'message' => __('Article updated successfully', 'kodanote-content-publisher'),
            'post_id' => $existing_post->ID,
            'published_url' => $published_url,
            'action' => 'updated',
        );
    }

    /**
     * Publish all pending articles
     * 
     * @param int $limit Maximum number of articles to publish (default: 10)
     * @return array
     */
    public function publish_pending_articles($limit = 10) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';
        
        // Get pending articles
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $pending_articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY synced_at ASC LIMIT %d",
            'pending',
            $limit
        ));

        $published_count = 0;
        $errors = array();

        self::start_batch();

        try {
            foreach ($pending_articles as $article) {
                $result = $this->publish_article($article->id);
                
                if (!is_wp_error($result)) {
                    $published_count++;
                } else {
                    $errors[] = sprintf(
                        /* translators: 1: article title, 2: error message */
                        __('Failed to publish "%1$s": %2$s', 'kodanote-content-publisher'),
                        $article->title,
                        $result->get_error_message()
                    );
                }
            }
        } finally {
            $batched_webhooks = self::end_batch();
        }

        if (!empty($batched_webhooks)) {
            $api = new Kodanote_API();
            $api->send_webhook('articles_batch_published', array(
                'articles' => $batched_webhooks,
            ));
        }

        return array(
            'success' => true,
            'published_count' => $published_count,
            'errors' => $errors,
        );
    }

    /**
     * Get the published URL for a post, ensuring pretty permalink even for scheduled/future posts.
     * WordPress's get_permalink() returns ?p=ID format for posts with 'future' status,
     * which happens when a post is created with post_status='publish' but a future post_date.
     *
     * @param int $post_id
     * @return string
     */
    private function get_published_url($post_id) {
        $published_url = get_permalink($post_id);

        if (strpos($published_url, '?p=') !== false || strpos($published_url, '?page_id=') !== false) {
            if (get_option('permalink_structure')) {
                $post_obj = get_post($post_id);
                if ($post_obj && !empty($post_obj->post_name)) {
                    $original_status = $post_obj->post_status;
                    $post_obj->post_status = 'publish';
                    $pretty_url = get_permalink($post_obj);
                    $post_obj->post_status = $original_status;

                    if (strpos($pretty_url, '?p=') === false && strpos($pretty_url, '?page_id=') === false) {
                        $this->log_debug(sprintf(
                            'Resolved pretty permalink for post %d (status: %s): %s -> %s',
                            $post_id, $original_status, $published_url, $pretty_url
                        ));
                        $published_url = $pretty_url;
                    }
                }
            }
        }

        return $published_url;
    }

    /**
     * Check whether a post has been edited with a page builder after Kodanote
     * published it.  Returns the builder name (truthy) or false.
     *
     * On initial publish the plugin clears page-builder meta, so any meta
     * that exists afterward was added by the user intentionally.
     *
     * @param int $post_id WordPress post ID
     * @return string|false Builder name or false
     */
    public static function has_page_builder_content($post_id) {
        // Elementor: check both the JSON data and the edit-mode flag.
        // _elementor_edit_mode is set early in Elementor's save flow (before
        // wp_update_post fires), so it catches the very first save reliably.
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data) && $elementor_data !== '[]') {
            return 'Elementor';
        }
        if (get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder') {
            return 'Elementor';
        }

        if (get_post_meta($post_id, '_et_pb_use_builder', true) === 'on') {
            return 'Divi';
        }

        if (get_post_meta($post_id, '_wpb_vc_js_status', true) === 'true') {
            return 'WPBakery';
        }

        $fl_data = get_post_meta($post_id, '_fl_builder_data', true);
        if (!empty($fl_data)) {
            return 'Beaver Builder';
        }

        if (get_post_meta($post_id, 'brizy_post_uid', true)) {
            return 'Brizy';
        }

        if (get_post_meta($post_id, 'ct_builder_shortcodes', true)) {
            return 'Oxygen';
        }

        // Enfold ALB: only treat as user-edited when actual layout data exists.
        // Enfold can flag _aviaLayoutBuilder_active without clean data, which renders
        // a blank page instead of falling back to post_content. Non-empty clean data
        // is the reliable signal of a real user layout (the active flag value varies
        // between Enfold versions), so key off that.
        $enfold_layout = get_post_meta($post_id, '_aviaLayoutBuilderCleanData', true);
        if (is_string($enfold_layout) && trim($enfold_layout) !== '') {
            return 'Enfold';
        }

        return false;
    }

    /**
     * Update only the Kodanote author box within a page-builder post.
     *
     * Page builders own the surrounding markup, so replacing the entire body
     * would discard customer layout changes. The incoming Kodanote payload is
     * the source of truth for this one generated fragment.
     */
    private function sync_author_box_into_page_builder_content($post_id, $existing_content, $incoming_content) {
        global $wpdb;

        $new_author_box = $this->extract_author_box_html($incoming_content);
        $updated_content = $this->replace_author_box_html($existing_content, $new_author_box);

        if ($updated_content === $existing_content) {
            return;
        }

        $wpdb->update(
            $wpdb->posts,
            array('post_content' => $updated_content),
            array('ID' => $post_id),
            array('%s'),
            array('%d')
        );
        clean_post_cache($post_id);

        $this->log_debug(sprintf(
            'Updated author box in page-builder content for post %d (%s)',
            $post_id,
            $new_author_box === '' ? 'removed' : 'applied'
        ));
    }

    /**
     * Extract the complete author-box div from trusted server-generated HTML.
     */
    private function extract_author_box_html($html) {
        if (!is_string($html) || strpos($html, 'author-box') === false) {
            return '';
        }

        $previous_errors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);

        if (!$loaded) {
            return '';
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " author-box ")]');
        $author_box = $nodes ? $nodes->item(0) : null;

        return $author_box ? trim($document->saveHTML($author_box)) : '';
    }

    /**
     * Replace an existing author-box div, or append the incoming one.
     */
    private function replace_author_box_html($content, $new_author_box) {
        if (!is_string($content)) {
            $content = '';
        }

        $pattern = '/<div\b(?=[^>]*\bclass\s*=\s*(["\'])[^"\']*\bauthor-box\b[^"\']*\1)[^>]*>/i';
        if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            return $new_author_box === '' ? $content : rtrim($content) . "\n" . $new_author_box;
        }

        $start = $match[0][1];
        $end = $this->find_matching_div_end($content, $start);
        if ($end === null) {
            $this->log_debug('Could not replace malformed author-box HTML; preserving existing content');
            return $content;
        }

        return substr($content, 0, $start) . $new_author_box . substr($content, $end);
    }

    /**
     * Return the offset immediately after the closing div paired with $start.
     */
    private function find_matching_div_end($content, $start) {
        $fragment = substr($content, $start);
        if (!preg_match_all('/<\/?div\b[^>]*>/i', $fragment, $tags, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $depth = 0;
        foreach ($tags[0] as $tag) {
            $is_closing = strpos($tag[0], '</') === 0;
            $depth += $is_closing ? -1 : 1;

            if ($depth === 0) {
                return $start + $tag[1] + strlen($tag[0]);
            }
        }

        return null;
    }

    /**
     * Remove page-builder metadata so WordPress renders from post_content.
     *
     * Covers Elementor, Divi Builder, WPBakery, Beaver Builder, Brizy, Oxygen,
     * and Enfold ALB. Only deletes keys that actually exist to avoid unnecessary DB writes.
     *
     * @param int $post_id WordPress post ID
     */
    private function clear_page_builder_meta($post_id) {
        $keys = array(
            // Elementor
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_pro_version',
            '_elementor_css',
            // Divi
            '_et_builder_version',
            '_et_pb_use_builder',
            '_et_pb_old_content',
            // WPBakery (Visual Composer)
            '_wpb_vc_js_status',
            // Beaver Builder
            '_fl_builder_data',
            '_fl_builder_data_settings',
            '_fl_builder_draft',
            '_fl_builder_draft_settings',
            '_fl_builder_enabled',
            // Brizy
            'brizy_post_uid',
            'brizy',
            // Oxygen
            'ct_builder_shortcodes',
            'ct_other_template',
            // Enfold Advanced Layout Builder
            '_aviaLayoutBuilder_active',
            '_aviaLayoutBuilderCleanData',
            '_avia_builder_shortcode_tree',
        );

        $cleared = array();
        foreach ($keys as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                delete_post_meta($post_id, $key);
                $cleared[] = $key;
            }
        }

        if (!empty($cleared)) {
            $this->log_debug(sprintf(
                'Cleared page-builder meta from post %d: %s',
                $post_id,
                implode(', ', $cleared)
            ));
        }
    }

    /**
     * Copy Kodanote post title/excerpt/body into empty ACF fields.
     *
     * Some themes never call the_content() and instead render ACF wysiwyg or
     * flexible-content fields. Writing those fields makes the article show in
     * both the theme and the WordPress editor.
     *
     * Existing non-empty field values are left untouched so user edits stay.
     *
     * @param int $post_id WordPress post ID
     */
    public function sync_content_to_acf_fields($post_id) {
        if (!function_exists('acf_get_field_groups') || !function_exists('update_field') || !function_exists('get_field')) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status === 'auto-draft') {
            return;
        }

        $groups = acf_get_field_groups(array('post_id' => $post_id));
        if (empty($groups) || !is_array($groups)) {
            update_post_meta($post_id, '_kodanote_acf_synced', current_time('mysql'));
            return;
        }

        $filled = array();
        foreach ($groups as $group) {
            $fields = function_exists('acf_get_fields') ? acf_get_fields($group) : array();
            if (empty($fields) || !is_array($fields)) {
                continue;
            }
            $this->fill_acf_fields_from_post($fields, $post, $filled);
        }

        if (!empty($filled)) {
            $this->log_debug(sprintf(
                'Copied Kodanote content into empty ACF fields for post %d: %s',
                $post_id,
                implode(', ', $filled)
            ));
        }

        update_post_meta($post_id, '_kodanote_acf_synced', current_time('mysql'));
    }

    /**
     * Recursively fill empty ACF fields that map to title, excerpt, or body.
     *
     * @param array    $fields  ACF field arrays
     * @param WP_Post  $post    WordPress post
     * @param string[] $filled  Field names written during this pass
     */
    private function fill_acf_fields_from_post($fields, $post, &$filled) {
        $title_names = array('title', 'titel', 'kop', 'headline', 'heading', 'post_title', 'pagina_titel');
        $excerpt_names = array(
            'excerpt', 'intro', 'inleiding', 'samenvatting', 'lead', 'subtitle',
            'subtitel', 'intro_text', 'intro_tekst', 'tekst_intro', 'news_intro',
            'nieuws_intro', 'inleiding_tekst',
        );
        $content_names = array(
            'content', 'content_text', 'tekst', 'text', 'body', 'inhoud',
            'article_content', 'artikel', 'wysiwyg', 'beschrijving', 'description',
            'bericht', 'artikel_tekst', 'post_content',
        );

        $wysiwyg_candidates = array();

        foreach ($fields as $field) {
            if (empty($field['name']) || empty($field['type'])) {
                continue;
            }

            $name = strtolower((string) $field['name']);
            $type = $field['type'];
            $post_id = $post->ID;

            if ($type === 'group' && !empty($field['sub_fields'])) {
                $this->fill_acf_fields_from_post($field['sub_fields'], $post, $filled);
                continue;
            }

            if ($type === 'flexible_content') {
                $this->fill_empty_acf_flexible_content($field, $post, $filled);
                continue;
            }

            $current = get_field($field['key'], $post_id, false);
            if ($this->acf_value_has_content($current)) {
                continue;
            }

            $value = null;
            if ($type === 'text' && in_array($name, $title_names, true)) {
                $value = $post->post_title;
            } elseif (in_array($type, array('textarea', 'wysiwyg', 'text'), true) && in_array($name, $excerpt_names, true)) {
                $value = $post->post_excerpt;
            } elseif (in_array($type, array('wysiwyg', 'textarea'), true) && in_array($name, $content_names, true)) {
                $value = $post->post_content;
            } elseif ($type === 'wysiwyg') {
                $wysiwyg_candidates[] = $field;
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            update_field($field['key'], $value, $post_id);
            $filled[] = $field['name'];
        }

        // If the field group has exactly one empty unnamed wysiwyg, treat it as the body.
        if (count($wysiwyg_candidates) === 1 && !empty($post->post_content)) {
            $field = $wysiwyg_candidates[0];
            update_field($field['key'], $post->post_content, $post->ID);
            $filled[] = $field['name'];
        }
    }

    /**
     * Insert one flexible-content row when the field is empty and a simple
     * wysiwyg/textarea layout exists. Nested brick builders are skipped.
     *
     * @param array    $field   ACF flexible_content field
     * @param WP_Post  $post    WordPress post
     * @param string[] $filled  Field names written during this pass
     */
    private function fill_empty_acf_flexible_content($field, $post, &$filled) {
        $current = get_field($field['key'], $post->ID, false);
        if ($this->acf_value_has_content($current)) {
            return;
        }

        if (empty($field['layouts']) || !is_array($field['layouts'])) {
            return;
        }

        foreach ($field['layouts'] as $layout) {
            $row = $this->build_simple_acf_flexible_row($layout, $post);
            if ($row === null) {
                continue;
            }
            update_field($field['key'], array($row), $post->ID);
            $filled[] = $field['name'] . ':' . $row['acf_fc_layout'];
            return;
        }
    }

    /**
     * Build a flexible-content row when every subfield is a simple input and
     * at least one can hold the article body.
     *
     * @param array   $layout ACF layout array
     * @param WP_Post $post   WordPress post
     * @return array|null
     */
    private function build_simple_acf_flexible_row($layout, $post) {
        if (empty($layout['name']) || empty($layout['sub_fields']) || !is_array($layout['sub_fields'])) {
            return null;
        }

        $complex_types = array('flexible_content', 'repeater', 'group', 'relationship', 'post_object', 'page_link', 'taxonomy', 'user', 'clone');
        $body_subfield = null;
        $title_subfield = null;
        $excerpt_subfield = null;

        foreach ($layout['sub_fields'] as $sub) {
            if (empty($sub['name']) || empty($sub['type'])) {
                return null;
            }
            if (in_array($sub['type'], $complex_types, true)) {
                return null;
            }
            $sub_name = strtolower((string) $sub['name']);
            if ($sub['type'] === 'wysiwyg' || in_array($sub_name, array('content', 'tekst', 'text', 'body', 'inhoud', 'wysiwyg'), true)) {
                $body_subfield = $sub['name'];
            }
            if (in_array($sub_name, array('title', 'titel', 'kop', 'headline'), true)) {
                $title_subfield = $sub['name'];
            }
            if (in_array($sub_name, array('excerpt', 'intro', 'inleiding', 'lead'), true)) {
                $excerpt_subfield = $sub['name'];
            }
        }

        if ($body_subfield === null) {
            return null;
        }

        $row = array('acf_fc_layout' => $layout['name']);
        $row[$body_subfield] = $post->post_content;
        if ($title_subfield) {
            $row[$title_subfield] = $post->post_title;
        }
        if ($excerpt_subfield && !empty($post->post_excerpt)) {
            $row[$excerpt_subfield] = $post->post_excerpt;
        }

        return $row;
    }

    /**
     * Whether an ACF raw value already contains user or generated content.
     *
     * @param mixed $value Raw ACF value
     * @return bool
     */
    private function acf_value_has_content($value) {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return !empty($value);
        }
        return trim(wp_strip_all_tags((string) $value)) !== '';
    }

    /**
     * Log debug message (only if debug mode is enabled)
     * 
     * @param string $message
     */
    private function log_debug($message) {
        $debug_mode = get_option('kodanote_debug_mode', '0');
        if ($debug_mode === '1') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging controlled by admin setting
            error_log('[Kodanote Publisher] ' . $message);
        }
    }

    /**
     * Set WordPress tags for a post
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_wordpress_tags($post_id, $article) {
        // Skip if no tags available
        if (empty($article->wordpress_tags)) {
            return;
        }

        // Parse comma-separated tags
        $tags_array = array_map('trim', explode(',', $article->wordpress_tags));
        $tags_array = array_filter($tags_array); // Remove empty values

        if (empty($tags_array)) {
            return;
        }

        // Set the tags on the post (wp_set_post_tags will create tags if they don't exist)
        $result = wp_set_post_tags($post_id, $tags_array, false); // false = replace existing tags

        if (is_wp_error($result)) {
            $this->log_debug(sprintf(
                'Failed to set tags for post %d: %s',
                $post_id,
                $result->get_error_message()
            ));
        } else {
            $this->log_debug(sprintf(
                'Set %d tags for post %d: %s',
                count($tags_array),
                $post_id,
                implode(', ', $tags_array)
            ));
        }

        // Store the original tags in post meta for reference
        update_post_meta($post_id, '_kodanote_wordpress_tags', $article->wordpress_tags);
    }

    /**
     * Set SEO meta fields for a post
     * Handles both Yoast SEO and custom meta output
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_seo_meta_fields($post_id, $article) {
        // Always store in our custom meta fields (for fallback and custom output)
        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_kodanote_meta_description', $article->meta_description);
        }
        if (!empty($article->meta_keywords)) {
            update_post_meta($post_id, '_kodanote_meta_keywords', $article->meta_keywords);
        }

        // Store FAQ schema for FAQPage JSON-LD output
        if (!empty($article->faq_schema)) {
            update_post_meta($post_id, '_kodanote_faq_schema', $article->faq_schema);
        }

        // Populate every active SEO plugin's native fields. Some sites run
        // multiple SEO plugins, and whichever one outputs wp_head needs data.
        $seo_plugins_updated = 0;
        if ($this->is_yoast_active()) {
            $this->set_yoast_meta($post_id, $article);
            $seo_plugins_updated++;
            $this->log_debug(sprintf(
                'Set Yoast SEO meta for post %d - description: %d chars, primary keyword: %s',
                $post_id,
                strlen($article->meta_description ?? ''),
                $article->keywords ?? 'none'
            ));
        }
        if ($this->is_rank_math_active()) {
            $this->set_rank_math_meta($post_id, $article);
            $seo_plugins_updated++;
            $this->log_debug(sprintf(
                'Set Rank Math SEO meta for post %d - description: %d chars, primary keyword: %s',
                $post_id,
                strlen($article->meta_description ?? ''),
                $article->keywords ?? 'none'
            ));
        }
        if ($this->is_seopress_active()) {
            $this->set_seopress_meta($post_id, $article);
            $seo_plugins_updated++;
            $this->log_debug(sprintf(
                'Set SEOPress meta for post %d - description: %d chars, primary keyword: %s',
                $post_id,
                strlen($article->meta_description ?? ''),
                $article->keywords ?? 'none'
            ));
        }
        if ($this->is_aioseo_active()) {
            $this->set_aioseo_meta($post_id, $article);
            $seo_plugins_updated++;
            $this->log_debug(sprintf(
                'Set AIOSEO meta for post %d - description: %d chars',
                $post_id,
                strlen($article->meta_description ?? '')
            ));
        }
        if ($this->is_smartcrawl_active()) {
            $this->set_smartcrawl_meta($post_id, $article);
            $seo_plugins_updated++;
            $this->log_debug(sprintf(
                'Set SmartCrawl meta for post %d - description: %d chars',
                $post_id,
                strlen($article->meta_description ?? '')
            ));
        }

        if ($seo_plugins_updated === 0) {
            $this->log_debug(sprintf(
                'No supported SEO plugin active - using custom meta for post %d',
                $post_id
            ));
        }
    }

    /**
     * Get the primary keyphrase to store in SEO plugin fields.
     *
     * @param object $article Article data from sync table
     * @return string
     */
    private function get_focus_keyphrase($article) {
        if (!empty($article->keywords)) {
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                return $keywords_array[0];
            }
        }

        if (!empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                return $meta_keywords_array[0];
            }
        }

        return '';
    }

    /**
     * Check if Yoast SEO plugin is active
     * 
     * @return bool
     */
    private function is_yoast_active() {
        return defined('WPSEO_VERSION') || 
               class_exists('WPSEO_Meta') || 
               function_exists('wpseo_init');
    }

    /**
     * Set Yoast SEO meta fields
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_yoast_meta($post_id, $article) {
        $title = get_the_title($post_id);
        if (!empty($title)) {
            update_post_meta($post_id, '_yoast_wpseo_title', $title);
        }

        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $article->meta_description);
        }

        // Yoast stores focus keyword in _yoast_wpseo_focuskw
        // Use the article's primary keyword first, fall back to first meta keyword
        $focus_keyphrase = '';
        
        // Primary source: the article's main keyword field
        if (!empty($article->keywords)) {
            // Keywords field may be comma-separated, get the first one
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                $focus_keyphrase = $keywords_array[0];
            }
        }
        
        // Fallback: use first meta keyword if no primary keyword
        if (empty($focus_keyphrase) && !empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                $focus_keyphrase = $meta_keywords_array[0];
            }
        }
        
        if (!empty($focus_keyphrase)) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyphrase);
            $this->log_debug(sprintf(
                'Set Yoast focus keyphrase for post %d: "%s"',
                $post_id,
                $focus_keyphrase
            ));
        }

        // Explicitly set the OG image so Yoast includes it in og:image output.
        // Without this, Yoast may not pick up the featured image for social sharing
        // depending on the site's Yoast settings.
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $og_image_url);
                update_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', $thumbnail_id);
                update_post_meta($post_id, '_yoast_wpseo_twitter-image', $og_image_url);
                update_post_meta($post_id, '_yoast_wpseo_twitter-image-id', $thumbnail_id);
                $this->log_debug(sprintf(
                    'Set Yoast OG/Twitter image for post %d: %s (attachment %d)',
                    $post_id,
                    $og_image_url,
                    $thumbnail_id
                ));
            }
        }
    }

    /**
     * Check if Rank Math SEO plugin is active
     * 
     * @return bool
     */
    private function is_rank_math_active() {
        return defined('RANK_MATH_VERSION') || 
               class_exists('RankMath') || 
               class_exists('RankMath\\Helper');
    }

    /**
     * Set Rank Math SEO meta fields
     * 
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_rank_math_meta($post_id, $article) {
        $title = get_the_title($post_id);
        if (!empty($title)) {
            update_post_meta($post_id, 'rank_math_title', $title);
        }

        if (!empty($article->meta_description)) {
            update_post_meta($post_id, 'rank_math_description', $article->meta_description);
        }

        $focus_keyphrase = '';
        
        if (!empty($article->keywords)) {
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                $focus_keyphrase = $keywords_array[0];
            }
        }
        
        if (empty($focus_keyphrase) && !empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                $focus_keyphrase = $meta_keywords_array[0];
            }
        }
        
        if (!empty($focus_keyphrase)) {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyphrase);
            $this->log_debug(sprintf(
                'Set Rank Math focus keyword for post %d: "%s"',
                $post_id,
                $focus_keyphrase
            ));
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                update_post_meta($post_id, 'rank_math_facebook_image', $og_image_url);
                update_post_meta($post_id, 'rank_math_facebook_image_id', $thumbnail_id);
                update_post_meta($post_id, 'rank_math_twitter_use_facebook', 'on');
                $this->log_debug(sprintf(
                    'Set Rank Math Facebook image for post %d: %s (attachment %d)',
                    $post_id,
                    $og_image_url,
                    $thumbnail_id
                ));
            }
        }
    }

    /**
     * Check if SEOPress plugin is active
     *
     * @return bool
     */
    private function is_seopress_active() {
        return defined('SEOPRESS_VERSION') ||
               function_exists('seopress_get_service') ||
               class_exists('SEOPress\\Core\\Kernel');
    }

    /**
     * Set SEOPress meta fields so the user doesn't have to re-enter them.
     *
     * SEOPress stores its data in post_meta with the `_seopress_` prefix.
     * We populate the SEO title, meta description, focus keyword, and the
     * Facebook / Twitter social fields so social previews work out of the box.
     *
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_seopress_meta($post_id, $article) {
        $title = get_the_title($post_id);

        if (!empty($title)) {
            update_post_meta($post_id, '_seopress_titles_title', $title);
        }

        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_seopress_titles_desc', $article->meta_description);
        }

        $focus_keyphrase = '';
        if (!empty($article->keywords)) {
            $keywords_array = array_map('trim', explode(',', $article->keywords));
            if (!empty($keywords_array[0])) {
                $focus_keyphrase = $keywords_array[0];
            }
        }
        if (empty($focus_keyphrase) && !empty($article->meta_keywords)) {
            $meta_keywords_array = array_map('trim', explode(',', $article->meta_keywords));
            if (!empty($meta_keywords_array[0])) {
                $focus_keyphrase = $meta_keywords_array[0];
            }
        }
        if (!empty($focus_keyphrase)) {
            // SEOPress stores the target keyword as a comma-separated list
            update_post_meta($post_id, '_seopress_analysis_target_kw', $focus_keyphrase);
            $this->log_debug(sprintf(
                'Set SEOPress target keyword for post %d: "%s"',
                $post_id,
                $focus_keyphrase
            ));
        }

        // Facebook Open Graph fields
        if (!empty($title)) {
            update_post_meta($post_id, '_seopress_social_fb_title', $title);
            update_post_meta($post_id, '_seopress_social_twitter_title', $title);
        }
        if (!empty($article->meta_description)) {
            update_post_meta($post_id, '_seopress_social_fb_desc', $article->meta_description);
            update_post_meta($post_id, '_seopress_social_twitter_desc', $article->meta_description);
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                update_post_meta($post_id, '_seopress_social_fb_img', $og_image_url);
                update_post_meta($post_id, '_seopress_social_fb_img_attachment_id', $thumbnail_id);
                update_post_meta($post_id, '_seopress_social_twitter_img', $og_image_url);
                update_post_meta($post_id, '_seopress_social_twitter_img_attachment_id', $thumbnail_id);
                $this->log_debug(sprintf(
                    'Set SEOPress Facebook/Twitter image for post %d: %s (attachment %d)',
                    $post_id,
                    $og_image_url,
                    $thumbnail_id
                ));
            }
        }
    }

    /**
     * Check if All in One SEO is active.
     *
     * @return bool
     */
    private function is_aioseo_active() {
        return defined('AIOSEO_VERSION') ||
               defined('AIOSEO_FILE') ||
               class_exists('AIOSEO\\Plugin\\AIOSEO') ||
               function_exists('aioseo');
    }

    /**
     * Set All in One SEO meta fields.
     *
     * AIOSEO 4 stores the values it outputs in the aioseo_posts table, not in
     * post_meta. We still write compatibility post_meta keys for integrations
     * that read them, but the custom table update is what affects the page head.
     *
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_aioseo_meta($post_id, $article) {
        global $wpdb;

        $title = get_the_title($post_id);
        $description = $article->meta_description ?? '';

        if (!empty($title)) {
            update_post_meta($post_id, '_aioseo_title', $title);
        }
        if (!empty($description)) {
            update_post_meta($post_id, '_aioseo_description', $description);
        }
        if (!empty($article->meta_keywords)) {
            update_post_meta($post_id, '_aioseo_keywords', $article->meta_keywords);
        }

        $table_name = $wpdb->prefix . 'aioseo_posts';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($table_exists !== $table_name) {
            $this->log_debug(sprintf('AIOSEO table not found for post %d', $post_id));
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . esc_sql($table_name), 0);
        if (empty($columns)) {
            return;
        }

        $now = current_time('mysql');
        $data = array(
            'post_id' => (int) $post_id,
            'title' => $title,
            'description' => $description,
            'keywords' => $article->meta_keywords ?? '',
            'og_title' => $title,
            'og_description' => $description,
            'twitter_use_og' => 1,
            'twitter_title' => $title,
            'twitter_description' => $description,
            'updated' => $now,
        );

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $og_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($og_image_url) {
                $data['og_image_type'] = 'custom_image';
                $data['og_image_url'] = $og_image_url;
                $data['og_image_custom_url'] = $og_image_url;
                $data['twitter_image_type'] = 'custom_image';
                $data['twitter_image_url'] = $og_image_url;
                $data['twitter_image_custom_url'] = $og_image_url;
            }
        }

        $data = array_intersect_key($data, array_flip($columns));
        if (empty($data)) {
            return;
        }

        $formats = array();
        foreach ($data as $key => $value) {
            $formats[] = in_array($key, array('post_id', 'twitter_use_og'), true) ? '%d' : '%s';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d LIMIT 1", $post_id));

        if ($existing_id) {
            unset($data['post_id']);
            $formats = array();
            foreach ($data as $key => $value) {
                $formats[] = ($key === 'twitter_use_og') ? '%d' : '%s';
            }

            if (empty($data)) {
                return;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table_name,
                $data,
                array('post_id' => $post_id),
                $formats,
                array('%d')
            );
        } else {
            if (in_array('created', $columns, true) && !isset($data['created'])) {
                $data['created'] = $now;
                $formats[] = '%s';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert($table_name, $data, $formats);
        }

        if ($result === false) {
            $this->log_debug(sprintf(
                'Failed to set AIOSEO table data for post %d: %s',
                $post_id,
                $wpdb->last_error
            ));
        }
    }

    /**
     * Check if SmartCrawl is active.
     *
     * @return bool
     */
    private function is_smartcrawl_active() {
        return defined('SMARTCRAWL_VERSION') ||
               defined('SMARTCRAWL_PLUGIN_VERSION') ||
               class_exists('Smartcrawl_Loader') ||
               class_exists('Smartcrawl_Controller_Hub');
    }

    /**
     * Set SmartCrawl meta fields.
     *
     * @param int $post_id WordPress post ID
     * @param object $article Article data from sync table
     */
    private function set_smartcrawl_meta($post_id, $article) {
        $title = get_the_title($post_id);
        $description = $article->meta_description ?? '';

        if (!empty($title)) {
            update_post_meta($post_id, '_wds_title', $title);
        }
        if (!empty($description)) {
            update_post_meta($post_id, '_wds_metadesc', $description);
        }

        $opengraph = get_post_meta($post_id, '_wds_opengraph', true);
        $opengraph = is_array($opengraph) ? $opengraph : array();
        if (!empty($title)) {
            $opengraph['title'] = $title;
        }
        if (!empty($description)) {
            $opengraph['description'] = $description;
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($image_url) {
                $opengraph['images'] = array($image_url);
            }
        }

        if (!empty($opengraph)) {
            update_post_meta($post_id, '_wds_opengraph', $opengraph);
        }

        $twitter = get_post_meta($post_id, '_wds_twitter', true);
        $twitter = is_array($twitter) ? $twitter : array();
        if (!empty($title)) {
            $twitter['title'] = $title;
        }
        if (!empty($description)) {
            $twitter['description'] = $description;
        }

        if (!empty($twitter)) {
            update_post_meta($post_id, '_wds_twitter', $twitter);
        }
    }

    /**
     * Assign the correct WPML language to a post.
     *
     * Uses WPML's official wpml_set_element_language_details action which
     * handles the icl_translations table, language directories (/en/, /de/, etc.),
     * and all internal WPML bookkeeping automatically.
     *
     * Falls back gracefully: if WPML is not installed or the language code is
     * not configured in WPML, the post is left in the site default language.
     */
    private function set_wpml_language($post_id, $language_code) {
        // Polylang exposes icl_object_id() for WPML compatibility, so that
        // function does not prove that WPML itself is active. The SitePress
        // version constant is unique to WPML.
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        $language_code = strtolower(substr($language_code, 0, 2));

        // Verify this language is configured in WPML
        $active_languages = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
        if (!is_array($active_languages) || !isset($active_languages[$language_code])) {
            $this->log_debug(sprintf(
                'WPML: language "%s" is not active in WPML for post %d — skipping',
                $language_code,
                $post_id
            ));
            return;
        }

        // Check current WPML language assignment
        $element_type = 'post_post';
        $current_lang = apply_filters('wpml_element_language_code', null, array(
            'element_id'   => $post_id,
            'element_type' => $element_type,
        ));

        if ($current_lang === $language_code) {
            return;
        }

        do_action('wpml_set_element_language_details', array(
            'element_id'           => $post_id,
            'element_type'         => $element_type,
            'trid'                 => false,
            'language_code'        => $language_code,
            'source_language_code' => null,
        ));

        $this->log_debug(sprintf(
            'WPML: assigned language "%s" to post %d (was: %s)',
            $language_code,
            $post_id,
            $current_lang ?: 'default'
        ));
    }

    /**
     * Assign a post's translation language, dispatching to whichever
     * multilingual plugin is active (WPML or Polylang).
     *
     * @param int         $post_id           WordPress post ID.
     * @param string      $language_code     Target language code (e.g. "de").
     * @param string|null $source_article_id Kodanote ID of the source-language
     *                                        article this post was translated from.
     */
    private function set_post_language($post_id, $language_code, $source_article_id = null) {
        // Polylang exposes icl_object_id() for WPML compatibility. Detect
        // WPML only by its unique SitePress constant so Polylang reaches its
        // native API below.
        if (defined('ICL_SITEPRESS_VERSION')) {
            $this->set_wpml_language($post_id, $language_code);
            return;
        }

        if (function_exists('pll_set_post_language')) {
            $this->set_polylang_language($post_id, $language_code, $source_article_id);
        }
    }

    /**
     * Assign the correct Polylang language to a post and, when the source
     * article is known, link it to that translation group.
     *
     * Polylang stores language as a term relationship and generates the
     * language directory (/de/, /fr/, …) from it. Without this, translated
     * posts fall back to the default language and publish to the primary
     * (untranslated) URL space — e.g. a German post landing on the English blog.
     *
     * Falls back gracefully: if Polylang is not installed or the language is
     * not configured, the post is left in the default language.
     */
    private function set_polylang_language($post_id, $language_code, $source_article_id = null) {
        if (!function_exists('pll_set_post_language')) {
            return;
        }

        $language_code = strtolower(substr($language_code, 0, 2));

        // Verify this language is configured in Polylang.
        $active_languages = function_exists('pll_languages_list')
            ? pll_languages_list(array('fields' => 'slug'))
            : array();
        if (!is_array($active_languages) || !in_array($language_code, $active_languages, true)) {
            $this->log_debug(sprintf(
                'Polylang: language "%s" is not active for post %d — skipping',
                $language_code,
                $post_id
            ));
            return;
        }

        $current_lang = function_exists('pll_get_post_language')
            ? pll_get_post_language($post_id, 'slug')
            : '';

        if ($current_lang !== $language_code) {
            pll_set_post_language($post_id, $language_code);
            $this->log_debug(sprintf(
                'Polylang: assigned language "%s" to post %d (was: %s)',
                $language_code,
                $post_id,
                $current_lang ?: 'default'
            ));
        }

        // Link this translation to its source-language post so Polylang serves
        // proper hreflang tags and the language switcher points between them.
        if (!empty($source_article_id)) {
            $this->link_polylang_translation($post_id, $language_code, $source_article_id);
        }
    }

    /**
     * Connect a translated post to its source-language post within Polylang's
     * translation group, so the two are recognised as translations of each other.
     *
     * @param int    $translated_post_id Post ID of the translated article.
     * @param string $translated_lang    Language slug of the translated post.
     * @param string $source_article_id  Kodanote ID of the source-language article.
     */
    private function link_polylang_translation($translated_post_id, $translated_lang, $source_article_id) {
        if (!function_exists('pll_save_post_translations') || !function_exists('pll_get_post_language')) {
            return;
        }

        $source_post = $this->find_post_by_kodanote_id($source_article_id);
        if (!$source_post) {
            $this->log_debug(sprintf(
                'Polylang: source article %s for post %d not on this site yet — skipping translation link',
                $source_article_id,
                $translated_post_id
            ));
            return;
        }

        // Ensure the source post has a language before linking. If it never got
        // one (e.g. published before this feature existed), assign the site default.
        $source_lang = pll_get_post_language($source_post->ID, 'slug');
        if (empty($source_lang)) {
            $source_lang = function_exists('pll_default_language') ? pll_default_language('slug') : '';
            if (!empty($source_lang)) {
                pll_set_post_language($source_post->ID, $source_lang);
            }
        }

        // Two posts sharing the same language can't be linked as translations.
        if (empty($source_lang) || $source_lang === $translated_lang) {
            return;
        }

        // Merge into the source post's existing translation group. Polylang's
        // pll_save_post_translations() REPLACES the whole group, so on a site
        // with multiple target languages (e.g. EN→DE and EN→FR) we must carry
        // over the already-linked translations or linking the second language
        // would silently unlink the first.
        $translations = function_exists('pll_get_post_translations')
            ? pll_get_post_translations($source_post->ID)
            : array();
        if (!is_array($translations)) {
            $translations = array();
        }
        $translations[$source_lang]     = $source_post->ID;
        $translations[$translated_lang] = $translated_post_id;

        pll_save_post_translations($translations);

        $this->log_debug(sprintf(
            'Polylang: linked post %d (%s) as translation of post %d (%s); group now [%s]',
            $translated_post_id,
            $translated_lang,
            $source_post->ID,
            $source_lang,
            implode(', ', array_keys($translations))
        ));
    }

    /**
     * Find a WordPress post by the Kodanote article ID stored in its
     * _kodanote_article_id meta.
     *
     * @param string $kodanote_article_id
     * @return WP_Post|null
     */
    private function find_post_by_kodanote_id($kodanote_article_id) {
        $query = new WP_Query(array(
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
                    'value' => (string) $kodanote_article_id,
                ),
            ),
        ));
        $post = !empty($query->posts) ? $query->posts[0] : null;
        wp_reset_postdata();
        return $post;
    }

    /**
     * Build a portable ASCII post slug.
     *
     * Expand German umlauts and ß to ae/oe/ue/ss before sanitize_title() and
     * the ASCII-only strip. Without this, a non-de_DE locale turns "Qualität"
     * into "qualitat" or drops ä and leaves "qualitt".
     *
     * @param string $raw_slug Title or supplied slug.
     * @param string $fallback Used when the sanitized slug is empty.
     * @return string
     */
    private function sanitize_post_slug($raw_slug, $fallback = '') {
        $text = is_string($raw_slug) ? $raw_slug : '';

        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }

        $text = strtr($text, array(
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'Ä' => 'ae',
            'Ö' => 'oe',
            'Ü' => 'ue',
            'ß' => 'ss',
            'ẞ' => 'ss',
        ));

        $post_slug = sanitize_title($text);
        $post_slug = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $post_slug));
        $post_slug = trim($post_slug, '-');

        if ($post_slug === '') {
            return $fallback;
        }

        return $post_slug;
    }
}

/**
 * Kodanote Meta Output Handler
 * Handles outputting meta tags when no supported SEO plugin is installed
 */
class Kodanote_Meta_Output {

    /**
     * Initialize meta output hooks
     */
    public static function init() {
        // Only output our own meta tags if no supported SEO plugin handles them
        if (!self::is_seo_plugin_active()) {
            add_action('wp_head', array(__CLASS__, 'output_meta_tags'), 1);
        }

        // FAQ schema runs regardless of SEO plugins (they don't auto-generate FAQPage schema)
        add_action('wp_head', array(__CLASS__, 'output_faq_schema'), 2);
    }

    /**
     * Check if any supported SEO plugin is active.
     * When one is active, we let it handle meta description and Open Graph
     * output so we don't emit duplicate tags.
     *
     * Detects Yoast SEO, Rank Math, SEOPress, All in One SEO (AIOSEO),
     * SmartCrawl, and The SEO Framework - all of which output their own
     * og:/twitter: meta tags.
     *
     * @return bool
     */
    private static function is_seo_plugin_active() {
        // Yoast SEO
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') || function_exists('wpseo_init')) {
            return true;
        }
        // Rank Math
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath') || class_exists('RankMath\\Helper')) {
            return true;
        }
        // SEOPress
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service') || class_exists('SEOPress\\Core\\Kernel')) {
            return true;
        }
        // All in One SEO (AIOSEO)
        if (defined('AIOSEO_VERSION') || defined('AIOSEO_FILE') || class_exists('AIOSEO\\Plugin\\AIOSEO')) {
            return true;
        }
        // SmartCrawl
        if (defined('SMARTCRAWL_VERSION') || defined('SMARTCRAWL_PLUGIN_VERSION') || class_exists('Smartcrawl_Loader')) {
            return true;
        }
        // The SEO Framework
        if (defined('THE_SEO_FRAMEWORK_DB_VERSION') || class_exists('The_SEO_Framework\\Load') || function_exists('the_seo_framework')) {
            return true;
        }
        return false;
    }

    /**
     * Output meta description, keywords, and Open Graph tags in wp_head.
     * Only runs when no SEO plugin (Yoast/Rank Math) is active.
     */
    public static function output_meta_tags() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $is_kodanote_managed = get_post_meta($post->ID, '_kodanote_managed', true);
        if (!$is_kodanote_managed) {
            return;
        }

        $meta_description = get_post_meta($post->ID, '_kodanote_meta_description', true);
        $meta_keywords = get_post_meta($post->ID, '_kodanote_meta_keywords', true);

        if (!empty($meta_description)) {
            echo '<meta name="description" content="' . esc_attr($meta_description) . '" />' . "\n";
        }

        if (!empty($meta_keywords)) {
            echo '<meta name="keywords" content="' . esc_attr($meta_keywords) . '" />' . "\n";
        }

        // Open Graph tags for Facebook / social sharing
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(get_the_title($post->ID)) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink($post->ID)) . '" />' . "\n";

        if (!empty($meta_description)) {
            echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" />' . "\n";
        }

        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
            if ($image_url) {
                echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";

                $image_meta = wp_get_attachment_metadata($thumbnail_id);
                if (!empty($image_meta['width'])) {
                    echo '<meta property="og:image:width" content="' . intval($image_meta['width']) . '" />' . "\n";
                }
                if (!empty($image_meta['height'])) {
                    echo '<meta property="og:image:height" content="' . intval($image_meta['height']) . '" />' . "\n";
                }

                $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
                if (!empty($image_alt)) {
                    echo '<meta property="og:image:alt" content="' . esc_attr($image_alt) . '" />' . "\n";
                }
            }
        }

        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";

        // Twitter Card tags (uses same image)
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr(get_the_title($post->ID)) . '" />' . "\n";
        if (!empty($meta_description)) {
            echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '" />' . "\n";
        }
        if (!empty($image_url)) {
            echo '<meta name="twitter:image" content="' . esc_url($image_url) . '" />' . "\n";
        }
    }

    /**
     * Output FAQPage JSON-LD structured data in wp_head for Kodanote articles.
     * Runs regardless of Yoast since Yoast does not auto-generate FAQPage schema.
     */
    public static function output_faq_schema() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $is_kodanote_managed = get_post_meta($post->ID, '_kodanote_managed', true);
        if (!$is_kodanote_managed) {
            return;
        }

        $faq_schema_raw = get_post_meta($post->ID, '_kodanote_faq_schema', true);
        if (empty($faq_schema_raw)) {
            return;
        }

        $faqs = is_string($faq_schema_raw) ? json_decode($faq_schema_raw, true) : $faq_schema_raw;
        if (empty($faqs) || !is_array($faqs)) {
            return;
        }

        $main_entity = array();
        foreach ($faqs as $faq) {
            if (empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }
            $main_entity[] = array(
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ),
            );
        }

        if (empty($main_entity)) {
            return;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $main_entity,
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD must be output as-is
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}

// Initialize meta output handler
add_action('init', array('Kodanote_Meta_Output', 'init'));


