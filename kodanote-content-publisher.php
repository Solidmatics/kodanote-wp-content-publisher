<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Plugin Name: Kodanote Content Publisher
 * Plugin URI: https://www.kodanote.com
 * Description: Connect WordPress to Kodanote to sync, schedule, and publish your content with your preferred categories and authors.
 * Version: 1.3.113
 * Author: Kodanote
 * Author URI: https://www.kodanote.com
 * Update URI: https://www.kodanote.com/kodanote-content-publisher
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kodanote-content-publisher
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('KODANOTE_VERSION', '1.3.113');
define('KODANOTE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KODANOTE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KODANOTE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('KODANOTE_PLUGIN_FILE', __FILE__);
if (!defined('KODANOTE_API_BASE_URL')) {
    define('KODANOTE_API_BASE_URL', '');
}
if (!defined('KODANOTE_DASHBOARD_URL')) {
    define('KODANOTE_DASHBOARD_URL', 'https://www.kodanote.com');
}

/**
 * Main Kodanote Plugin Class
 */
class Kodanote_Plugin {

    /**
     * Single instance of the plugin
     */
    private static $instance = null;

    /**
     * API Key for authentication
     */
    private $api_key = '';

    /**
     * API Base URL
     */
    private $api_base_url = '';

    /**
     * When true, the content-protection filter allows post_content changes
     * through to the database. The plugin sets this before its own
     * wp_insert_post / wp_update_post calls and clears it immediately after.
     */
    private static $allow_content_update = false;

    public static function allow_content_updates() {
        self::$allow_content_update = true;
    }

    public static function disallow_content_updates() {
        self::$allow_content_update = false;
    }

    /**
     * Get single instance of the plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        $this->set_api_credentials();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom cron schedules early (needed for adaptive sync)
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        add_action('init', array($this, 'init'));
        add_action('init', array($this, 'ensure_db_schema')); // Run migrations on init (needed for cron context)
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_init', array($this, 'check_plugin_upgrade'));
        add_action('admin_init', array($this, 'maybe_auto_verify_on_activation'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Setup wizard
        add_action('admin_init', array($this, 'check_setup_wizard'));
        add_action('admin_menu', array($this, 'add_setup_menu'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'), 0);
        add_action('wp_head', array($this, 'print_conversion_tracker_script'), 0);

        // LLM-friendly .md URL support
        add_action('init', array($this, 'register_md_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_md_url_request'));

        // Themes that render ACF/page-builder fields instead of the_content()
        // leave Kodanote posts looking empty. Fill ACF when we can, and inject
        // post_content into empty theme containers as a last resort.
        add_action('wp', array($this, 'maybe_sync_kodanote_content_to_acf'));
        add_action('template_redirect', array($this, 'maybe_buffer_kodanote_theme_fallback'), 1);
        add_filter('acf/load_field_group', array($this, 'keep_content_editor_visible_for_kodanote_posts'), 20);

        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // AJAX handlers
        add_action('wp_ajax_kodanote_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_kodanote_save_post_category', array($this, 'ajax_save_post_category'));
        add_action('wp_ajax_kodanote_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_kodanote_sync_articles', array($this, 'ajax_sync_articles'));
        add_action('wp_ajax_kodanote_publish_article', array($this, 'ajax_publish_article'));
        add_action('wp_ajax_kodanote_toggle_debug', array($this, 'ajax_toggle_debug'));
        add_action('wp_ajax_kodanote_auto_verify', array($this, 'ajax_attempt_auto_verification'));

        // Kodanote article edit protection (meta boxes remain editable for Rank Math, categories, tags, etc.)
        add_action('admin_notices', array($this, 'show_kodanote_edit_notice'));
        add_action('admin_head', array($this, 'add_kodanote_article_styles'));
        add_action('admin_footer', array($this, 'add_kodanote_content_readonly_script'));
        add_filter('post_row_actions', array($this, 'modify_kodanote_article_row_actions'), 10, 2);
        add_filter('use_block_editor_for_post', array($this, 'disable_gutenberg_for_kodanote_articles'), 10, 2);

        // Protect Kodanote post content from being overwritten by any external save
        // (admin editor, REST API, third-party plugins). Only the plugin's own
        // sync/publish/update code bypasses this via the $allow_content_update flag.
        add_filter('wp_insert_post_data', array($this, 'protect_kodanote_content_on_admin_save'), 10, 2);

        // Add SVG/path to TinyMCE valid elements so social icons display in the editor
        add_filter('tiny_mce_before_init', array($this, 'add_svg_to_tinymce_valid_elements'));

        // Prevent trashing/deleting Kodanote articles from WP admin (WP 5.5+ short-circuit filters)
        add_filter('pre_trash_post', array($this, 'prevent_kodanote_article_trashing'), 10, 3);
        add_filter('pre_delete_post', array($this, 'prevent_kodanote_article_deleting'), 10, 3);

        // Add 'kodanote' CSS class to post container and body for Kodanote-managed posts
        add_filter('post_class', array($this, 'add_kodanote_post_class'), 10, 3);
        add_filter('body_class', array($this, 'add_kodanote_body_class'));

        // Inject infographic image into post content
        add_filter('the_content', array($this, 'inject_infographic_image_into_content'), 10);

        // Replace the English author line on sites whose language is not English.
        // This updates pages that were synced before the label followed the site language.
        add_filter('the_content', array($this, 'localize_author_box_label_in_content'), 11);
        add_action('template_redirect', array($this, 'maybe_localize_archive_author_bylines'), 0);

        // Fix Key Takeaways HTML structure (runs earliest to fix stray </div> before other filters)
        add_filter('the_content', array($this, 'fix_key_takeaways_structure'), 3);

        // Convert stale markdown-style headings before WordPress auto-paragraphs the content
        add_filter('the_content', array($this, 'convert_markdown_headings_in_content'), 4);

        // Add anchor IDs to headings for TOC linking (runs early to ensure IDs exist)
        add_filter('the_content', array($this, 'add_heading_anchor_ids'), 5);

        // Unwrap legacy heading anchor links that break layout in some themes
        add_filter('the_content', array($this, 'normalize_kodanote_heading_anchors'), 6);

        // Keep YouTube embeds responsive even when inline styles were stripped before sync.
        add_filter('the_content', array($this, 'normalize_youtube_embed_markup'), 9);

        // Allow YouTube iframes and SVG icons in post content (WordPress strips these by default)
        add_filter('wp_kses_allowed_html', array($this, 'allow_kodanote_html_elements'), 10, 2);

        // Allow display/flex CSS properties in inline styles (WordPress strips them by default)
        add_filter('safe_style_css', array($this, 'allow_additional_css_properties'));

        // Hook for when posts are published (including manual publishing in WP admin)
        add_action('transition_post_status', array($this, 'handle_post_status_transition'), 10, 3);

        // Hook for when a published post's permalink changes (user edits slug)
        add_action('post_updated', array($this, 'handle_post_permalink_change'), 10, 3);

        // Hook for site-wide permalink structure changes (e.g. dated URLs -> post name URLs)
        add_action('update_option_permalink_structure', array($this, 'handle_permalink_structure_change'), 10, 2);
        add_action('kodanote_rescan_published_urls', array($this, 'run_permalink_structure_rescan'));

        // Deferred rewrite rules flush (set during activation to avoid .htaccess issues on slow hosts)
        add_action('admin_init', array($this, 'maybe_flush_rewrite_rules'));
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Load admin classes
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-admin.php';
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-api.php';
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-publisher.php';
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-scheduler.php';
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-notifications.php';
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-rendered-content.php';
        require_once KODANOTE_PLUGIN_DIR . 'includes/class-kodanote-sitemap.php';

        // Initialize classes
        new Kodanote_Admin();
        new Kodanote_API();
        new Kodanote_Publisher();
        new Kodanote_Scheduler();
        new Kodanote_Notifications();

        // List a sitemap in robots.txt (and serve one when the site has none)
        (new Kodanote_Sitemap())->register_hooks();
    }

    /**
     * Set API credentials from settings
     */
    private function set_api_credentials() {
        $this->api_key = get_option('kodanote_api_key', '');
        $this->api_base_url = rtrim(trim(KODANOTE_API_BASE_URL), '/');
    }

    /**
     * Add custom cron schedules for adaptive sync
     * 
     * Sync frequency is adaptive based on time since API key was set:
     * - First 10 minutes: every 1 minute
     * - 10-60 minutes: every 5 minutes  
     * - After 60 minutes: hourly
     */
    public function add_custom_cron_schedules($schedules) {
        // Every 1 minute
        $schedules['kodanote_every_minute'] = array(
            'interval' => 60,
            'display'  => __('Every Minute', 'kodanote-content-publisher')
        );
        
        // Every 5 minutes
        $schedules['kodanote_every_five_minutes'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'kodanote-content-publisher')
        );
        
        return $schedules;
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Explicit load for self-hosted distribution (not on WordPress.org)
        load_plugin_textdomain(
            'kodanote-content-publisher',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        // Check if user can manage options (admin)
        if (current_user_can('manage_options')) {
            // Add settings link to plugins page
            add_filter('plugin_action_links_' . KODANOTE_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        }
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings with sanitization callbacks
        register_setting('kodanote_settings', 'kodanote_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('kodanote_settings', 'kodanote_post_category', array(
            'type' => 'string',
            'sanitize_callback' => 'absint',
        ));
        register_setting('kodanote_settings', 'kodanote_author_id', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ));
        register_setting('kodanote_settings', 'kodanote_debug_mode', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));

        // Add settings sections
        add_settings_section(
            'kodanote_api_settings',
            __('API Configuration', 'kodanote-content-publisher'),
            array($this, 'api_settings_section_callback'),
            'kodanote_settings'
        );

        add_settings_section(
            'kodanote_publishing_settings',
            __('Publishing Settings', 'kodanote-content-publisher'),
            array($this, 'publishing_settings_section_callback'),
            'kodanote_settings'
        );

        add_settings_section(
            'kodanote_debug_settings',
            __('Debug Settings', 'kodanote-content-publisher'),
            array($this, 'debug_settings_section_callback'),
            'kodanote_settings'
        );

        // Add settings fields
        add_settings_field(
            'kodanote_api_key',
            __('API Key', 'kodanote-content-publisher'),
            array($this, 'api_key_field_callback'),
            'kodanote_settings',
            'kodanote_api_settings'
        );




        add_settings_field(
            'kodanote_post_category',
            __('Default Category', 'kodanote-content-publisher'),
            array($this, 'post_category_field_callback'),
            'kodanote_settings',
            'kodanote_publishing_settings'
        );

        add_settings_field(
            'kodanote_author_id',
            __('Default Author', 'kodanote-content-publisher'),
            array($this, 'author_field_callback'),
            'kodanote_settings',
            'kodanote_publishing_settings'
        );

        add_settings_field(
            'kodanote_debug_mode',
            __('Debug Mode', 'kodanote-content-publisher'),
            array($this, 'debug_mode_field_callback'),
            'kodanote_settings',
            'kodanote_debug_settings'
        );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Kodanote', 'kodanote-content-publisher'),
            __('Kodanote', 'kodanote-content-publisher'),
            'manage_options',
            'kodanote',
            array($this, 'admin_page'),
            'dashicons-admin-site-alt3',
            30
        );

        add_submenu_page(
            'kodanote',
            __('Dashboard', 'kodanote-content-publisher'),
            __('Dashboard', 'kodanote-content-publisher'),
            'manage_options',
            'kodanote',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'kodanote',
            __('Settings', 'kodanote-content-publisher'),
            __('Settings', 'kodanote-content-publisher'),
            'manage_options',
            'kodanote-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Load on Kodanote admin pages OR on posts list/edit pages (for article marking)
        $load_on_pages = array(
            'edit.php',           // Posts list page
            'post.php',           // Single post edit page
            'post-new.php'        // New post page
        );
        
        $is_kodanote_page = (strpos($hook, 'kodanote') !== false);
        $is_posts_page = in_array($hook, $load_on_pages, true);
        
        if (!$is_kodanote_page && !$is_posts_page) {
            return;
        }

        wp_enqueue_script(
            'kodanote-admin',
            KODANOTE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            KODANOTE_VERSION,
            true
        );

        wp_enqueue_style(
            'kodanote-admin',
            KODANOTE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            KODANOTE_VERSION
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script('kodanote-admin', 'kodanote_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kodanote_ajax_nonce'),
            'debug_mode' => get_option('kodanote_debug_mode', '0'),
            'strings' => array(
                'connecting'          => __('Connecting...', 'kodanote-content-publisher'),
                'connected'           => __('Connected!', 'kodanote-content-publisher'),
                'error'               => __('Error occurred', 'kodanote-content-publisher'),
                'syncing'             => __('Syncing articles...', 'kodanote-content-publisher'),
                'publishing'          => __('Publishing article...', 'kodanote-content-publisher'),
                'testing'             => __('Testing...', 'kodanote-content-publisher'),
                'testing_connection'  => __('Testing connection...', 'kodanote-content-publisher'),
                'connection_failed'   => __('Connection failed', 'kodanote-content-publisher'),
                'connection_success'  => __('Connection successful!', 'kodanote-content-publisher'),
                'copied'              => __('Copied!', 'kodanote-content-publisher'),
                'copy_failed'         => __('Failed to copy to clipboard.', 'kodanote-content-publisher'),
                'confirm_publish'     => __('Are you sure you want to publish this article?', 'kodanote-content-publisher'),
                'confirm_bulk'        => __('Are you sure you want to publish %d articles?', 'kodanote-content-publisher'),
                'confirm_delete'      => __('Are you sure you want to delete "%s"? This action cannot be undone.', 'kodanote-content-publisher'),
                'confirm_reset'       => __('Are you sure you want to reset all settings to defaults? This action cannot be undone.', 'kodanote-content-publisher'),
                'confirm_regen_key'   => __('Are you sure you want to regenerate your API key? This will invalidate the current key.', 'kodanote-content-publisher'),
                'confirm_clear_sync'  => __('Are you sure you want to clear all sync data? This action cannot be undone.', 'kodanote-content-publisher'),
                'select_articles'     => __('Please select articles to publish.', 'kodanote-content-publisher'),
                'publish_success'     => __('Article published successfully!', 'kodanote-content-publisher'),
                'publish_failed'      => __('Publish failed. Please try again.', 'kodanote-content-publisher'),
                'sync_success'        => __('Articles synced successfully!', 'kodanote-content-publisher'),
                'sync_failed'         => __('Sync failed. Please try again.', 'kodanote-content-publisher'),
                'articles_synced'     => __('%d articles synced', 'kodanote-content-publisher'),
                'settings_saved'      => __('Settings saved successfully!', 'kodanote-content-publisher'),
                'settings_failed'     => __('Failed to save settings. Please try again.', 'kodanote-content-publisher'),
                'debug_enabled'       => __('Debug mode enabled successfully.', 'kodanote-content-publisher'),
                'debug_disabled'      => __('Debug mode disabled successfully.', 'kodanote-content-publisher'),
                'debug_update_failed' => __('Failed to update debug settings.', 'kodanote-content-publisher'),
                'article_details'     => __('Article Details', 'kodanote-content-publisher'),
                'close'               => __('Close', 'kodanote-content-publisher'),
                'bulk_publish'        => __('Bulk Publish', 'kodanote-content-publisher'),
                'bulk_publish_count'  => __('Bulk Publish (%d)', 'kodanote-content-publisher'),
                'sync_complete'       => __('Sync Complete!', 'kodanote-content-publisher'),
                'sync_complete_msg'   => __('Sync completed successfully! Your content is up to date.', 'kodanote-content-publisher'),
                'sync_count_msg'      => __('Successfully synced %d article(s)! Your content is now up to date.', 'kodanote-content-publisher'),
                'sync_failed_title'   => __('Sync Failed', 'kodanote-content-publisher'),
                'not_available'       => __('This feature is not yet available.', 'kodanote-content-publisher'),
                'coming_soon'         => __('Coming soon!', 'kodanote-content-publisher'),
            )
        ));
        
        // Add inline script for marking Kodanote articles on posts list page
        if ($hook === 'edit.php') {
            $inline_js = '
                jQuery(document).ready(function($) {
                    // Mark Kodanote articles in the posts list
                    $(".wp-list-table tbody tr").each(function() {
                        var $row = $(this);
                        var postId = $row.attr("id");
                        if (postId) {
                            postId = postId.replace("post-", "");
                            
                            // Check if this post has Kodanote meta (we add this via PHP)
                            if ($row.find(".kodanote-managed").length > 0) {
                                $row.attr("data-kodanote-article", "true");
                                
                                // Keep the normal Edit link available so users can adjust
                                // categories, permalink, SEO fields, and other archive-related
                                // settings for Kodanote-managed posts.
                            }
                        }
                    });
                });
            ';
            wp_add_inline_script('kodanote-admin', $inline_js);
        }
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        // Check if current post is a Kodanote article
        global $post;
        
        $is_kodanote_article = false;
        if ($post && isset($post->ID)) {
            $kodanote_article_id = get_post_meta($post->ID, '_kodanote_article_id', true);
            $is_kodanote_article = !empty($kodanote_article_id);
        }
        
        // Always load frontend CSS for Kodanote articles (for infographic support)
        if ($is_kodanote_article || is_singular('post')) {
            wp_enqueue_style(
                'kodanote-frontend',
                KODANOTE_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                KODANOTE_VERSION
            );
        }

        $post_content = ($post && isset($post->post_content)) ? $post->post_content : '';
        $needs_frontend_js = $is_kodanote_article
            || has_shortcode($post_content, 'kodanote')
            || is_page('kodanote-dashboard');

        if (!$needs_frontend_js) {
            return;
        }

        wp_enqueue_script(
            'kodanote-frontend',
            KODANOTE_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            KODANOTE_VERSION,
            true
        );
    }

    /**
     * Print the conversion tracker as early as possible in wp_head.
     *
     * Enqueued head scripts print later in wp_head, so inline output here gives
     * the tracker the best chance to install listeners before ad pixels fire.
     */
    public function print_conversion_tracker_script() {
        if (is_admin() || trim(KODANOTE_API_BASE_URL) === '') {
            return;
        }

        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            return;
        }

        $script_path = KODANOTE_PLUGIN_DIR . 'assets/js/conversion-tracker.js';
        if (!file_exists($script_path) || !is_readable($script_path)) {
            return;
        }

        $script_url = add_query_arg('ver', KODANOTE_VERSION, KODANOTE_PLUGIN_URL . 'assets/js/conversion-tracker.js');
        $article_id = '';
        if (is_singular('post')) {
            $post = get_post();
            if ($post && isset($post->ID)) {
                $article_id = get_post_meta($post->ID, '_kodanote_article_id', true);
                $article_id = $article_id ? (string) absint($article_id) : '';
            }
        }

        echo "\n<script id=\"kodanote-conversion-tracker\" defer src=\"" . esc_url($script_url) . "\" data-endpoint=\"" . esc_url(rest_url('kodanote/v1/conversion-event')) . "\" data-pageview-endpoint=\"" . esc_url(rest_url('kodanote/v1/article-pageview')) . "\" data-token=\"" . esc_attr($this->get_conversion_tracker_token()) . "\" data-article-id=\"" . esc_attr($article_id) . "\"></script>\n";
    }

    /**
     * Build a short-lived token for anonymous conversion beacons.
     */
    private function get_conversion_tracker_token($bucket = null) {
        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            return '';
        }

        $bucket = $bucket ?: floor(time() / HOUR_IN_SECONDS);
        return hash_hmac('sha256', site_url() . '|' . $bucket, $api_key);
    }


    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=kodanote-settings') . '">' . __('Settings', 'kodanote-content-publisher') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables if needed
        $this->create_tables();

        // Set default options
        add_option('kodanote_post_category', '1');
        add_option('kodanote_author_id', get_current_user_id());
        add_option('kodanote_debug_mode', '0');

        // If no API key, schedule auto-verification attempt on next admin page load
        // We can't do HTTP requests during activation, so we defer to admin_init
        if (empty(get_option('kodanote_api_key'))) {
            update_option('kodanote_show_setup_wizard', '1');
            if (trim(KODANOTE_API_BASE_URL) !== '') {
                update_option('kodanote_pending_auto_verification', '1');
            }
        }

        // Defer rewrite rules flush to next admin page load to avoid .htaccess
        // corruption on slow/managed hosts (e.g. GoDaddy) where direct flush
        // during activation can timeout or conflict with hosting-level .htaccess rules
        set_transient('kodanote_flush_rewrite_rules', '1', 300);
    }

    /**
     * Flush rewrite rules on next admin page load (deferred from activation).
     * This avoids writing to .htaccess during the activation hook, which can
     * fail or produce corrupt output on managed/slow hosting environments.
     */
    public function maybe_flush_rewrite_rules() {
        if (get_transient('kodanote_flush_rewrite_rules')) {
            delete_transient('kodanote_flush_rewrite_rules');
            flush_rewrite_rules();
        }
    }

    /**
     * On the front end, copy post_content into empty ACF fields before the
     * theme template runs. This backfills articles published before 1.3.104.
     */
    public function maybe_sync_kodanote_content_to_acf() {
        if (is_admin() || !is_singular()) {
            return;
        }

        $post = get_queried_object();
        if (!$post || empty($post->ID) || !$this->is_kodanote_article($post->ID)) {
            return;
        }

        if (!function_exists('acf_get_field_groups')) {
            return;
        }

        if (get_post_meta($post->ID, '_kodanote_acf_synced', true)) {
            return;
        }

        $publisher = new Kodanote_Publisher();
        $publisher->sync_content_to_acf_fields($post->ID);
    }

    /**
     * Fixed author-box phrases. Keep these in sync with Article::authorBoxLabels()
     * in the Laravel app. "theme" is the short byline some themes print as "by:".
     *
     * @return array<string, array{box: string, theme: string}>
     */
    private function author_byline_phrases() {
        return array(
            'da' => array('box' => 'Artikel af', 'theme' => 'af:'),
            'de' => array('box' => 'Artikel von', 'theme' => 'von:'),
            'es' => array('box' => 'Artículo por', 'theme' => 'por:'),
            'fr' => array('box' => 'Article par', 'theme' => 'par:'),
            'ja' => array('box' => '記事作成者', 'theme' => ''),
            'nl' => array('box' => 'Artikel door', 'theme' => 'door:'),
            'pl' => array('box' => 'Artykuł autorstwa', 'theme' => ''),
            'sv' => array('box' => 'Artikel av', 'theme' => 'av:'),
        );
    }

    /**
     * Phrases for the current WordPress locale, or null when the locale stays English.
     *
     * @return array{box: string, theme: string}|null
     */
    private function localized_byline_strings() {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $code = strtolower(substr(str_replace('_', '-', (string) $locale), 0, 2));
        $phrases = $this->author_byline_phrases();

        return isset($phrases[$code]) ? $phrases[$code] : null;
    }

    /**
     * Replace the English author line in post HTML for the current site language.
     *
     * @param mixed $content
     * @return mixed
     */
    public function localize_author_box_label_in_content($content) {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        return $this->localize_public_author_bylines($content);
    }

    /**
     * Blog archive pages print "by:" outside the post body. Buffer those pages
     * and replace that theme text. Singular Kodanote posts use the existing
     * theme-fallback buffer instead, so the two buffers do not nest.
     */
    public function maybe_localize_archive_author_bylines() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_singular()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (!is_home() && !is_archive() && !is_search()) {
            return;
        }
        if ($this->localized_byline_strings() === null) {
            return;
        }

        ob_start(array($this, 'localize_public_author_bylines'));
        add_action('shutdown', array($this, 'flush_author_byline_buffer'), 0);
    }

    /**
     * Flush the archive byline buffer before PHP shutdown.
     */
    public function flush_author_byline_buffer() {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * Run the empty-theme repair, then localize author lines in the full page.
     *
     * @param mixed $html
     * @return string
     */
    public function finish_theme_fallback_html($html) {
        $html = $this->inject_kodanote_content_into_empty_theme($html);

        return $this->localize_public_author_bylines(is_string($html) ? $html : '');
    }

    /**
     * Replace "Article by" in the author box and "by:" in theme blog cards.
     * Always returns a string. A null return from an output-buffer callback
     * blanks the page.
     *
     * @param mixed $html
     * @return string
     */
    public function localize_public_author_bylines($html) {
        if (!is_string($html) || $html === '') {
            return is_string($html) ? $html : '';
        }

        $strings = $this->localized_byline_strings();
        if ($strings === null) {
            return $html;
        }

        if ($strings['box'] !== '' && $strings['box'] !== 'Article by') {
            $replaced = preg_replace(
                '/>\s*Article by\s*<\/p>/i',
                '>' . $strings['box'] . '</p>',
                $html
            );
            if (is_string($replaced)) {
                $html = $replaced;
            }
        }

        if ($strings['theme'] !== '') {
            $html = str_replace(
                '<span class="meta">by:</span>',
                '<span class="meta">' . $strings['theme'] . '</span>',
                $html
            );
        }

        return $html;
    }

    /**
     * Start an output buffer so we can inject the article when the theme
     * renders an empty shell (ACF bricks, custom title fields, etc.).
     */
    public function maybe_buffer_kodanote_theme_fallback() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (!is_singular()) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ($request_uri !== '' && preg_match('/\.md(?:\?|$)/', $request_uri)) {
            return;
        }

        $post = get_queried_object();
        if (!$post || empty($post->post_content) || !$this->is_kodanote_article($post->ID)) {
            return;
        }

        ob_start(array($this, 'finish_theme_fallback_html'));

        // Flush the buffer at shutdown priority 0 — before other shutdown handlers
        // run. Relying on PHP's implicit buffer flush during shutdown can trigger
        // HTTP 500 on servers that escalate late PHP warnings (PCRE JIT stack, etc.)
        // to error status codes.
        add_action('shutdown', array($this, 'flush_theme_fallback_buffer'), 0);
    }

    /**
     * Explicitly end+flush the theme-fallback output buffer before PHP shutdown
     * handlers fire. This prevents late PCRE/memory warnings inside the callback
     * from being interpreted as a request failure by the web server.
     */
    public function flush_theme_fallback_buffer() {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * Fill empty theme title/body containers when the theme did not print
     * the WordPress post body. Must always return a string — a null return
     * from this output-buffer callback blanks the entire page.
     *
     * @param string $html Full page HTML
     * @return string
     */
    public function inject_kodanote_content_into_empty_theme($html) {
        $original_html = is_string($html) ? $html : '';

        try {
            if ($original_html === '') {
                return $original_html;
            }

            // Some themes clone the_content() into "load more" pages. Collapse
            // those copies before the empty-theme injection check, so crawlers
            // and visitors see one article. Stored post_content is not changed.
            $html = Kodanote_Rendered_Content::collapse_repeated_copies($original_html);
            if (!is_string($html) || $html === '') {
                $html = $original_html;
            }

            $post = get_queried_object();
            if (!$post || empty($post->post_content)) {
                return $html;
            }

            if ($this->kodanote_content_appears_in_html($html, $post->post_content)) {
                return $html;
            }

            $title = get_the_title($post);
            if ($title !== '' && strpos($html, '</h1>') !== false) {
                $replaced = $this->safe_preg_replace_callback(
                    '/(<h1\b[^>]*\bclass="[^"]*\btitle\b[^"]*"[^>]*>)\s*(<\/h1>)/i',
                    function ($match) use ($title) {
                        return $match[1] . esc_html($title) . $match[2];
                    },
                    $html,
                    1
                );
                if (is_string($replaced)) {
                    $html = $replaced;
                }
            }

            // Use stored HTML as-is. Re-running the_content() inside an
            // output-buffer callback can fatal with cache plugins (LiteSpeed,
            // WP Rocket) and wipe the page.
            $content = $post->post_content;
            if (!is_string($content) || trim(wp_strip_all_tags($content)) === '') {
                return $html;
            }

            $block = '<div class="kodanote-theme-content-fallback">' . $content . '</div>';
            $injected = $this->insert_kodanote_fallback_block($html, $block);

            return is_string($injected) ? $injected : $html;
        } catch (\Throwable $e) {
            return $original_html;
        }
    }

    /**
     * Insert fallback HTML into a known-empty theme content container only.
     * Does not inject after a random </h1> — that broke headers and sidebars.
     *
     * @param string $html  Page HTML
     * @param string $block Markup to insert
     * @return string|null
     */
    private function insert_kodanote_fallback_block($html, $block) {
        if (!is_string($html) || $html === '') {
            return null;
        }

        $patterns = array(
            '/(<div\b[^>]*\bclass="[^"]*\bcomponent-group-flexible\b[^"]*"[^>]*>)\s*(<\/div>)/i',
            '/(<div\b[^>]*\bclass="[^"]*\b(entry-content|post-content|article-content)\b[^"]*"[^>]*>)\s*(<\/div>)/i',
        );

        foreach ($patterns as $pattern) {
            $count = 0;
            $replaced = $this->safe_preg_replace_callback(
                $pattern,
                function ($match) use ($block) {
                    return $match[1] . $block . $match[count($match) - 1];
                },
                $html,
                1,
                $count
            );
            if (is_string($replaced) && $count > 0) {
                return $replaced;
            }
        }

        $count = 0;
        $replaced = $this->safe_preg_replace_callback(
            '/(<div\b[^>]*\bclass="[^"]*\bnews-single-socials\b[^"]*")/i',
            function ($match) use ($block) {
                return $block . $match[1];
            },
            $html,
            1,
            $count
        );
        if (is_string($replaced) && $count > 0) {
            return $replaced;
        }

        return null;
    }

    /**
     * preg_replace_callback that keeps the original subject when PCRE fails.
     *
     * @param string   $pattern  Regex
     * @param callable $callback Replacement
     * @param string   $subject  HTML
     * @param int      $limit    Match limit
     * @param int      $count    Match count (output)
     * @return string
     */
    private function safe_preg_replace_callback($pattern, $callback, $subject, $limit = 1, &$count = 0) {
        $count = 0;
        if (!is_string($subject)) {
            return '';
        }

        $result = preg_replace_callback($pattern, $callback, $subject, $limit, $count);
        return is_string($result) ? $result : $subject;
    }

    /**
     * True when a distinctive snippet of the stored article is already visible.
     *
     * @param string $html         Rendered page HTML
     * @param string $post_content Stored post_content
     * @return bool
     */
    private function kodanote_content_appears_in_html($html, $post_content) {
        $plain_content = $this->normalize_plain_text_for_theme_fallback($post_content);
        if (strlen($plain_content) < 40) {
            return true;
        }

        $plain_html = $this->normalize_plain_text_for_theme_fallback($html);
        if ($plain_html === '') {
            return false;
        }

        $offsets = array(0, 160, 400);
        foreach ($offsets as $offset) {
            if ($offset >= strlen($plain_content)) {
                break;
            }
            $snippet = trim(substr($plain_content, $offset, 80));
            if (strlen($snippet) < 40) {
                continue;
            }
            if (stripos($plain_html, $snippet) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip tags and normalize quotes so wptexturize curly apostrophes still match.
     *
     * @param string $text Raw HTML or text
     * @return string
     */
    private function normalize_plain_text_for_theme_fallback($text) {
        if (!is_string($text) || $text === '') {
            return '';
        }

        $plain = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');

        // Avoid /u (Unicode) flag — on large pages (100 KB+) it exhausts the PCRE
        // JIT stack, emitting a PHP Warning that some servers escalate to HTTP 500.
        $plain = preg_replace('/\s+/', ' ', $plain);
        if (!is_string($plain)) {
            return '';
        }

        $plain = str_replace(
            array("\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x93", "\xE2\x80\x94"),
            array("'", "'", '"', '"', '-', '-'),
            $plain
        );

        return trim($plain);
    }

    /**
     * ACF field groups often hide the WordPress content editor. Kodanote stores
     * the article in post_content, so keep that editor visible on our posts.
     *
     * @param array $group ACF field group
     * @return array
     */
    public function keep_content_editor_visible_for_kodanote_posts($group) {
        if (empty($group['hide_on_screen']) || !is_admin()) {
            return $group;
        }

        $post_id = 0;
        if (!empty($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_id = absint(wp_unslash($_GET['post']));
        } elseif (!empty($_POST['post_ID'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $post_id = absint(wp_unslash($_POST['post_ID']));
        }

        if (!$post_id || !$this->is_kodanote_article($post_id)) {
            return $group;
        }

        $group['hide_on_screen'] = array_values(array_diff((array) $group['hide_on_screen'], array('the_content')));
        return $group;
    }

    /**
     * Clear full-page caches so a previously empty article URL is rebuilt.
     */
    private function purge_page_caches_after_content_fix() {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
        }
    }

    /**
     * Ensure database schema is up to date (runs on every init for cron compatibility)
     *
     * Throttling uses a plain (autoloaded) option storing the last-check timestamp
     * rather than a transient. A transient's timeout row is auto-deleted by
     * get_transient() the moment it expires, so under concurrent traffic (regular
     * requests + cron) many init requests would race on the SAME
     * `DELETE FROM {prefix}_options WHERE option_name = '_transient_kodanote_schema_check_...'`
     * query and hit repeated InnoDB deadlocks. Reading an autoloaded option is served
     * from the alloptions cache and never issues a DELETE, which removes the race.
     */
    public function ensure_db_schema() {
        // Guard against running more than once within a single request.
        static $checked_this_request = false;
        if ($checked_this_request) {
            return;
        }
        $checked_this_request = true;

        // Only run once per hour (timestamp-based throttling via a plain option).
        $option_key = 'kodanote_schema_check_' . KODANOTE_VERSION;
        $last_check  = (int) get_option($option_key, 0);
        if ($last_check > 0 && (time() - $last_check) < HOUR_IN_SECONDS) {
            return;
        }

        // Record the check timestamp up-front (autoload=no) so concurrent requests
        // skip the migration block immediately, shrinking the race window. update_option
        // performs an UPDATE on a single row rather than the DELETE storm a transient causes.
        update_option($option_key, time(), false);

        // Run all idempotent migrations (they check if column exists first).
        // Wrapped defensively so a transient DB hiccup can never turn into an
        // every-request error loop.
        try {
            // Recreate the articles table first: the column migrations below are
            // no-ops (and log errors) if the base table is missing entirely.
            $this->create_articles_table_if_missing();
            $this->add_featured_image_column();
            $this->add_hero_image_column();
            $this->add_infographic_column();
            $this->add_infographic_image_column();
            $this->add_meta_description_columns();
            $this->add_wordpress_tags_column();
            $this->add_content_markdown_column();
            $this->add_intended_published_at_column();
            $this->add_faq_schema_column();
            $this->add_hero_image_alt_column();
            $this->add_previous_article_ids_column();
            $this->add_language_column();
            $this->add_source_article_id_column();
            $this->add_slug_column();
            $this->create_settings_table_if_missing();
            $this->add_settings_created_at_column();
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[Kodanote] ensure_db_schema migration error: ' . $e->getMessage());
        }
    }

    /**
     * Check if plugin needs upgrade (runs database migrations on plugin update)
     */
    public function check_plugin_upgrade() {
        $installed_version = get_option('kodanote_db_version', '1.0.0');
        
        // ALWAYS run idempotent migrations (they check if column exists first)
        // This ensures columns are added even if version was updated before migration code existed
        // Recreate the base articles table first so the column migrations have a
        // table to operate on (handles a dropped/never-created articles table).
        $this->create_articles_table_if_missing();
        $this->add_featured_image_column();
        $this->add_hero_image_column();
        $this->add_infographic_column();
        $this->add_infographic_image_column();
        $this->add_meta_description_columns();
        $this->add_wordpress_tags_column();
        $this->add_content_markdown_column();
        $this->add_intended_published_at_column();
        $this->add_faq_schema_column();
        $this->add_hero_image_alt_column();
        $this->add_previous_article_ids_column();
        $this->add_language_column();
        $this->add_source_article_id_column();
        $this->add_slug_column();
        $this->add_recreate_count_column();
        $this->create_settings_table_if_missing();
        $this->add_settings_created_at_column();
        $this->maybe_convert_tables_to_utf8mb4();
        
        // One-time duplicate cleanup (added in 1.3.43)
        if (version_compare($installed_version, '1.3.43', '<')) {
            $this->cleanup_duplicate_posts();
        }

        // Release any sync lock stranded by a crashed or timed-out sync on the
        // previous version. Without this a site that got stuck stays stuck, because
        // every later sync bails out on a lock whose owner will never return.
        if (version_compare($installed_version, '1.3.102', '<')) {
            $this->clear_stranded_sync_locks();
        }

        // ACF/theme-fallback content fix: existing Kodanote posts were stored in
        // post_content but never shown. Purge page caches so the new renderer
        // is not hidden behind a cached empty HTML file (e.g. WP Rocket).
        if (version_compare($installed_version, '1.3.104', '<')) {
            $this->purge_page_caches_after_content_fix();
        }

        // 1.3.105: the 1.3.104 theme-fallback buffer could return null / fatal
        // and cache a blank HTML page. Purge so those cached empties are rebuilt.
        if (version_compare($installed_version, '1.3.105', '<')) {
            $this->purge_page_caches_after_content_fix();
        }

        // If installed version is less than current, log the upgrade
        if (version_compare($installed_version, KODANOTE_VERSION, '<')) {
            // Update the stored version
            update_option('kodanote_db_version', KODANOTE_VERSION);
            
            // Log the upgrade
            if (get_option('kodanote_debug_mode', '0') === '1') {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Plugin upgraded from ' . $installed_version . ' to ' . KODANOTE_VERSION);
            }
        }
    }

    /**
     * Remove duplicate WordPress posts created by Kodanote.
     * Groups posts by _kodanote_article_id meta, keeps the newest, trashes the rest.
     */
    private function cleanup_duplicate_posts() {
        global $wpdb, $kodanote_allow_trash;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $duplicates = $wpdb->get_results(
            "SELECT meta_value AS kodanote_id, COUNT(*) AS cnt, GROUP_CONCAT(post_id ORDER BY post_id ASC) AS post_ids
             FROM {$wpdb->postmeta}
             INNER JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
             WHERE meta_key = '_kodanote_article_id'
               AND {$wpdb->posts}.post_status NOT IN ('trash', 'auto-draft')
             GROUP BY meta_value
             HAVING cnt > 1"
        );

        if (empty($duplicates)) {
            return;
        }

        $trashed_count = 0;
        $table_name = $wpdb->prefix . 'kodanote_articles';

        foreach ($duplicates as $dup) {
            $post_ids = array_map('intval', explode(',', $dup->post_ids));
            $keep_id = array_pop($post_ids); // Keep the newest (highest ID)

            $all_trashed = true;
            $kodanote_allow_trash = true;
            foreach ($post_ids as $trash_id) {
                $result = wp_trash_post($trash_id);
                if ($result) {
                    $trashed_count++;
                    $this->log_debug(sprintf(
                        'Duplicate cleanup: trashed post %d (duplicate of kodanote_id %s, keeping post %d)',
                        $trash_id, $dup->kodanote_id, $keep_id
                    ));
                } else {
                    $all_trashed = false;
                    $this->log_debug(sprintf(
                        'Duplicate cleanup: FAILED to trash post %d (kodanote_id %s) — skipping sync table update for this group',
                        $trash_id, $dup->kodanote_id
                    ));
                }
            }
            $kodanote_allow_trash = false;

            // Only update sync table if all duplicates were successfully trashed
            if ($all_trashed) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update(
                    $table_name,
                    array('post_id' => $keep_id, 'status' => 'published'),
                    array('kodanote_id' => $dup->kodanote_id),
                    array('%d', '%s'),
                    array('%s')
                );
            }
        }

        if ($trashed_count > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[Kodanote] Duplicate cleanup: trashed %d duplicate posts across %d articles',
                $trashed_count,
                count($duplicates)
            ));
        }
    }

    /**
     * Attempt auto-verification on first admin page load after activation
     * This runs once after plugin activation to try keyless verification
     */
    public function maybe_auto_verify_on_activation() {
        // Check if we have a pending auto-verification
        if (get_option('kodanote_pending_auto_verification') !== '1') {
            return;
        }
        
        // Already have an API key? No need to verify
        if (!empty(get_option('kodanote_api_key'))) {
            delete_option('kodanote_pending_auto_verification');
            return;
        }
        
        // Clear the flag immediately to prevent multiple attempts
        delete_option('kodanote_pending_auto_verification');
        
        $this->log_debug('Attempting auto-verification on first load after activation');
        
        // Attempt auto-verification
        $result = $this->attempt_auto_verification();
        
        if (!is_wp_error($result) && isset($result['success']) && $result['success']) {
            // Success! API key was stored by attempt_auto_verification()
            $this->log_debug('Auto-verification successful on activation!');
            
            // Trigger initial article sync
            $api = new Kodanote_API();
            $sync_result = $api->sync_articles();
            
            if (!is_wp_error($sync_result)) {
                $this->log_debug('Initial sync completed: ' . ($sync_result['synced_count'] ?? 0) . ' articles');
            }
            
            // Redirect to dashboard with success message
            if (!headers_sent()) {
                wp_safe_redirect(admin_url('admin.php?page=kodanote&auto_verified=1'));
                exit;
            }
        } else {
            // Failed - show the setup wizard
            $this->log_debug('Auto-verification failed on activation: ' . (is_wp_error($result) ? $result->get_error_message() : 'Unknown error'));
            update_option('kodanote_show_setup_wizard', '1');
        }
    }

    /**
     * Check if setup wizard should be shown
     */
    public function check_setup_wizard() {
        if (get_option('kodanote_show_setup_wizard') === '1' && empty(get_option('kodanote_api_key'))) {
            add_action('admin_notices', array($this, 'setup_wizard_notice'));
        }
    }

    /**
     * Add setup menu page
     */
    public function add_setup_menu() {
        if (get_option('kodanote_show_setup_wizard') === '1' && empty(get_option('kodanote_api_key'))) {
            add_menu_page(
                __('Kodanote Setup', 'kodanote-content-publisher'),
                __('Kodanote Setup', 'kodanote-content-publisher'),
                'manage_options',
                'kodanote-setup',
                array($this, 'setup_page'),
                'dashicons-admin-tools',
                2
            );
        }
    }

    /**
     * Setup wizard notice
     */
    public function setup_wizard_notice() {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($page === 'kodanote-setup') {
            return; // Don't show notice on setup page itself
        }
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e('Kodanote Setup Required', 'kodanote-content-publisher'); ?></strong><br>
                <?php esc_html_e('Welcome to Kodanote! Please complete the setup by entering your API key.', 'kodanote-content-publisher'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kodanote-setup')); ?>" class="button button-primary" style="margin-left: 10px;">
                    <?php esc_html_e('Complete Setup', 'kodanote-content-publisher'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Setup page content
     */
    public function setup_page() {
        $setup_complete = false;
        $sync_result = null;
        $synced_count = 0;
        $auto_verify_result = null;
        $show_manual_form = true; // Default to showing manual form
        
        // Check if auto-verification was requested via AJAX (handled separately)
        // Or if manual API key was submitted
        if (isset($_POST['kodanote_api_key']) && isset($_POST['kodanote_setup_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kodanote_setup_nonce'])), 'kodanote_setup')) {
            $api_key = sanitize_text_field(wp_unslash($_POST['kodanote_api_key']));
            if (!empty($api_key)) {
                update_option('kodanote_api_key', $api_key);
                
                // Track when API key was first set for adaptive sync scheduling
                // Only set if not already set (don't reset on API key change)
                if (!get_option('kodanote_api_key_set_time')) {
                    update_option('kodanote_api_key_set_time', time());
                    
                    // Reschedule sync with aggressive interval for new setup
                    wp_clear_scheduled_hook('kodanote_auto_sync');
                    if (trim(KODANOTE_API_BASE_URL) !== '') {
                        wp_schedule_event(time(), 'kodanote_every_minute', 'kodanote_auto_sync');
                    }
                }
                
                delete_option('kodanote_show_setup_wizard');
                $setup_complete = true;

                // Automatically sync articles after setup
                $api = new Kodanote_API();
                $sync_result = $api->sync_articles();
                
                if (!is_wp_error($sync_result) && isset($sync_result['synced_count'])) {
                    $synced_count = $sync_result['synced_count'];
                }
            }
        }

        if ($setup_complete) {
            // Show success confirmation
            ?>
            <div class="wrap">
                <div style="max-width: 600px; margin: 50px auto; padding: 40px; background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                            <svg style="width: 48px; height: 48px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h1 style="color: #047857; font-size: 2.2em; margin-bottom: 10px;">
                            <?php esc_html_e('Setup Complete!', 'kodanote-content-publisher'); ?>
                        </h1>
                        <p style="color: #6b7280; font-size: 1.1em; margin-bottom: 30px;">
                            <?php esc_html_e('Your Kodanote plugin is now configured and ready to use.', 'kodanote-content-publisher'); ?>
                        </p>
                    </div>

                    <?php if (!is_wp_error($sync_result) && $synced_count > 0): ?>
                    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #047857; font-size: 18px;">
                            🎉 <?php esc_html_e('First Sync Complete!', 'kodanote-content-publisher'); ?>
                        </h3>
                        <p style="margin: 0 0 12px 0; color: #374151; font-size: 16px;">
                            <?php
                            /* translators: %d: number of articles synced */
                            printf(esc_html__('Successfully synced <strong>%d article(s)</strong> from your Kodanote account!', 'kodanote-content-publisher'), (int) $synced_count);
                            ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px; color: #374151; line-height: 1.8;">
                            <li><?php esc_html_e('Visit your dashboard to view and manage synced articles', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Articles are ready to be published to your site', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Configure publishing settings and preferences', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Articles will sync automatically going forward', 'kodanote-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php elseif (!is_wp_error($sync_result) && $synced_count === 0): ?>
                    <div style="background: #fef3c7; border: 2px solid #fbbf24; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #92400e; font-size: 18px;">
                            ℹ️ <?php esc_html_e('No Articles Found', 'kodanote-content-publisher'); ?>
                        </h3>
                        <p style="margin: 0 0 12px 0; color: #78350f; font-size: 16px;">
                            <?php esc_html_e('Your Kodanote account doesn\'t have any articles yet.', 'kodanote-content-publisher'); ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px; color: #78350f; line-height: 1.8;">
                            <li><?php esc_html_e('Visit your Kodanote dashboard to create your first article', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Once created, articles will automatically sync to WordPress', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Configure publishing settings in the meantime', 'kodanote-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php elseif (is_wp_error($sync_result)): ?>
                    <div style="background: #fef2f2; border: 2px solid #fca5a5; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #991b1b; font-size: 18px;">
                            ⚠️ <?php esc_html_e('Sync Issue', 'kodanote-content-publisher'); ?>
                        </h3>
                        <p style="margin: 0 0 12px 0; color: #7f1d1d;">
                            <?php echo esc_html($sync_result->get_error_message()); ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px; color: #7f1d1d; line-height: 1.8;">
                            <li><?php esc_html_e('Check your API key is correct in Settings', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Try syncing manually from your dashboard', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Contact support if the issue persists', 'kodanote-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <h3 style="margin: 0 0 12px 0; color: #047857; font-size: 18px;">
                            ✅ <?php esc_html_e('What\'s Next?', 'kodanote-content-publisher'); ?>
                        </h3>
                        <ul style="margin: 0; padding-left: 20px; color: #374151; line-height: 1.8;">
                            <li><?php esc_html_e('Visit your Kodanote dashboard to manage articles', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Sync articles from your Kodanote account', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Configure publishing settings and preferences', 'kodanote-content-publisher'); ?></li>
                            <li><?php esc_html_e('Start publishing SEO-optimized content automatically', 'kodanote-content-publisher'); ?></li>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                        <label for="kodanote-setup-category-manual" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 15px;">
                            <?php esc_html_e('This is the category your articles will be published in:', 'kodanote-content-publisher'); ?>
                        </label>
                        <?php wp_dropdown_categories(array(
                            'name' => 'kodanote_setup_category_manual',
                            'id' => 'kodanote-setup-category-manual',
                            'selected' => get_option('kodanote_post_category', '1'),
                            'hide_empty' => false,
                            'show_option_none' => __('Select Category', 'kodanote-content-publisher'),
                            'class' => 'kodanote-category-select',
                        )); ?>
                        <p style="margin: 6px 0 0; color: #9ca3af; font-size: 13px;">
                            <?php esc_html_e('You can change this later in Settings.', 'kodanote-content-publisher'); ?>
                        </p>
                    </div>

                    <div style="text-align: center;">
                        <button type="button" id="kodanote-manual-continue-btn" onclick="kodanoteSaveCategoryAndContinue('manual')" 
                            style="display: inline-block; background: linear-gradient(135deg, #0d877a, #08695f); color: white; border: none; padding: 14px 32px; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s;"
                            onmouseover="this.style.transform='translateY(-2px)'"
                            onmouseout="this.style.transform='translateY(0)'">
                            🎯 <?php esc_html_e('Go to Kodanote Dashboard', 'kodanote-content-publisher'); ?>
                        </button>
                    </div>

                    <div style="text-align: center; margin-top: 20px;">
                        <p style="color: #9ca3af; font-size: 14px;">
                            <?php esc_html_e('Need help? Contact Kodanote support for assistance.', 'kodanote-content-publisher'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <style>
                .kodanote-category-select { width: 100%; padding: 10px 12px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 15px; background: #fff; cursor: pointer; }
                .kodanote-category-select:focus { border-color: #0d877a; outline: none; }
            </style>
            <script>
            function kodanoteSaveCategoryAndContinue(variant) {
                var select = document.getElementById('kodanote-setup-category-manual');
                var btn = document.getElementById('kodanote-manual-continue-btn');
                var categoryId = select ? select.value : '0';

                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.textContent = '<?php echo esc_js(__('Saving...', 'kodanote-content-publisher')); ?>';

                if (categoryId && categoryId !== '-1' && categoryId !== '0') {
                    var formData = new FormData();
                    formData.append('action', 'kodanote_save_post_category');
                    formData.append('nonce', '<?php echo esc_js(wp_create_nonce('kodanote_ajax_nonce')); ?>');
                    formData.append('category_id', categoryId);

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }).then(function() {
                        window.location.href = '<?php echo esc_url(admin_url('admin.php?page=kodanote&setup=complete')); ?>';
                    }).catch(function() {
                        window.location.href = '<?php echo esc_url(admin_url('admin.php?page=kodanote&setup=complete')); ?>';
                    });
                } else {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=kodanote&setup=complete')); ?>';
                }
            }
            </script>
            <?php
            return;
        }

        // Show setup form with auto-verification
        $site_url = site_url();
        $is_localhost = (strpos($site_url, 'localhost') !== false || strpos($site_url, '127.0.0.1') !== false);
        ?>
        <div class="wrap">
            <div style="max-width: 600px; margin: 50px auto; padding: 40px; background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #1e40af; font-size: 2.5em; margin-bottom: 10px;">
                        🚀 <?php esc_html_e('Welcome to Kodanote', 'kodanote-content-publisher'); ?>
                    </h1>
                    <p style="color: #6b7280; font-size: 1.1em;">
                        <?php esc_html_e('Let\'s get you set up with automated article publishing!', 'kodanote-content-publisher'); ?>
                    </p>
                </div>

                <?php if (!$is_localhost): ?>
                <!-- Auto-Verification Section -->
                <div id="kodanote-auto-verify-section">
                    <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 2px solid #0d877a; border-radius: 12px; padding: 24px; margin-bottom: 25px; text-align: center;">
                        <div id="auto-verify-loading" style="display: block;">
                            <div style="margin-bottom: 16px;">
                                <svg style="width: 48px; height: 48px; margin: 0 auto; animation: spin 1s linear infinite;" viewBox="0 0 24 24" fill="none" stroke="#0d877a" stroke-width="2">
                                    <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                                </svg>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: #1e40af; font-size: 18px;">
                                <?php esc_html_e('Connecting to Kodanote...', 'kodanote-content-publisher'); ?>
                            </h3>
                            <p style="margin: 0; color: #0d877a; font-size: 14px;">
                                <?php esc_html_e('We\'re trying to verify your plugin automatically. This may take a few seconds.', 'kodanote-content-publisher'); ?>
                            </p>
                        </div>
                        
                        <div id="auto-verify-success" style="display: none;">
                            <div style="width: 64px; height: 64px; background: #10b981; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                                <svg style="width: 36px; height: 36px; color: white;" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: #047857; font-size: 20px;">
                                <?php esc_html_e('Connected Successfully!', 'kodanote-content-publisher'); ?>
                            </h3>
                            <p id="auto-verify-site-name" style="margin: 0 0 16px 0; color: #059669; font-size: 14px;"></p>

                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-top: 16px; text-align: left;">
                                <label for="kodanote-setup-category" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 14px;">
                                    <?php esc_html_e('This is the category your articles will be published in:', 'kodanote-content-publisher'); ?>
                                </label>
                                <?php wp_dropdown_categories(array(
                                    'name' => 'kodanote_setup_category',
                                    'id' => 'kodanote-setup-category',
                                    'selected' => get_option('kodanote_post_category', '1'),
                                    'hide_empty' => false,
                                    'show_option_none' => __('Select Category', 'kodanote-content-publisher'),
                                    'class' => 'kodanote-category-select',
                                )); ?>
                                <p style="margin: 6px 0 0; color: #9ca3af; font-size: 12px;">
                                    <?php esc_html_e('You can change this later in Settings.', 'kodanote-content-publisher'); ?>
                                </p>
                            </div>

                            <button type="button" id="kodanote-setup-continue-btn" onclick="kodanoteSaveCategoryAndContinue()" 
                                style="margin-top: 16px; width: 100%; background: linear-gradient(135deg, #0d877a, #08695f); color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                                <?php esc_html_e('Continue to Dashboard →', 'kodanote-content-publisher'); ?>
                            </button>
                        </div>
                        
                        <div id="auto-verify-failed" style="display: none;">
                            <div style="margin-bottom: 12px;">
                                <svg style="width: 48px; height: 48px; color: #f59e0b;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <h3 style="margin: 0 0 8px 0; color: #92400e; font-size: 16px;">
                                <?php esc_html_e('Auto-Connect Not Available', 'kodanote-content-publisher'); ?>
                            </h3>
                            <p id="auto-verify-error" style="margin: 0 0 12px 0; color: #b45309; font-size: 14px;"></p>
                            <p style="margin: 0; color: #6b7280; font-size: 13px;">
                                <?php esc_html_e('No problem! Just enter your API key below.', 'kodanote-content-publisher'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Manual API Key Form (shown if auto-verify fails or for localhost) -->
                <div id="kodanote-manual-form" style="<?php echo $is_localhost ? 'display: block;' : 'display: none;'; ?>">
                    <form method="post" action="">
                        <?php wp_nonce_field('kodanote_setup', 'kodanote_setup_nonce'); ?>

                        <div style="margin-bottom: 25px;">
                            <label for="kodanote_api_key" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">
                                <?php esc_html_e('Your Kodanote API Key', 'kodanote-content-publisher'); ?>
                            </label>
                            <input type="text"
                                   id="kodanote_api_key"
                                   name="kodanote_api_key"
                                   value="<?php echo esc_attr(get_option('kodanote_api_key', '')); ?>"
                                   placeholder="Enter your API key here..."
                                   style="width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 16px; transition: border-color 0.2s;"
                                   onfocus="this.style.borderColor='#0d877a'"
                                   onblur="this.style.borderColor='#e5e7eb'"
                                   required>
                            <p style="margin-top: 8px; color: #6b7280; font-size: 14px;">
                                <?php esc_html_e('You can find your API key in your Kodanote dashboard under Integrations.', 'kodanote-content-publisher'); ?>
                            </p>
                        </div>

                        <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 16px; margin-bottom: 25px;">
                            <h3 style="margin: 0 0 8px 0; color: #0c4a6e; font-size: 16px;">
                                ✅ <?php esc_html_e('What\'s Already Configured', 'kodanote-content-publisher'); ?>
                            </h3>
                            <ul style="margin: 0; padding-left: 20px; color: #374151;">
                                <li><?php esc_html_e('Articles will be published automatically when synced', 'kodanote-content-publisher'); ?></li>
                                <li><?php esc_html_e('Plugin verification is ready to use', 'kodanote-content-publisher'); ?></li>
                                <li><?php esc_html_e('Default category and author settings are configured', 'kodanote-content-publisher'); ?></li>
                            </ul>
                        </div>

                        <button type="submit"
                                style="width: 100%; background: linear-gradient(135deg, #0d877a, #08695f); color: white; border: none; padding: 14px 24px; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                            🎯 <?php esc_html_e('Complete Setup & Start Publishing', 'kodanote-content-publisher'); ?>
                        </button>
                    </form>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <p style="color: #9ca3af; font-size: 14px;">
                        <?php esc_html_e('Need help? Visit our documentation or contact support.', 'kodanote-content-publisher'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <style>
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            .wrap h1 {
                font-size: 2.5em !important;
                margin-bottom: 10px !important;
            }
            .notice-info {
                border-left-color: #0d877a !important;
            }
            .kodanote-category-select {
                width: 100%;
                padding: 10px 12px;
                border: 2px solid #e5e7eb;
                border-radius: 6px;
                font-size: 15px;
                background: #fff;
                cursor: pointer;
            }
            .kodanote-category-select:focus {
                border-color: #0d877a;
                outline: none;
            }
        </style>

        <script>
        function kodanoteSaveCategoryAndContinue(variant) {
            var selectId = variant === 'manual' ? 'kodanote-setup-category-manual' : 'kodanote-setup-category';
            var btnId = variant === 'manual' ? 'kodanote-manual-continue-btn' : 'kodanote-setup-continue-btn';
            var select = document.getElementById(selectId);
            var btn = document.getElementById(btnId);
            var categoryId = select ? select.value : '0';

            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.textContent = '<?php echo esc_js(__('Saving...', 'kodanote-content-publisher')); ?>';

            if (categoryId && categoryId !== '-1' && categoryId !== '0') {
                var formData = new FormData();
                formData.append('action', 'kodanote_save_post_category');
                formData.append('nonce', '<?php echo esc_js(wp_create_nonce('kodanote_ajax_nonce')); ?>');
                formData.append('category_id', categoryId);

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).then(function() {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=kodanote&setup=complete')); ?>';
                }).catch(function() {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=kodanote&setup=complete')); ?>';
                });
            } else {
                window.location.href = '<?php echo esc_url(admin_url('admin.php?page=kodanote&setup=complete')); ?>';
            }
        }
        </script>
        
        <?php if (!$is_localhost): ?>
        <script>
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                attemptAutoVerification();
            });
            
            function attemptAutoVerification() {
                var loadingEl = document.getElementById('auto-verify-loading');
                var successEl = document.getElementById('auto-verify-success');
                var failedEl = document.getElementById('auto-verify-failed');
                var errorMsgEl = document.getElementById('auto-verify-error');
                var siteNameEl = document.getElementById('auto-verify-site-name');
                var manualFormEl = document.getElementById('kodanote-manual-form');
                var autoVerifySection = document.getElementById('kodanote-auto-verify-section');
                
                // Make the AJAX request
                var formData = new FormData();
                formData.append('action', 'kodanote_auto_verify');
                formData.append('nonce', '<?php echo esc_js(wp_create_nonce('kodanote_ajax_nonce')); ?>');
                
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    
                    if (data.success) {
                        successEl.style.display = 'block';
                        if (data.data && data.data.site_name) {
                            siteNameEl.textContent = 'Connected to: ' + data.data.site_name;
                        }
                    } else {
                        // Failed - show manual form
                        failedEl.style.display = 'block';
                        if (data.data && data.data.message) {
                            errorMsgEl.textContent = data.data.message;
                        } else {
                            errorMsgEl.textContent = 'Could not connect automatically.';
                        }
                        
                        // Show manual form after a short delay
                        setTimeout(function() {
                            manualFormEl.style.display = 'block';
                        }, 500);
                    }
                })
                .catch(function(error) {
                    console.error('Auto-verification error:', error);
                    loadingEl.style.display = 'none';
                    failedEl.style.display = 'block';
                    errorMsgEl.textContent = 'Network error. Please enter your API key manually.';
                    manualFormEl.style.display = 'block';
                });
            }
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear any scheduled events (both legacy and current hooks)
        wp_clear_scheduled_hook('kodanote_sync_articles');
        wp_clear_scheduled_hook('kodanote_auto_sync');
        wp_clear_scheduled_hook('kodanote_rescan_published_urls');
        wp_clear_scheduled_hook('kodanote_publish_scheduled_article');

        // Remove the sitemap block we added to a physical robots.txt
        Kodanote_Sitemap::deactivate_cleanup();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create necessary database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Articles sync table
        $table_name = $wpdb->prefix . 'kodanote_articles';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            kodanote_id varchar(100) NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title text NOT NULL,
            slug varchar(200) DEFAULT NULL,
            content longtext NOT NULL,
            excerpt text,
            keywords text,
            meta_description varchar(320) DEFAULT NULL,
            meta_keywords varchar(500) DEFAULT NULL,
            wordpress_tags varchar(500) DEFAULT NULL,
            featured_image_url text,
            hero_image_url text,
            hero_image_alt text,
            infographic_html longtext,
            status varchar(20) DEFAULT 'pending',
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            published_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY kodanote_id (kodanote_id),
            KEY post_id (post_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Add featured_image_url column if it doesn't exist (for existing installations)
        $this->add_featured_image_column();
        
        // Add hero_image_url and infographic_html columns if they don't exist (for existing installations)
        $this->add_hero_image_column();
        $this->add_infographic_column();
        $this->add_infographic_image_column();
        
        // Add meta_description, meta_keywords, and wordpress_tags columns if they don't exist (for existing installations)
        $this->add_meta_description_columns();
        $this->add_wordpress_tags_column();
        
        // Add content_markdown column for LLM-friendly .md URLs
        $this->add_content_markdown_column();
        
        // Add intended_published_at column for correct publication dates
        $this->add_intended_published_at_column();
        
        // Add faq_schema column for FAQ structured data
        $this->add_faq_schema_column();
        
        // Add hero_image_alt column for image alt text
        $this->add_hero_image_alt_column();

        // Add previous_article_ids column for feedback rewrite version tracking
        $this->add_previous_article_ids_column();

        // Add language column for WPML integration
        $this->add_language_column();

        // Add source_article_id column for multilingual translation linking
        $this->add_source_article_id_column();

        // Add slug column so Kodanote can supply an English permalink for non-Latin titles
        $this->add_slug_column();

        // Add recreate_count column for auto-recovery of deleted posts
        $this->add_recreate_count_column();

        // Settings table for plugin-specific settings
        $settings_table = $wpdb->prefix . 'kodanote_settings';

        $settings_sql = "CREATE TABLE $settings_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        dbDelta($settings_sql);
    }

    /**
     * Add featured_image_url column to existing installations
     */
    private function add_featured_image_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'featured_image_url'
        ));

        if (empty($column_exists)) {
            // Add the column
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN featured_image_url TEXT AFTER keywords"
            );
        }
    }

    /**
     * Add hero_image_url column to existing installations
     */
    private function add_hero_image_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'hero_image_url'
        ));

        if (empty($column_exists)) {
            // Add the column after featured_image_url
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN hero_image_url TEXT AFTER featured_image_url"
            );
        }
    }

    /**
     * Add infographic_html column to existing installations
     */
    private function add_infographic_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'infographic_html'
        ));

        if (empty($column_exists)) {
            // Add the column after hero_image_url
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN infographic_html LONGTEXT AFTER hero_image_url"
            );
        }
    }

    /**
     * Add infographic_image_url column to existing installations
     */
    private function add_infographic_image_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'infographic_image_url'
        ));

        if (empty($column_exists)) {
            // Add the column after infographic_html
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN infographic_image_url TEXT AFTER infographic_html"
            );
        }
    }

    /**
     * Add meta_description and meta_keywords columns to existing installations
     */
    private function add_meta_description_columns() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if meta_description column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $meta_desc_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'meta_description'
        ));

        if (empty($meta_desc_exists)) {
            // Add the meta_description column (without AFTER clause for compatibility)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN meta_description VARCHAR(320) DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add meta_description column: ' . $wpdb->last_error);
            }
        }

        // Check if meta_keywords column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $meta_keywords_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'meta_keywords'
        ));

        if (empty($meta_keywords_exists)) {
            // Add the meta_keywords column (without AFTER clause for compatibility)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN meta_keywords VARCHAR(500) DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add meta_keywords column: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Add wordpress_tags column to existing installations
     */
    private function add_wordpress_tags_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if wordpress_tags column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'wordpress_tags'
        ));

        if (empty($column_exists)) {
            // Add the wordpress_tags column (without AFTER clause for compatibility)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN wordpress_tags VARCHAR(500) DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add wordpress_tags column: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Add content_markdown column to existing installations
     * This stores the original markdown content for LLM-friendly .md URLs
     */
    private function add_content_markdown_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if content_markdown column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'content_markdown'
        ));

        if (empty($column_exists)) {
            // Add the content_markdown column (LONGTEXT to match content column)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN content_markdown LONGTEXT"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add content_markdown column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added content_markdown column for LLM-friendly .md URLs');
            }
        }
    }

    /**
     * Add intended_published_at column to existing installations
     * This column stores the publication date from Kodanote so WordPress posts
     * can be created with the correct date instead of the sync time
     */
    private function add_intended_published_at_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Check if intended_published_at column already exists
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'intended_published_at'
        ));

        if (empty($column_exists)) {
            // Add the intended_published_at column (DATETIME to store the intended publication date)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN intended_published_at DATETIME DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add intended_published_at column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added intended_published_at column for correct publication dates');
            }
        }
    }

    /**
     * Add faq_schema column to existing installations.
     * Stores FAQPage structured data (JSON) for JSON-LD output.
     */
    private function add_faq_schema_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'faq_schema'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN faq_schema LONGTEXT DEFAULT NULL"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add faq_schema column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added faq_schema column for FAQ structured data');
            }
        }
    }

    /**
     * Add hero_image_alt column for image alt text
     */
    private function add_hero_image_alt_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'hero_image_alt'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN hero_image_alt TEXT DEFAULT NULL AFTER hero_image_url"
            );
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Failed to add hero_image_alt column: ' . $wpdb->last_error);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added hero_image_alt column for image alt text');
            }
        }
    }

    /**
     * Add previous_article_ids column for tracking feedback rewrite version chains.
     * When users rewrite articles via feedback, a new article ID is created.
     * This column stores the chain of previous IDs so the plugin can match
     * the new version to the existing WordPress post.
     */
    private function add_previous_article_ids_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'previous_article_ids'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN previous_article_ids TEXT DEFAULT NULL"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added previous_article_ids column to articles table');
            }
        }
    }

    /**
     * Add language column for WPML integration.
     * Stores the article language code (e.g. 'en', 'de', 'pl', 'it') so the
     * plugin can automatically assign the correct WPML language after publishing.
     */
    private function add_language_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'language'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN language VARCHAR(10) DEFAULT NULL"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added language column for WPML integration');
            }
        }
    }

    /**
     * Add source_article_id column so translated posts can be linked back to
     * their source-language article. Used to connect translations within WPML
     * and Polylang translation groups (for correct /de/ URLs and hreflang).
     */
    private function add_source_article_id_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'source_article_id'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN source_article_id VARCHAR(100) DEFAULT NULL"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added source_article_id column for multilingual translation linking');
            }
        }
    }

    /**
     * Add slug column so Kodanote can supply an English permalink for the post.
     * For non-Latin titles (e.g. Traditional Chinese) WordPress would otherwise
     * build an unreadable URL-encoded slug from the title; Kodanote now sends a
     * clean English slug which the publisher uses as the post_name on new posts.
     */
    private function add_slug_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'slug'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN slug VARCHAR(200) DEFAULT NULL AFTER title"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added slug column for English permalinks');
            }
        }
    }

    /**
     * Add recreate_count column to track how many times a deleted WP post
     * was automatically re-created. Prevents infinite re-creation loops
     * while allowing recovery from accidental deletions or Cloudflare blocks.
     */
    private function add_recreate_count_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'recreate_count'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table_name is escaped with esc_sql()
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN recreate_count TINYINT UNSIGNED NOT NULL DEFAULT 0"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added recreate_count column for auto-recovery of deleted posts');
            }
        }
    }

    /**
     * Delete sync lock rows left behind by a sync that never finished.
     */
    private function clear_stranded_sync_locks() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_settings';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists !== $table_name) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query(
            "DELETE FROM " . esc_sql($table_name) . " WHERE setting_key = 'sync_lock'"
        );

        if ($deleted) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[Kodanote] Cleared ' . (int) $deleted . ' stranded sync lock(s) on upgrade');
        }
    }

    /**
     * Recreate the kodanote_settings table if it is missing.
     *
     * Only plugin activation created this table, so a site that lost it (failed
     * activation, a migration tool, a partial restore) had no way to get it back
     * and the sync lock could never be taken.
     */
    private function create_settings_table_if_missing() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_settings';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists === $table_name) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;");

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[Kodanote] Recreated missing settings table');
    }

    /**
     * Recreate the main kodanote_articles table if it is completely missing.
     *
     * The table is normally created on activation, but it can disappear on some
     * hosts (a failed/interrupted activation, a database restore from a backup
     * taken before the plugin was installed, a host migration, or a manual drop).
     * When that happens every sync fails permanently with
     * "Table '..._kodanote_articles' doesn't exist", and the column migrations
     * (add_*_column) silently do nothing because there is no table to alter.
     * This self-heal mirrors create_settings_table_if_missing() so a missing
     * articles table repairs itself instead of stranding the customer.
     */
    public function create_articles_table_if_missing() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists === $table_name) {
            return;
        }

        // create_tables() is idempotent (dbDelta + column checks) and rebuilds the
        // full schema, so it safely brings a missing table back to current shape.
        $this->create_tables();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[Kodanote] Recreated missing kodanote_articles table');
    }

    /**
     * Ensure the kodanote_settings table has a created_at column.
     * Older installations may have been created without it, causing the sync lock
     * cleanup query to fail silently and permanently block syncs.
     */
    private function add_settings_created_at_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_settings';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM " . esc_sql($table_name) . " LIKE %s",
            'created_at'
        ));

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query(
                "ALTER TABLE " . esc_sql($table_name) . " ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP"
            );
            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Kodanote] Added created_at column to settings table');

                // Clear any stuck sync locks from before this migration
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->delete($table_name, array('setting_key' => 'sync_lock'), array('%s'));
            }
        }
    }

    /**
     * Convert kodanote_articles table to utf8mb4 if the database supports it.
     * utf8mb4 allows 4-byte characters (emoji, some CJK, etc.) that utf8 rejects.
     */
    private function maybe_convert_tables_to_utf8mb4() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Only attempt if WordPress itself uses utf8mb4
        $wp_charset = $wpdb->charset;
        if (empty($wp_charset) || strpos($wp_charset, 'utf8mb4') === false) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row("SHOW TABLE STATUS LIKE '{$table_name}'");
        if (!$row || !isset($row->Collation)) {
            return;
        }

        if (strpos($row->Collation, 'utf8mb4') !== false) {
            return;
        }

        $collate = $wpdb->collate;
        if (empty($collate)) {
            $collate = 'utf8mb4_unicode_ci';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query(
            "ALTER TABLE " . esc_sql($table_name) . " CONVERT TO CHARACTER SET utf8mb4 COLLATE " . esc_sql($collate)
        );

        if ($result !== false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[Kodanote] Converted ' . $table_name . ' to utf8mb4 for full Unicode support');
        }
    }

    /**
     * Main admin page
     */
    public function admin_page() {
        include KODANOTE_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    /**
     * Settings page
     */
    public function settings_page() {
        include KODANOTE_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    // Settings callbacks
    public function api_settings_section_callback() {
        echo '<p>' . esc_html__('Configure your Kodanote API connection settings.', 'kodanote-content-publisher') . '</p>';
    }

    public function publishing_settings_section_callback() {
        echo '<p>' . esc_html__('Configure how articles from Kodanote should be published on your site.', 'kodanote-content-publisher') . '</p>';
    }

    public function api_key_field_callback() {
        $value = get_option('kodanote_api_key');
        echo '<input type="text" name="kodanote_api_key" value="' . esc_attr($value) . '" class="regular-text" placeholder="Your Kodanote API Key">';
        echo '<p class="description">' . esc_html__('Get your API key from your Kodanote dashboard.', 'kodanote-content-publisher') . '</p>';
    }




    public function post_category_field_callback() {
        $value = get_option('kodanote_post_category', '1');
        wp_dropdown_categories(array(
            'name' => 'kodanote_post_category',
            'selected' => $value,
            'hide_empty' => false,
            'show_option_none' => __('Select Category', 'kodanote-content-publisher'),
        ));
    }

    public function author_field_callback() {
        $value = get_option('kodanote_author_id', get_current_user_id());
        wp_dropdown_users(array(
            'name' => 'kodanote_author_id',
            'selected' => $value,
            'show_option_none' => __('Select Author', 'kodanote-content-publisher'),
        ));
    }

    /**
     * Debug settings section callback
     */
    public function debug_settings_section_callback() {
        echo '<p>' . esc_html__('Configure debugging options for troubleshooting.', 'kodanote-content-publisher') . '</p>';
    }

    /**
     * Debug mode field callback
     */
    public function debug_mode_field_callback() {
        $value = get_option('kodanote_debug_mode', '0'); // Default to enabled
        echo '<input type="checkbox" name="kodanote_debug_mode" value="1" ' . checked(1, $value, false) . ' id="kodanote_debug_mode">';
        echo '<label for="kodanote_debug_mode">' . esc_html__('Enable debug logging to browser console', 'kodanote-content-publisher') . '</label>';
        echo '<p class="description">' . esc_html__('Debug information will be logged to your browser\'s console when enabled.', 'kodanote-content-publisher') . '</p>';
    }

    // AJAX handlers
    public function ajax_save_settings() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'kodanote-content-publisher'));
        }

        // Save settings logic here
        wp_send_json_success(array('message' => __('Settings saved successfully', 'kodanote-content-publisher')));
    }

    public function ajax_save_post_category() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'kodanote-content-publisher')));
        }

        $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
        if ($category_id > 0 && term_exists($category_id, 'category')) {
            update_option('kodanote_post_category', $category_id);
            wp_send_json_success(array('message' => __('Category saved', 'kodanote-content-publisher')));
        }

        wp_send_json_error(array('message' => __('Invalid category', 'kodanote-content-publisher')));
    }

    public function ajax_test_connection() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'kodanote-content-publisher'));
        }

        $api = new Kodanote_API();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            Kodanote_Scheduler::store_sync_error($error_message);
            wp_send_json_error(array(
                'message' => $error_message,
            ));
            return;
        }

        Kodanote_Scheduler::clear_sync_error();

        if (!get_option('kodanote_api_key_set_time')) {
            update_option('kodanote_api_key_set_time', time());
        }

        wp_clear_scheduled_hook('kodanote_auto_sync');
        if (trim(KODANOTE_API_BASE_URL) !== '') {
                        wp_schedule_event(time(), 'kodanote_every_minute', 'kodanote_auto_sync');
                    }

        wp_send_json_success(array(
            'message' => $result['message'] ?? __('Connection successful', 'kodanote-content-publisher'),
            'next_sync' => wp_next_scheduled('kodanote_auto_sync'),
        ));
    }

    public function ajax_sync_articles() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'kodanote-content-publisher'));
        }

        try {
            // Initialize API class
            $api = new Kodanote_API();
            
            // Call the sync method
            $result = $api->sync_articles();
            
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                Kodanote_Scheduler::store_sync_error($error_message);
                $stored_error = Kodanote_Scheduler::get_sync_error();
                wp_send_json_error(array(
                    'message' => $error_message,
                    'friendly_message' => is_array($stored_error) ? ($stored_error['friendly_message'] ?? null) : null,
                ));
                return;
            }
            
            Kodanote_Scheduler::clear_sync_error();
            wp_send_json_success(array(
                'message' => $result['message'],
                'synced_count' => $result['synced_count'],
                'errors' => $result['errors']
            ));
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            Kodanote_Scheduler::store_sync_error($error_message);
            $stored_error = Kodanote_Scheduler::get_sync_error();
            wp_send_json_error(array(
                'message' => 'Sync failed: ' . $error_message,
                'friendly_message' => is_array($stored_error) ? ($stored_error['friendly_message'] ?? null) : null,
            ));
        }
    }

    public function ajax_publish_article() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'kodanote-content-publisher'));
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        if (!$article_id) {
            wp_send_json_error(array('message' => __('Article ID is required.', 'kodanote-content-publisher')));
            return;
        }

        try {
            // Use the publisher class to publish the article
            $publisher = new Kodanote_Publisher();
            $result = $publisher->publish_article($article_id);

            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            } else {
                wp_send_json_success(array(
                    'message' => $result['message'],
                    'post_id' => $result['post_id']
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('Failed to publish article: ', 'kodanote-content-publisher') . $e->getMessage()));
        }
    }

    /**
     * AJAX handler for toggling debug mode
     */
    public function ajax_toggle_debug() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'kodanote-content-publisher')));
            return;
        }

        $enabled = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : '0';
        
        update_option('kodanote_debug_mode', $enabled);

        wp_send_json_success(array(
            'message' => __('Debug mode updated successfully', 'kodanote-content-publisher'),
            'enabled' => $enabled
        ));
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Public callback for keyless verification. It only responds when the
        // backend returns a one-time token created by this plugin installation.
        register_rest_route('kodanote/v1', '/handshake', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_handshake_callback'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('kodanote/v1', '/force-republish', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_force_republish'),
            'permission_callback' => array($this, 'rest_api_permission_check'),
            'args' => array(
                'article_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Kodanote article ID',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Trigger sync endpoint - allows Kodanote server to trigger immediate article sync.
        // Supports push mode: if 'articles' array is included, they are processed directly
        // without the plugin needing to make an outbound API call.
        register_rest_route('kodanote/v1', '/trigger-sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_trigger_sync'),
            'permission_callback' => array($this, 'rest_api_permission_check'),
            'args' => array(
                'auto_publish' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Whether to auto-publish synced articles (if site has auto-publish enabled)',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
                'articles' => array(
                    'required' => false,
                    'type' => 'array',
                    'default' => null,
                    'description' => 'Articles pushed from the server for direct processing (bypasses outbound API call)',
                    'validate_callback' => function ($value) {
                        if ($value === null) {
                            return true;
                        }
                        if (!is_array($value)) {
                            return new WP_Error('invalid_articles', 'articles must be an array');
                        }
                        foreach ($value as $article) {
                            if (!is_array($article) || empty($article['id']) || empty($article['title'])) {
                                return new WP_Error('invalid_article', 'Each article must have id and title');
                            }
                        }
                        return true;
                    },
                ),
            ),
        ));

        // Push image endpoint - allows Kodanote server to push images directly to WP media library.
        // Used after push-mode article sync to attach hero/infographic images without the WP server
        // needing to make outbound HTTPS requests to download them.
        register_rest_route('kodanote/v1', '/push-image', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_push_image'),
            'permission_callback' => array($this, 'rest_api_permission_check'),
        ));

        // Conversion event proxy - public endpoint called by frontend JS.
        // Forwards events to the Kodanote API server-side (keeps API key off the client).
        register_rest_route('kodanote/v1', '/conversion-event', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_conversion_event_proxy'),
            'permission_callback' => '__return_true',
        ));

        // Article pageview proxy - public endpoint called by frontend JS.
        // Forwards pageviews to Kodanote server-side (keeps API key off the client).
        register_rest_route('kodanote/v1', '/article-pageview', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_article_pageview_proxy'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Permission callback for REST API
     * Validates the API key and required HMAC signature from request headers.
     */
    public function rest_api_permission_check($request) {
        $token = $this->extract_api_token($request);

        if (empty($token)) {
            $this->log_auth_failure('missing_auth_header', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Authorization header is required', 'kodanote-content-publisher'),
                array('status' => 401)
            );
        }

        $stored_api_key = get_option('kodanote_api_key', '');
        
        if (empty($stored_api_key) || !hash_equals($stored_api_key, $token)) {
            $this->log_auth_failure('invalid_api_key', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Invalid API key', 'kodanote-content-publisher'),
                array('status' => 401)
            );
        }

        // HMAC signature verification (mandatory)
        $signature = $request->get_header('X-Kodanote-Signature');
        if (empty($signature)) {
            $query_params = $request->get_query_params();
            $signature = isset($query_params['_kodanote_sig']) ? $query_params['_kodanote_sig'] : '';
        }

        if (empty($signature)) {
            $this->log_auth_failure('missing_hmac_signature', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Request signature is required', 'kodanote-content-publisher'),
                array('status' => 401)
            );
        }

        $content_type = $request->get_header('Content-Type') ?? '';
        if (stripos($content_type, 'multipart/form-data') !== false) {
            $params = $request->get_params();
            unset($params['file'], $params['_kodanote_sig']);
            ksort($params);
            $signing_payload = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $signing_payload = $request->get_body();
        }

        $expected = hash_hmac('sha256', $signing_payload, $stored_api_key);
        if (!hash_equals($expected, $signature)) {
            $this->log_auth_failure('invalid_hmac_signature', $request);
            return new WP_Error(
                'rest_forbidden',
                __('Invalid request signature', 'kodanote-content-publisher'),
                array('status' => 401)
            );
        }

        // Rate limiting per endpoint
        $rate_limit_result = $this->check_rest_rate_limit($request);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }

        return true;
    }

    /**
     * Extract the API bearer token from the request, trying multiple sources.
     * Apache CGI/FastCGI often strips the Authorization header, so we check
     * several fallback locations.
     */
    private function extract_api_token($request) {
        // 1. Standard WP REST Request header (works when server passes it through)
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return $matches[1];
        }

        // 2. Custom header fallback (never stripped by Apache)
        $custom_key = $request->get_header('X-Kodanote-API-Key');
        if (!empty($custom_key)) {
            return $custom_key;
        }

        // 3. REDIRECT_HTTP_AUTHORIZATION (set by some Apache RewriteRule configs)
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $redirect_auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            if (preg_match('/^Bearer\s+(.+)$/i', $redirect_auth, $matches)) {
                return $matches[1];
            }
        }

        // 4. HTTP_AUTHORIZATION directly from $_SERVER (some setups)
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $server_auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^Bearer\s+(.+)$/i', $server_auth, $matches)) {
                return $matches[1];
            }
        }

        // 5. apache_request_headers() / getallheaders() as last resort
        if (function_exists('getallheaders')) {
            $all_headers = getallheaders();
            if (is_array($all_headers)) {
                foreach ($all_headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0 && preg_match('/^Bearer\s+(.+)$/i', $value, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }

        // 6. POST body fallback — some hosting configs strip ALL headers
        // (including custom X- headers). The POST body is never stripped.
        $body_key = $request->get_param('kodanote_api_key');
        if (!empty($body_key)) {
            return $body_key;
        }

        return '';
    }

    /**
     * Log failed authentication attempts for security monitoring.
     */
    private function log_auth_failure($reason, $request) {
        $ip = $this->get_client_ip();
        $endpoint = $request->get_route();
        $user_agent = $request->get_header('User-Agent') ?? 'unknown';
        $user_agent = preg_replace('/[\r\n\t]/', ' ', substr($user_agent, 0, 100));

        error_log(sprintf(
            '[Kodanote Security] Auth failure: reason=%s, ip=%s, endpoint=%s, ua=%s',
            $reason,
            $ip,
            $endpoint,
            $user_agent
        ));

        $transient_key = 'kodanote_auth_fail_' . md5($ip);
        $fail_count = (int) get_transient($transient_key);
        set_transient($transient_key, $fail_count + 1, 3600);

        if ($fail_count + 1 >= 10) {
            error_log(sprintf(
                '[Kodanote Security] WARNING: %d failed auth attempts from IP %s in the last hour',
                $fail_count + 1,
                $ip
            ));
        }
    }

    /**
     * Get the client IP address, respecting common proxy headers.
     */
    private function get_client_ip() {
        $headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }

    /**
     * Check rate limits for REST API endpoints using transients.
     * Returns true if within limits, WP_Error if exceeded.
     */
    private function check_rest_rate_limit($request) {
        $route = $request->get_route();

        $limits = array(
            // Authenticated server-side syncs can legitimately arrive in short
            // bursts while a queued publish is recovering from a host timeout.
            '/kodanote/v1/trigger-sync'    => array('max' => 60, 'window' => 60),
            '/kodanote/v1/force-republish' => array('max' => 10, 'window' => 60),
            '/kodanote/v1/push-image'      => array('max' => 60, 'window' => 60),
        );

        if (!isset($limits[$route])) {
            return true;
        }

        $limit = $limits[$route];
        $ip = $this->get_client_ip();
        $key = 'kodanote_rl_' . md5($route . '_' . $ip);

        $data = get_transient($key);
        if ($data === false) {
            set_transient($key, array('count' => 1, 'started' => time()), $limit['window']);
            return true;
        }

        if ($data['count'] >= $limit['max']) {
            $this->log_auth_failure('rate_limit_exceeded', $request);
            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    /* translators: %1$d: maximum number of requests allowed, %2$d: time window in seconds */
                    __('Rate limit exceeded. Max %1$d requests per %2$d seconds.', 'kodanote-content-publisher'),
                    $limit['max'],
                    $limit['window']
                ),
                array('status' => 429)
            );
        }

        $data['count']++;
        $remaining = $limit['window'] - (time() - $data['started']);
        if ($remaining > 0) {
            set_transient($key, $data, $remaining);
        }

        return true;
    }

    /**
     * REST API handler for handshake verification callback
     * 
     * This endpoint is called by the Kodanote backend to verify the plugin is installed.
     * It is public, but requires a one-time token created by this installation.
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_handshake_callback($request) {
        // Get the challenge token from the request header
        $challenge_token = $request->get_header('X-Kodanote-Challenge');
        $installation_token = $request->get_header('X-Kodanote-Installation-Token');
        $site_id = $request->get_header('X-Kodanote-Site-ID');
        
        $this->log_debug("Handshake callback received from Kodanote backend");
        $this->log_debug("Challenge token: " . ($challenge_token ? substr($challenge_token, 0, 10) . '...' : 'none'));
        $this->log_debug("Site ID: " . ($site_id ?: 'none'));

        // Verify the request is from Kodanote backend (check User-Agent)
        $user_agent = $request->get_header('User-Agent');
        if (strpos($user_agent, 'Kodanote-Handshake') === false) {
            $this->log_debug("Handshake rejected - invalid User-Agent: " . $user_agent);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid request origin',
            ), 403);
        }

        if (empty($challenge_token)) {
            $this->log_debug("Handshake rejected - no challenge token");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Challenge token required',
            ), 400);
        }

        $installation_token_key = 'kodanote_handshake_installation_token_' . hash('sha256', $installation_token);
        $installation_token_exists = get_transient($installation_token_key);
        if (
            empty($installation_token)
            || !is_string($installation_token_exists)
            || !hash_equals('1', $installation_token_exists)
        ) {
            $this->log_debug("Handshake rejected - invalid installation token");
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid or expired installation token',
            ), 403);
        }

        // Consume the token before returning the proof. A token can complete
        // only one handshake and never leaves this site except in its outbound
        // TLS request to Kodanote.
        delete_transient($installation_token_key);

        // Return the challenge token to prove we received it
        // Also include plugin version and site URL for verification
        $response = array(
            'success' => true,
            'challenge_response' => $challenge_token,
            'installation_token_proof' => hash_hmac('sha256', $challenge_token, $installation_token),
            'plugin_version' => KODANOTE_VERSION,
            'site_url' => home_url(),
            'wordpress_version' => get_bloginfo('version'),
        );

        $this->log_debug("Handshake successful - returning challenge response");

        return new WP_REST_Response($response, 200);
    }

    /**
     * Attempt automatic verification via handshake
     * 
     * Called when the plugin is activated or when API key is empty.
     * Makes a request to the Kodanote backend which will callback to verify.
     * 
     * @return array|WP_Error Result of the verification attempt
     */
    public function attempt_auto_verification() {
        if (trim(KODANOTE_API_BASE_URL) === '') {
            return new WP_Error('no_api_url', __('Configure the Kodanote publishing API URL in wp-config.php before connecting.', 'kodanote-content-publisher'));
        }

        $site_url = home_url();
        
        $this->log_debug("Attempting auto-verification for: " . $site_url);

        // Don't attempt for localhost or local development environments (backend can't reach them)
        $local_patterns = array('localhost', '127.0.0.1', '.local', '.test', '.dev', '192.168.', '10.0.', '172.16.');
        foreach ($local_patterns as $pattern) {
            if (strpos($site_url, $pattern) !== false) {
                $this->log_debug("Auto-verification skipped - local development environment detected: " . $pattern);
                return new WP_Error('localhost', 'Auto-verification is not available for local development environments. Please enter your API key manually.');
            }
        }

        $handshake_url = rtrim(trim(KODANOTE_API_BASE_URL), '/') . '/plugin/initiate-handshake';
        $installation_token = wp_generate_password(64, false, false);
        $installation_token_key = 'kodanote_handshake_installation_token_' . hash('sha256', $installation_token);
        set_transient(
            $installation_token_key,
            '1',
            10 * MINUTE_IN_SECONDS
        );

        $response = wp_remote_post($handshake_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Kodanote-Plugin-Version' => KODANOTE_VERSION,
            ),
            'body' => wp_json_encode(array(
                'site_url' => $site_url,
                'installation_token' => $installation_token,
            )),
            'timeout' => 30, // Longer timeout - backend needs to callback to us
        ));

        if (is_wp_error($response)) {
            delete_transient($installation_token_key);
            $this->log_debug("Auto-verification failed - request error: " . $response->get_error_message());
            return $response;
        }

        delete_transient($installation_token_key);

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $this->log_debug("Auto-verification response - status: " . $status_code);
        $this->log_debug(
            "Auto-verification response parsed - success: "
            . (!empty($data['success']) ? 'yes' : 'no')
        );

        if ($status_code === 200 && isset($data['success']) && $data['success'] === true) {
            // Success! We received the API key
            if (isset($data['api_key'])) {
                // Store the API key
                update_option('kodanote_api_key', sanitize_text_field($data['api_key']));
                
                // Store verification timestamp
                update_option('kodanote_auto_verified_at', current_time('mysql'));
                
                // Track when API key was set for adaptive sync
                if (!get_option('kodanote_api_key_set_time')) {
                    update_option('kodanote_api_key_set_time', time());
                    
                    // Schedule aggressive sync for new setup
                    wp_clear_scheduled_hook('kodanote_auto_sync');
                    if (trim(KODANOTE_API_BASE_URL) !== '') {
                        wp_schedule_event(time(), 'kodanote_every_minute', 'kodanote_auto_sync');
                    }
                }
                
                // Hide setup wizard
                delete_option('kodanote_show_setup_wizard');

                $this->log_debug("Auto-verification successful! API key received and stored.");

                return array(
                    'success' => true,
                    'message' => 'Plugin verified automatically!',
                    'site_name' => $data['site_name'] ?? '',
                );
            }
        }

        // Failed - return error with details
        $error_message = isset($data['message']) ? $data['message'] : 'Auto-verification failed';
        $requires_api_key = isset($data['requires_api_key']) ? $data['requires_api_key'] : true;

        $this->log_debug("Auto-verification failed: " . $error_message);

        return new WP_Error(
            'verification_failed',
            $error_message,
            array('requires_api_key' => $requires_api_key)
        );
    }

    /**
     * AJAX handler for attempting auto-verification
     */
    public function ajax_attempt_auto_verification() {
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'kodanote-content-publisher')));
            return;
        }

        $result = $this->attempt_auto_verification();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'requires_api_key' => true,
            ));
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * REST API handler for force republishing an article
     * This will publish an article even if it was trashed or deleted
     */
    public function rest_force_republish($request) {
        global $wpdb;

        $kodanote_id = $request->get_param('article_id');
        $table_name = $wpdb->prefix . 'kodanote_articles';

        $this->log_debug("Force republish requested for article ID: {$kodanote_id}");

        // First, sync the latest article data from Kodanote API
        $api = new Kodanote_API();
        $sync_result = $api->sync_articles(true); // Force resync to get latest content

        if (is_wp_error($sync_result)) {
            $this->log_debug("Force republish failed - sync error: " . $sync_result->get_error_message());
            return new WP_Error(
                'sync_failed',
                __('Failed to sync article from Kodanote', 'kodanote-content-publisher'),
                array('status' => 500)
            );
        }

        // Without this the article is looked up in a sync table the skipped sync never
        // populated, and the real cause is reported as a misleading "Article not found".
        if (!empty($sync_result['skipped'])) {
            $this->log_debug('Force republish skipped - another sync is already in progress');
            return new WP_Error(
                'sync_in_progress',
                $sync_result['message'],
                array('status' => 409)
            );
        }

        // Get the article from sync table
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is safely constructed from $wpdb->prefix
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE kodanote_id = %s",
            $kodanote_id
        ));

        if (!$article) {
            $this->log_debug("Force republish failed - article not found in sync table: {$kodanote_id}");
            return new WP_Error(
                'article_not_found',
                __('Article not found', 'kodanote-content-publisher'),
                array('status' => 404)
            );
        }

        // Check if post exists and restore from trash if needed
        if ($article->post_id) {
            $existing_post = get_post($article->post_id);

            // Verify the post actually exists in the database with a direct query,
            // bypassing WP's object cache which can return stale data for deleted posts.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $db_post_status = $wpdb->get_var($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' LIMIT 1",
                $article->post_id
            ));
            
            if ($existing_post && $db_post_status) {
                if ($db_post_status === 'trash' || $existing_post->post_status === 'trash') {
                    $this->log_debug("Restoring post {$article->post_id} from trash (db_status: {$db_post_status})");
                    wp_untrash_post($article->post_id);
                    wp_cache_delete($article->post_id, 'posts');
                }
                
                // Update and republish the existing post. Force resync is an
                // explicit Kodanote action, so it should refresh body content even
                // if the post was previously marked as manually edited in WP.
                $article->force_content_update = true;
                $article->force_page_builder_content_update = true;
                $publisher = new Kodanote_Publisher();
                $result = $publisher->update_existing_article($article->id, $article, $existing_post);
                
                if (is_wp_error($result)) {
                    $this->log_debug("Force republish failed - publish error: " . $result->get_error_message());
                    return new WP_Error(
                        'publish_failed',
                        $result->get_error_message(),
                        array('status' => 500)
                    );
                }

                $this->log_debug("Force republish successful - updated existing post {$article->post_id}");
                
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => __('Article republished successfully', 'kodanote-content-publisher'),
                    'post_id' => $result['post_id'],
                    'published_url' => $result['published_url'],
                    'action' => 'republished',
                ));
            } else {
                $this->log_debug(sprintf(
                    'Post %d not found in database (get_post: %s, db_status: %s) — will create new post',
                    $article->post_id,
                    $existing_post ? 'cached' : 'null',
                    $db_post_status ?: 'not found'
                ));
                wp_cache_delete($article->post_id, 'posts');
            }
        }

        // Post doesn't exist or was phantom — clear the post_id, reset recreate_count, and create new
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $table_name,
            array('post_id' => null, 'status' => 'pending'),
            array('id' => $article->id),
            array('%d', '%s'),
            array('%d')
        );
        // Reset recreate_count so auto-recovery doesn't block future syncs
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET recreate_count = 0 WHERE id = %d",
            $article->id
        ));

        // Publish as new post
        $publisher = new Kodanote_Publisher();
        $result = $publisher->publish_article($article->id);
        
        if (is_wp_error($result)) {
            $this->log_debug("Force republish failed - create new post error: " . $result->get_error_message());
            return new WP_Error(
                'publish_failed',
                $result->get_error_message(),
                array('status' => 500)
            );
        }

        $this->log_debug("Force republish successful - created new post {$result['post_id']}");

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Article published successfully', 'kodanote-content-publisher'),
            'post_id' => $result['post_id'],
            'published_url' => $result['published_url'],
            'action' => 'created',
        ));
    }

    /**
     * REST API endpoint to trigger article sync from Kodanote server
     * This allows the server to push sync requests rather than waiting for cron
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_trigger_sync($request) {
        $auto_publish = $request->get_param('auto_publish');
        $pushed_articles = $request->get_param('articles');
        $deleted_article_ids = $request->get_param('deleted_article_ids');
        
        $has_pushed = is_array($pushed_articles);
        $has_deletions = !empty($deleted_article_ids) && is_array($deleted_article_ids);
        $mode = $has_pushed ? 'push' : 'pull';
        $article_count = $has_pushed ? count($pushed_articles) : 0;

        $max_articles_per_sync = 50;
        if ($has_pushed && $article_count > $max_articles_per_sync) {
            $this->log_debug("Trigger sync rejected - too many articles: {$article_count} (max {$max_articles_per_sync})");
            return new WP_Error(
                'payload_too_large',
                sprintf('Maximum %d articles per sync request', $max_articles_per_sync),
                array('status' => 413)
            );
        }

        $this->log_debug("Trigger sync requested from Kodanote server (mode: {$mode}, auto_publish: " . ($auto_publish ? 'true' : 'false') . ", articles: {$article_count} , deletions: " . ($has_deletions ? count($deleted_article_ids) : 0) . ")");

        try {
            $api = new Kodanote_API();
            // Pull triggers must use the incremental sync cursor. Forcing a resync
            // here makes large sites re-check every recent article in one request.
            $sync_result = $api->sync_articles(
                false,
                $has_pushed ? $pushed_articles : null,
                $has_deletions ? $deleted_article_ids : null,
                $auto_publish
            );

            if (is_wp_error($sync_result)) {
                $error_message = $sync_result->get_error_message();
                $this->log_debug("Trigger sync failed - sync error: " . $error_message);
                Kodanote_Scheduler::store_sync_error($error_message);
                return new WP_Error(
                    'sync_failed',
                    $error_message,
                    array('status' => 500)
                );
            }

            // Answering a skipped sync with a 200 made Kodanote record "WordPress
            // accepted the sync request but did not publish the article". A 409 tells
            // the server this is a transient conflict worth retrying.
            if (!empty($sync_result['skipped'])) {
                $this->log_debug('Trigger sync skipped - another sync is already in progress');
                return new WP_Error(
                    'sync_in_progress',
                    $sync_result['message'],
                    array('status' => 409)
                );
            }

            $synced_count = $sync_result['synced_count'] ?? 0;
            $publish_errors = array();

            // sync_articles() already publishes pending articles during the sync loop,
            // so no separate publish pass is needed here. A previous version ran a second
            // publish pass which caused duplicate WordPress posts via race conditions.

            Kodanote_Scheduler::clear_sync_error();
            $this->log_debug("Trigger sync completed - synced: {$synced_count}");

            $message = sprintf(
                /* translators: %d: number of articles synced */
                __('Sync completed: %d articles synced', 'kodanote-content-publisher'),
                $synced_count
            );

            return rest_ensure_response(array(
                'success' => true,
                'message' => $message,
                'synced_count' => $synced_count,
                'published_count' => $synced_count,
                'sync_errors' => $sync_result['errors'] ?? array(),
                'publish_errors' => $publish_errors,
            ));

        } catch (Exception $e) {
            $this->log_debug("Trigger sync exception: " . $e->getMessage());
            return new WP_Error(
                'sync_exception',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * REST API handler for pushing images directly to the WP media library.
     *
     * Receives a file upload with metadata, finds the corresponding WP post
     * by Kodanote article ID, and attaches the image as hero (featured) or infographic.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_push_image($request) {
        $files = $request->get_file_params();
        $params = $request->get_params();

        $article_id = isset($params['article_id']) ? intval($params['article_id']) : 0;
        $image_type = isset($params['image_type']) ? sanitize_text_field($params['image_type']) : '';
        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        $original_url = isset($params['original_url']) ? esc_url_raw($params['original_url']) : '';

        if (empty($article_id) || empty($image_type) || empty($files['file'])) {
            return new WP_Error('missing_params', 'article_id, image_type, and file are required', array('status' => 400));
        }

        if (!in_array($image_type, array('hero', 'infographic'), true)) {
            return new WP_Error('invalid_image_type', 'image_type must be "hero" or "infographic"', array('status' => 400));
        }

        $max_file_size = 10 * 1024 * 1024; // 10 MB
        $file_size = $files['file']['size'] ?? 0;
        if ($file_size > $max_file_size) {
            $this->log_debug(sprintf('Push image rejected - file too large: %s (max %s)', size_format($file_size), size_format($max_file_size)));
            return new WP_Error('file_too_large', sprintf('File size %s exceeds maximum of %s', size_format($file_size), size_format($max_file_size)), array('status' => 413));
        }

        $this->log_debug(sprintf(
            'Push image received: article_id=%d, type=%s, file_size=%s',
            $article_id,
            $image_type,
            size_format($file_size)
        ));

        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kodanote_article_id' AND meta_value = %s LIMIT 1",
            $article_id
        ));

        if (!$post_id) {
            $this->log_debug(sprintf('Push image: no WP post found for article ID %d', $article_id));
            return new WP_Error('post_not_found', 'No WordPress post found for article ID ' . $article_id, array('status' => 404));
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'WordPress post ' . $post_id . ' not found', array('status' => 404));
        }

        // For hero images, skip if the post already has a valid featured image with the same URL
        // AND the thumbnail was actually set by Kodanote (verified via _kodanote_hero_attachment_id)
        if ($image_type === 'hero' && has_post_thumbnail($post_id)) {
            $current_url = get_post_meta($post_id, '_kodanote_hero_image_url', true);
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $kodanote_attachment_id = get_post_meta($post_id, '_kodanote_hero_attachment_id', true);
            $thumbnail_verified = !empty($kodanote_attachment_id) && (int) $kodanote_attachment_id === (int) $thumbnail_id;
            if ($current_url === $original_url && $thumbnail_id && wp_get_attachment_url($thumbnail_id) && $thumbnail_verified) {
                $this->log_debug(sprintf('Push image: hero image already attached and verified for post %d, skipping', $post_id));
                return rest_ensure_response(array(
                    'success' => true,
                    'skipped' => true,
                    'attachment_id' => intval($thumbnail_id),
                    'post_id' => intval($post_id),
                    'image_type' => $image_type,
                ));
            }
        }

        // For infographic images, skip if already attached with the same URL
        if ($image_type === 'infographic') {
            $current_url = get_post_meta($post_id, '_kodanote_infographic_image_url', true);
            $current_id = get_post_meta($post_id, '_kodanote_infographic_image_id', true);
            if ($current_url === $original_url && $current_id && wp_get_attachment_url($current_id)) {
                $this->log_debug(sprintf('Push image: infographic already attached for post %d, skipping', $post_id));
                return rest_ensure_response(array(
                    'success' => true,
                    'skipped' => true,
                    'attachment_id' => intval($current_id),
                    'post_id' => intval($post_id),
                    'image_type' => $image_type,
                ));
            }
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $upload_overrides = array('test_form' => false);
        $uploaded_file = wp_handle_upload($files['file'], $upload_overrides);

        if (isset($uploaded_file['error'])) {
            $this->log_debug('Push image upload failed: ' . $uploaded_file['error']);
            return new WP_Error('upload_failed', $uploaded_file['error'], array('status' => 500));
        }

        $attachment_data = array(
            'post_mime_type' => $uploaded_file['type'],
            'post_title' => $title ?: sanitize_file_name(basename($uploaded_file['file'])),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment_data, $uploaded_file['file'], $post_id);

        if (is_wp_error($attachment_id)) {
            $this->log_debug('Push image attachment creation failed: ' . $attachment_id->get_error_message());
            return new WP_Error('attachment_failed', $attachment_id->get_error_message(), array('status' => 500));
        }

        $attach_data = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // Set alt text on the attachment using post title
        $alt_text = $post ? $post->post_title : '';
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        if ($image_type === 'hero') {
            set_post_thumbnail($post_id, $attachment_id);
            if ($original_url) {
                update_post_meta($post_id, '_kodanote_hero_image_url', $original_url);
            }
            update_post_meta($post_id, '_kodanote_hero_attachment_id', $attachment_id);
            $this->log_debug(sprintf('Push image: set hero image (attachment %d) for post %d', $attachment_id, $post_id));

            // Update SEO plugin OG image meta so social sharing uses this image
            $og_image_url = wp_get_attachment_image_url($attachment_id, 'full');
            if ($og_image_url) {
                if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') || function_exists('wpseo_init')) {
                    update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $og_image_url);
                    update_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id);
                    update_post_meta($post_id, '_yoast_wpseo_twitter-image', $og_image_url);
                    update_post_meta($post_id, '_yoast_wpseo_twitter-image-id', $attachment_id);
                    $this->log_debug(sprintf('Push image: set Yoast OG image for post %d', $post_id));
                } elseif (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
                    update_post_meta($post_id, 'rank_math_facebook_image', $og_image_url);
                    update_post_meta($post_id, 'rank_math_facebook_image_id', $attachment_id);
                    update_post_meta($post_id, 'rank_math_twitter_use_facebook', 'on');
                    $this->log_debug(sprintf('Push image: set Rank Math OG image for post %d', $post_id));
                } elseif (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
                    update_post_meta($post_id, '_seopress_social_fb_img', $og_image_url);
                    update_post_meta($post_id, '_seopress_social_fb_img_attachment_id', $attachment_id);
                    update_post_meta($post_id, '_seopress_social_twitter_img', $og_image_url);
                    update_post_meta($post_id, '_seopress_social_twitter_img_attachment_id', $attachment_id);
                    $this->log_debug(sprintf('Push image: set SEOPress social image for post %d', $post_id));
                }
            }
        } elseif ($image_type === 'infographic') {
            update_post_meta($post_id, '_kodanote_infographic_image_id', $attachment_id);
            if ($original_url) {
                update_post_meta($post_id, '_kodanote_infographic_image_url', $original_url);
            }
            $this->log_debug(sprintf('Push image: set infographic (attachment %d) for post %d', $attachment_id, $post_id));

            $publisher = new Kodanote_Publisher();
            $publisher->bake_infographic_into_content($post_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'attachment_id' => $attachment_id,
            'post_id' => intval($post_id),
            'image_type' => $image_type,
        ));
    }

    /**
     * Log debug message
     */
    private function log_debug($message) {
        $debug_mode = get_option('kodanote_debug_mode', '0');
        if ($debug_mode === '1') {
            error_log('[Kodanote] ' . $message);
        }
    }

    /**
     * REST proxy for conversion events.
     * Frontend JS sends events here; this method forwards them to the Kodanote API
     * with the API key so it never appears in browser-visible JavaScript.
     */
    public function rest_conversion_event_proxy($request) {
        if (trim(KODANOTE_API_BASE_URL) === '') {
            return new WP_REST_Response(array('error' => 'not_configured'), 503);
        }

        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            return new WP_REST_Response(array('error' => 'not_configured'), 200);
        }

        $origin = $request->get_header('Origin');
        $referer = $request->get_header('Referer');
        $allowed_hosts = array_map('strtolower', array_filter(array(
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
        )));
        $request_host = '';
        if (!empty($origin)) {
            $request_host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
        } elseif (!empty($referer)) {
            $request_host = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
        }

        if (!empty($request_host) && !in_array($request_host, $allowed_hosts, true)) {
            return new WP_REST_Response(array('error' => 'origin_not_allowed'), 403);
        }

        // Simple per-IP rate limiting via transients (60 requests/minute)
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rate_key = 'kodanote_conv_rate_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= 60) {
            return new WP_REST_Response(array('error' => 'rate_limited'), 429);
        }
        set_transient($rate_key, $count + 1, 60);

        $body = $request->get_json_params();
        if (empty($body['events']) || !is_array($body['events'])) {
            return new WP_REST_Response(array('error' => 'invalid_payload'), 400);
        }

        $token = (isset($body['token']) && is_scalar($body['token'])) ? sanitize_text_field(wp_unslash((string) $body['token'])) : '';
        $current_bucket = floor(time() / HOUR_IN_SECONDS);
        $valid_tokens = array(
            $this->get_conversion_tracker_token($current_bucket),
            $this->get_conversion_tracker_token($current_bucket - 1),
        );
        if (empty($token) || (!hash_equals($valid_tokens[0], $token) && !hash_equals($valid_tokens[1], $token))) {
            return new WP_REST_Response(array('error' => 'invalid_token'), 403);
        }

        // Cap at 20 events per request to match server-side validation
        $body['events'] = array_slice($body['events'], 0, 20);
        unset($body['token']);

        $json_body = wp_json_encode($body);
        $signature = hash_hmac('sha256', $json_body, $api_key);

        $response = wp_remote_post($this->api_base_url . '/conversion-events', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-Kodanote-Plugin-Version' => KODANOTE_VERSION,
                'X-Kodanote-Signature' => $signature,
            ),
            'body' => $json_body,
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_REST_Response(array('error' => 'upstream_error'), 502);
        }

        $status = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_REST_Response($result ?: array('ok' => true), $status ?: 200);
    }

    /**
     * REST proxy for article pageviews.
     * Frontend JS sends one pageview here; this method forwards it to Kodanote
     * with the API key so it never appears in browser-visible JavaScript.
     */
    public function rest_article_pageview_proxy($request) {
        if (trim(KODANOTE_API_BASE_URL) === '') {
            return new WP_REST_Response(array('error' => 'not_configured'), 503);
        }

        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $origin = $request->get_header('Origin');
        $referer = $request->get_header('Referer');
        $allowed_hosts = array_map('strtolower', array_filter(array(
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
        )));
        $request_host = '';
        if (!empty($origin)) {
            $request_host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
        } elseif (!empty($referer)) {
            $request_host = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
        }

        if (!empty($request_host) && !in_array($request_host, $allowed_hosts, true)) {
            return new WP_REST_Response(array('error' => 'origin_not_allowed'), 403);
        }

        // Simple per-IP rate limiting via transients (300 requests/minute)
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rate_key = 'kodanote_pv_rate_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= 300) {
            return new WP_REST_Response(array('error' => 'rate_limited'), 429);
        }
        set_transient($rate_key, $count + 1, 60);

        $body = $request->get_json_params();
        if (empty($body) || !is_array($body)) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $payload = array(
            'article_id' => isset($body['article_id']) ? absint($body['article_id']) : 0,
            'visitor_id' => isset($body['visitor_id']) && is_scalar($body['visitor_id']) ? substr(sanitize_text_field(wp_unslash((string) $body['visitor_id'])), 0, 128) : '',
            'event_type' => isset($body['event_type']) && is_scalar($body['event_type']) ? sanitize_text_field(wp_unslash((string) $body['event_type'])) : 'pageview',
            'page_url' => isset($body['page_url']) && is_scalar($body['page_url']) ? substr(sanitize_text_field(wp_unslash((string) $body['page_url'])), 0, 500) : null,
            'referrer_url' => isset($body['referrer_url']) && is_scalar($body['referrer_url']) ? substr(sanitize_text_field(wp_unslash((string) $body['referrer_url'])), 0, 500) : null,
            'timestamp' => isset($body['timestamp']) && is_scalar($body['timestamp']) ? sanitize_text_field(wp_unslash((string) $body['timestamp'])) : gmdate('c'),
        );

        if (!in_array($payload['event_type'], array('pageview', 'time_on_page'), true)) {
            $payload['event_type'] = 'pageview';
        }

        if ($payload['event_type'] === 'time_on_page') {
            $payload['duration_seconds'] = isset($body['duration_seconds']) ? absint($body['duration_seconds']) : 0;
        }

        if (!$payload['article_id'] || empty($payload['visitor_id'])) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        if ($payload['event_type'] === 'time_on_page' && ($payload['duration_seconds'] < 1 || $payload['duration_seconds'] > 3600)) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $json_body = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $json_body, $api_key);

        $response = wp_remote_post($this->api_base_url . '/article-pageviews', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-Kodanote-Plugin-Version' => KODANOTE_VERSION,
                'X-Kodanote-Signature' => $signature,
            ),
            'body' => $json_body,
            'timeout' => 5,
        ));

        if (is_wp_error($response)) {
            return new WP_REST_Response(array('error' => 'upstream_error'), 502);
        }

        $status = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_REST_Response($result ?: array('ok' => true), $status ?: 200);
    }

    /**
     * Handle post status transitions for Kodanote articles.
     * This catches when users manually publish or trash Kodanote articles in WP admin.
     * 
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function handle_post_status_transition($new_status, $old_status, $post) {
        if ($post->post_type !== 'post') {
            return;
        }

        // Check if this is a Kodanote article
        $kodanote_article_id = get_post_meta($post->ID, '_kodanote_article_id', true);
        if (empty($kodanote_article_id)) {
            return; // Not a Kodanote article
        }

        if ($new_status === 'trash' && $old_status !== 'trash') {
            $this->handle_kodanote_article_trashed($post, $kodanote_article_id);
            return;
        }

        // Only care about new publishes from this point on.
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        // Skip if a sync is in progress — the batch will handle all webhooks
        if (class_exists('Kodanote_Publisher') && Kodanote_Publisher::is_batching()) {
            if (get_option('kodanote_debug_mode', '0') === '1') {
                error_log(sprintf(
                    'Kodanote: Skipping article_published webhook for post %d (batch mode active)',
                    $post->ID
                ));
            }
            return;
        }

        // Skip if the plugin already sent a webhook for this article recently
        // (e.g. create_new_post already sent one, and this is WordPress firing
        // transition_post_status when a scheduled/future post goes live)
        $webhook_sent = get_post_meta($post->ID, '_kodanote_webhook_sent', true);
        if (!empty($webhook_sent) && (time() - (int) $webhook_sent) < 900) {
            if (get_option('kodanote_debug_mode', '0') === '1') {
                error_log(sprintf(
                    'Kodanote: Skipping duplicate article_published webhook for post %d (already sent %ds ago)',
                    $post->ID,
                    time() - (int) $webhook_sent
                ));
            }
            return;
        }

        // Get the published URL
        $publisher = new Kodanote_Publisher();
        $published_url = $publisher->get_post_permalink($post->ID);

        // Mark webhook as sent to prevent future duplicates
        update_post_meta($post->ID, '_kodanote_webhook_sent', (string) time());

        // Send webhook to Kodanote API
        $api = new Kodanote_API();
        $result = $api->send_webhook('article_published', array(
            'article_id' => $kodanote_article_id,
            'wordpress_post_id' => $post->ID,
            'published_url' => $published_url,
        ));
        if (!is_wp_error($result)) {
            update_post_meta($post->ID, '_kodanote_last_reported_url', esc_url_raw($published_url));
        }

        // Log for debugging
        if (get_option('kodanote_debug_mode', '0') === '1') {
            error_log(sprintf(
                'Kodanote: Sent article_published webhook for post %d (Kodanote ID: %s, URL: %s)',
                $post->ID,
                $kodanote_article_id,
                $published_url
            ));
        }
    }

    /**
     * Mark a manually trashed Kodanote post locally and notify Kodanote.
     */
    private function handle_kodanote_article_trashed($post, $kodanote_article_id) {
        global $wpdb, $kodanote_allow_trash;

        // Dashboard-initiated deletion sets this flag while wp_trash_post() runs.
        // That path already soft-deletes the article in Kodanote, so don't send a
        // manual trash webhook back for the same action.
        if (!empty($kodanote_allow_trash)) {
            return;
        }

        $table_name = $wpdb->prefix . 'kodanote_articles';

        // Keep post_id so force-republish can restore this same WordPress post.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $table_name,
            array('status' => 'trashed'),
            array('kodanote_id' => (string) $kodanote_article_id),
            array('%s'),
            array('%s')
        );

        $api = new Kodanote_API();
        $result = $api->send_webhook('article_trashed', array(
            'article_id' => (string) $kodanote_article_id,
        ));

        if (get_option('kodanote_debug_mode', '0') === '1') {
            if (is_wp_error($result)) {
                error_log(sprintf(
                    'Kodanote: Failed to send article_trashed webhook for post %d (Kodanote ID: %s): %s',
                    $post->ID,
                    $kodanote_article_id,
                    $result->get_error_message()
                ));
            } else {
                error_log(sprintf(
                    'Kodanote: Sent article_trashed webhook for post %d (Kodanote ID: %s)',
                    $post->ID,
                    $kodanote_article_id
                ));
            }
        }
    }

    /**
     * Detect when a published Kodanote article's permalink changes and notify the API.
     *
     * WordPress fires `post_updated` after a post is saved. We compare the old
     * and new slugs (post_name) to detect permalink edits.
     */
    public function handle_post_permalink_change($post_id, $post_after, $post_before) {
        if ($post_after->post_type !== 'post' || $post_after->post_status !== 'publish') {
            return;
        }

        if ($post_before->post_name === $post_after->post_name) {
            return;
        }

        $kodanote_article_id = get_post_meta($post_id, '_kodanote_article_id', true);
        if (empty($kodanote_article_id)) {
            return;
        }

        $publisher = new Kodanote_Publisher();
        $new_url = $publisher->get_post_permalink($post_id);
        $old_url_approx = str_replace(
            '/' . $post_after->post_name . '/',
            '/' . $post_before->post_name . '/',
            $new_url
        );

        $api = new Kodanote_API();
        $result = $api->send_webhook('article_url_updated', array(
            'article_id'        => $kodanote_article_id,
            'wordpress_post_id' => $post_id,
            'published_url'     => $new_url,
            'old_url'           => $old_url_approx,
        ));
        if (!is_wp_error($result)) {
            update_post_meta($post_id, '_kodanote_last_reported_url', esc_url_raw($new_url));
        }

        if (get_option('kodanote_debug_mode', '0') === '1') {
            error_log(sprintf(
                'Kodanote: Permalink changed for post %d (Kodanote ID: %s), old slug: %s → new slug: %s, new URL: %s',
                $post_id,
                $kodanote_article_id,
                $post_before->post_name,
                $post_after->post_name,
                $new_url
            ));
        }
    }

    /**
     * Schedule a published URL rescan when the site-wide permalink structure changes.
     */
    public function handle_permalink_structure_change($old_value, $new_value) {
        if ($old_value === $new_value) {
            return;
        }

        update_option('kodanote_pending_permalink_structure', (string) $new_value, false);
        update_option('kodanote_permalink_rescan_after_id', 0, false);

        if (!wp_next_scheduled('kodanote_rescan_published_urls')) {
            wp_schedule_single_event(time() + 30, 'kodanote_rescan_published_urls');
        }

        if (get_option('kodanote_debug_mode', '0') === '1') {
            error_log(sprintf(
                'Kodanote: Permalink structure changed from "%s" to "%s"; scheduled published URL rescan',
                (string) $old_value,
                (string) $new_value
            ));
        }
    }

    /**
     * Resca Kodanote post URLs after a permalink structure change.
     */
    public function run_permalink_structure_rescan() {
        if (!class_exists('Kodanote_Publisher')) {
            return;
        }

        $publisher = new Kodanote_Publisher();
        $after_id = (int) get_option('kodanote_permalink_rescan_after_id', 0);
        // Keep the batch small: each reported URL is a blocking 15s webhook, so a
        // large batch could exceed max_execution_time on a single cron run. The
        // handler reschedules itself (every ~30s) until every post is checked.
        $result = $publisher->rescan_published_urls(50, true, $after_id);

        if (!empty($result['has_more'])) {
            update_option('kodanote_permalink_rescan_after_id', (int) $result['last_id'], false);
            wp_schedule_single_event(time() + 30, 'kodanote_rescan_published_urls');
        } elseif (!empty($result['errors'])) {
            update_option('kodanote_permalink_rescan_after_id', 0, false);
        } else {
            update_option('kodanote_last_permalink_structure', (string) get_option('permalink_structure', ''), false);
            delete_option('kodanote_pending_permalink_structure');
            delete_option('kodanote_permalink_rescan_after_id');
        }

        if (get_option('kodanote_debug_mode', '0') === '1') {
            error_log(sprintf(
                'Kodanote: Permalink structure rescan batch completed; checked %d posts, sent %d URL updates, errors %d%s',
                isset($result['checked']) ? (int) $result['checked'] : 0,
                isset($result['updated']) ? (int) $result['updated'] : 0,
                isset($result['errors']) ? (int) $result['errors'] : 0,
                !empty($result['has_more']) ? '; more batches scheduled' : (!empty($result['errors']) ? '; will retry on next sync' : '')
            ));
        }
    }

    /**
     * Check if a post is a Kodanote article
     */
    private function is_kodanote_article($post_id) {
        $kodanote_article_id = get_post_meta($post_id, '_kodanote_article_id', true);
        return !empty($kodanote_article_id);
    }

    /**
     * Show notice when viewing Kodanote article in editor
     */
    public function show_kodanote_edit_notice() {
        global $pagenow, $post;

        // Show notice on individual post edit pages for Kodanote articles
        if (($pagenow === 'post.php' || $pagenow === 'post-new.php') && isset($post->ID)) {
            $post_id = $post->ID;
            if ($this->is_kodanote_article($post_id)) {
                ?>
                <div class="notice notice-info" style="border-left-color: #3498db;">
                    <div style="display: flex; align-items: center; padding: 10px 0;">
                        <div style="margin-right: 15px; font-size: 24px;">ℹ️</div>
                        <div>
                            <h3 style="margin: 0 0 5px 0; color: #2980b9;">
                                <?php esc_html_e('Kodanote Managed Article', 'kodanote-content-publisher'); ?>
                            </h3>
                            <p style="margin: 0;">
                                <?php esc_html_e('You can edit this article here. If you change the body content in WordPress, Kodanote will remember that and will not overwrite the body on future syncs. Title, SEO settings, categories, tags, and images can still sync from Kodanote.', 'kodanote-content-publisher'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php
            }
        }
    }

    /**
     * Make the content editor read-only for Kodanote articles via JavaScript.
     * Meta boxes (Rank Math, Yoast, categories, tags, etc.) remain fully editable.
     */
    public function add_kodanote_content_readonly_script() {
        global $pagenow, $post;

        if (($pagenow !== 'post.php' && $pagenow !== 'post-new.php') || !isset($post->ID)) {
            return;
        }

        if (!$this->is_kodanote_article($post->ID)) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Keep the content editor available. If the user edits the body,
            // server-side save handling marks the post as manually edited so
            // future Kodanote syncs preserve their WordPress changes.

            // Hide the "Move to Trash" link in the editor
            $('#delete-action').hide();
        });
        </script>
        <style>
            /* Subtle indicator that WordPress edits are respected */
            body.post-php .kodanote-readonly-editor #postdivrich {
                position: relative;
            }
            body.post-php .kodanote-readonly-editor #postdivrich::before {
                content: "Kodanote article: WordPress body edits will be preserved after saving";
                display: block;
                background: #e8f4fd;
                color: #2980b9;
                font-size: 12px;
                padding: 6px 12px;
                border-bottom: 1px solid #bee5eb;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('#post-body-content').addClass('kodanote-readonly-editor');
        });
        </script>
        <?php
    }

    /**
     * Add styles for Kodanote articles in admin
     */
    public function add_kodanote_article_styles() {
        global $pagenow;
        
        if ($pagenow === 'edit.php') {
            // Enqueue inline styles for Kodanote article styling
            $inline_css = '
                /* Style Kodanote articles in posts list */
                .post-type-post .wp-list-table tbody tr[data-kodanote-article="true"] {
                    background-color: #f8f9ff;
                    border-left: 4px solid #3498db;
                }
                
                .post-type-post .wp-list-table tbody tr[data-kodanote-article="true"] .row-title {
                    position: relative;
                }
                
                .post-type-post .wp-list-table tbody tr[data-kodanote-article="true"] .row-title:after {
                    content: "🚀 Kodanote";
                    background: linear-gradient(135deg, #12a594 0%, #0b0b0d 100%);
                    color: white;
                    font-size: 11px;
                    padding: 3px 8px;
                    border-radius: 4px;
                    margin-left: 10px;
                    font-weight: 600;
                    display: inline-block;
                    vertical-align: middle;
                }
                
                .kodanote-edit-disabled {
                    opacity: 0.6;
                    pointer-events: none;
                }
                
                .kodanote-edit-notice {
                    color: #d68910;
                    font-weight: bold;
                }
            ';
            wp_add_inline_style('kodanote-admin', $inline_css);
        }
    }

    /**
     * Modify row actions for Kodanote articles
     */
    public function modify_kodanote_article_row_actions($actions, $post) {
        if ($this->is_kodanote_article($post->ID)) {
            // Remove inline/quick edit (content is managed by Kodanote)
            unset($actions['inline hide-if-no-js']);

            // Keep the Edit link so users can access meta boxes (Rank Math, Yoast, categories, etc.)
            // Add note that it's managed by Kodanote
            $actions['kodanote_note'] = '<span class="kodanote-edit-notice">' . __('Managed by Kodanote', 'kodanote-content-publisher') . '</span>';
            
            // Add hidden marker for JavaScript
            $actions['kodanote_marker'] = '<span class="kodanote-managed" style="display:none;"></span>';
        }
        
        return $actions;
    }

    /**
     * Allow Kodanote articles to be moved to trash from WordPress admin.
     * The transition_post_status handler records the manual trash and notifies Kodanote.
     *
     * @param bool|null $trash   Null to proceed, non-null to short-circuit.
     * @param WP_Post   $post   Post being trashed.
     * @param string    $status Previous post status.
     * @return bool|null
     */
    public function prevent_kodanote_article_trashing($trash, $post, $status = '') {
        if (!$post || $post->post_type !== 'post') {
            return $trash;
        }

        if ($this->is_kodanote_article($post->ID)) {
            $this->log_debug(sprintf('Allowing Kodanote article to move to trash (post ID: %d)', $post->ID));
        }

        return $trash;
    }

    /**
     * Prevent permanent deletion of Kodanote articles via wp_delete_post().
     * Returns false to short-circuit the delete (WP 5.5+).
     *
     * @param bool|null $delete       Null to proceed, non-null to short-circuit.
     * @param WP_Post   $post         Post being deleted.
     * @param bool      $force_delete Whether to bypass trash.
     * @return bool|null
     */
    public function prevent_kodanote_article_deleting($delete, $post, $force_delete = false) {
        if (!$post || $post->post_type !== 'post') {
            return $delete;
        }

        if ($this->is_kodanote_article($post->ID)) {
            $this->log_debug(sprintf('Blocked attempt to delete Kodanote article (post ID: %d)', $post->ID));
            return false;
        }

        return $delete;
    }

    /**
     * Disable Gutenberg editor for Kodanote articles
     */
    public function disable_gutenberg_for_kodanote_articles($use_block_editor, $post) {
        if (is_object($post) && $this->is_kodanote_article($post->ID)) {
            return false;
        }
        return $use_block_editor;
    }

    /**
     * Detect manual body edits on Kodanote posts. Earlier versions silently
     * discarded content changes here, which made the WordPress editor appear
     * broken. Now, a real body change marks the post as user-managed so later
     * Kodanote syncs keep the WordPress body intact.
     *
     * Bypassed when:
     *  - The plugin's own code sets $allow_content_update before writing.
     *  - The post has active page-builder data (Elementor, Divi, etc.),
     *    meaning the user is intentionally managing content with a builder.
     */
    public function protect_kodanote_content_on_admin_save($data, $postarr) {
        if (self::$allow_content_update) {
            return $data;
        }

        if (empty($postarr['ID'])) {
            return $data;
        }

        if (!get_post_meta($postarr['ID'], '_kodanote_managed', true)) {
            return $data;
        }

        // If the user is managing this post with a page builder, let the
        // builder save its own content without interference.
        if (Kodanote_Publisher::has_page_builder_content($postarr['ID'])) {
            return $data;
        }

        $original_post = get_post($postarr['ID']);
        if ($original_post && array_key_exists('post_content', $data)) {
            $incoming_content = (string) $data['post_content'];
            $original_content = (string) $original_post->post_content;

            if ($incoming_content !== $original_content) {
                update_post_meta($postarr['ID'], '_kodanote_manual_content_override', '1');
                update_post_meta($postarr['ID'], '_kodanote_manual_content_override_at', current_time('mysql'));
            }
        }

        return $data;
    }

    /**
     * Add SVG and path elements to TinyMCE's valid elements list for Kodanote
     * posts, so author box social icons render correctly in the Classic Editor
     * instead of being silently stripped during HTML parsing.
     */
    public function add_svg_to_tinymce_valid_elements($init_array) {
        global $post;

        if (!isset($post->ID) || !$this->is_kodanote_article($post->ID)) {
            return $init_array;
        }

        $svg_elements = 'svg[xmlns|width|height|viewBox|viewbox|fill|stroke|class|style|aria-hidden|role],'
            . 'path[d|fill|stroke|stroke-width|stroke-linecap|fill-rule|clip-rule]';

        if (!empty($init_array['extended_valid_elements'])) {
            $init_array['extended_valid_elements'] .= ',' . $svg_elements;
        } else {
            $init_array['extended_valid_elements'] = $svg_elements;
        }

        return $init_array;
    }

    /**
     * Allow Kodanote HTML elements in post content.
     * WordPress strips iframes, SVGs, etc. by default through wp_kses_post().
     * 
     * @param array  $allowed_tags Array of allowed HTML tags and attributes
     * @param string $context      The context for the allowed tags (e.g., 'post')
     * @return array Modified array of allowed tags
     */
    public function allow_kodanote_html_elements($allowed_tags, $context) {
        if ($context !== 'post') {
            return $allowed_tags;
        }

        // YouTube iframes
        $allowed_tags['iframe'] = array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allowfullscreen' => true,
            'allow'           => true,
            'title'           => true,
            'style'           => true,
            'class'           => true,
            'loading'         => true,
        );

        // SVG elements for author box social icons
        $allowed_tags['svg'] = array(
            'xmlns'       => true,
            'width'       => true,
            'height'      => true,
            'viewbox'     => true,
            'fill'        => true,
            'stroke'      => true,
            'class'       => true,
            'style'       => true,
            'aria-hidden' => true,
            'role'        => true,
        );
        $allowed_tags['path'] = array(
            'd'              => true,
            'fill'           => true,
            'stroke'         => true,
            'stroke-width'   => true,
            'stroke-linecap' => true,
            'fill-rule'      => true,
            'clip-rule'      => true,
        );

        // Heading IDs for TOC anchor links (wp_kses strips id during cron-based inserts)
        $heading_tags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6');
        foreach ($heading_tags as $tag) {
            if (!isset($allowed_tags[$tag])) {
                $allowed_tags[$tag] = array();
            }
            $allowed_tags[$tag]['id'] = true;
            $allowed_tags[$tag]['class'] = true;
        }

        return $allowed_tags;
    }

    /**
     * Allow additional CSS properties in inline styles.
     * WordPress strips display, flex, gap, etc. by default.
     */
    public function allow_additional_css_properties($styles) {
        $styles[] = 'width';
        $styles[] = 'height';
        $styles[] = 'max-width';
        $styles[] = 'min-height';
        $styles[] = 'padding-bottom';
        $styles[] = 'overflow';
        $styles[] = 'margin';
        $styles[] = 'border';
        $styles[] = 'display';
        $styles[] = 'gap';
        $styles[] = 'flex';
        $styles[] = 'flex-shrink';
        $styles[] = 'flex-grow';
        $styles[] = 'flex-direction';
        $styles[] = 'flex-wrap';
        $styles[] = 'align-items';
        $styles[] = 'justify-content';
        $styles[] = 'object-fit';
        $styles[] = 'transition';
        $styles[] = 'letter-spacing';
        $styles[] = 'text-transform';
        $styles[] = 'min-width';
        $styles[] = 'position';
        $styles[] = 'top';
        $styles[] = 'left';
        $styles[] = 'right';
        $styles[] = 'bottom';
        $styles[] = 'z-index';
        return $styles;
    }

    /**
     * Normalize YouTube iframe markup to a full-width responsive embed.
     */
    public function normalize_youtube_embed_markup($content) {
        if (!is_string($content) || stripos($content, 'youtube') === false) {
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
        ) ?? $content;

        $content = preg_replace_callback(
            '/<iframe\b[^>]*src=["\']https?:\/\/(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*>.*?<\/iframe>/is',
            function($matches) {
                return $this->build_responsive_youtube_embed_from_html($matches[0]);
            },
            $content
        ) ?? $content;

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

    /**
     * Add 'kodanote' class to the post container element for Kodanote-managed posts.
     * Allows users to target Kodanote articles with custom CSS.
     */
    public function add_kodanote_post_class($classes, $extra_classes, $post_id) {
        if (get_post_meta($post_id, '_kodanote_managed', true)) {
            $classes[] = 'kodanote';
        }
        return $classes;
    }

    /**
     * Add 'kodanote' class to the <body> tag when viewing a single Kodanote-managed post.
     */
    public function add_kodanote_body_class($classes) {
        if (is_singular('post')) {
            $post = get_post();
            if ($post && get_post_meta($post->ID, '_kodanote_managed', true)) {
                $classes[] = 'kodanote';
            }
        }
        return $classes;
    }

    /**
     * Fix Key Takeaways HTML structure at render time.
     *
     * Older articles may have Key Takeaways without the <div class="key-takeaways"> wrapper,
     * sometimes with a stray </div> that closes the FSE theme's wp-block-post-content container.
     * This filter ensures the wrapper is always present and removes stray closing divs.
     */
    public function fix_key_takeaways_structure($content) {
        if (!is_string($content) || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_kodanote_article_id', true)) {
            return $content;
        }

        // Already properly wrapped — nothing to do
        if (preg_match('/<div[^>]*class="key-takeaways"[^>]*>/i', $content)) {
            return $content;
        }

        // Only repair sections with a recognised Key Takeaways heading. A broad
        // H2 + list match also catches the table of contents; wrapping it consumes
        // the TOC's closing div and corrupts the page layout.
        $known_headings = array(
            'Key Takeaways', 'Puntos Clave', 'Points Cl(?:é|e)s', 'Wichtigste Erkenntnisse',
            'Punti Chiave', 'Principais Conclus(?:õ|o)es', 'Belangrijkste Punten',
            'Najwa(?:ż|z)niejsze Wnioski', 'Ключевые Выводы', '重要なポイント', '关键要点',
            '핵심 요약', 'النقاط الرئيسية', 'נקודות מפתח', '(?:Ö|O)nemli Noktalar',
            'Viktiga Slutsatser', 'Vigtigste Pointer', 'Viktige Punkter',
            'T(?:ä|a)rkeimm(?:ä|a)t Havainnot', 'Legfontosabb Tudnival(?:ó|o)k',
            'Kl(?:í|i)(?:č|c)ov(?:é|e) Poznatky', 'Concluzii Cheie',
            'Ключов(?:і|i) Висновки', 'Βασικά Συμπεράσματα',
            'ประเด็นสำคัญ', '(?:Đ|D)i(?:ể|e)m Ch(?:í|i)nh', 'Poin Penting',
            'Perkara Utama', 'मुख्य बातें',
        );
        $headings_pattern = implode('|', $known_headings);

        // Case A: recognised H2 + <ul>...</ul> + stray </div> — wrap and consume
        // the closer only when it belongs to a Key Takeaways section.
        $replaced = preg_replace(
            '/(<h2[^>]*>\s*(?:' . $headings_pattern . ')\s*<\/h2>\s*<ul>)(.*?)(<\/ul>)\s*<\/div>/isu',
            '<div class="key-takeaways">$1$2$3</div>',
            $content,
            1,
            $count
        );
        if ($count > 0 && $replaced !== null) {
            return $replaced;
        }

        // Case B: recognised H2 + <ul>...</ul> with no wrapper at all.
        $replaced = preg_replace(
            '/(<h2[^>]*>\s*(?:' . $headings_pattern . ')\s*<\/h2>\s*<ul>)(.*?)(<\/ul>)/isu',
            '<div class="key-takeaways">$1$2$3</div>',
            $content,
            1,
            $count
        );
        if ($count > 0 && $replaced !== null) {
            return $replaced;
        }

        return $content;
    }

    /**
     * Convert stale Markdown-style headings in Kodanote post content.
     *
     * Older syncs can leave lines like "## []()Heading" in post_content.
     * This runs before wpautop so those lines become real headings instead of
     * visible paragraph text on the published article.
     */
    public function convert_markdown_headings_in_content($content) {
        if (!is_string($content) || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_kodanote_article_id', true)) {
            return $content;
        }

        $convert_heading = function ($hashes, $heading_html, $fallback) {
            $clean_heading = preg_replace('/[ \t]*(?:\[\]\(\)[ \t]*)+/', '', $heading_html);
            if ($clean_heading !== null) {
                $heading_html = $clean_heading;
            }
            $heading_html = trim($heading_html);
            if ($heading_html === '') {
                return $fallback;
            }

            $level = min(6, strlen($hashes));
            return '<h' . $level . '>' . $heading_html . '</h' . $level . '>';
        };

        // Paragraph-wrapped variants, e.g. <p>## Heading</p>.
        $content = preg_replace_callback(
            '/<p\b[^>]*>\s*(#{2,6})[ \t]*(.*?)\s*<\/p>/is',
            function ($matches) use ($convert_heading) {
                return $convert_heading($matches[1], $matches[2], $matches[0]);
            },
            $content
        ) ?? $content;

        // Raw line variants, e.g. "## []()Heading", before WordPress applies wpautop.
        $content = preg_replace_callback(
            '/(^|[\r\n])([ \t]*)(#{2,6})[ \t]*((?:\[\]\(\)[ \t]*)*[^\r\n<][^\r\n]*)/m',
            function ($matches) use ($convert_heading) {
                $converted = $convert_heading($matches[3], $matches[4], $matches[3] . ' ' . $matches[4]);
                return $matches[1] . $matches[2] . $converted;
            },
            $content
        ) ?? $content;

        return $content;
    }

    /**
     * Add anchor IDs to H2 headings that don't have them.
     * WordPress wp_kses can strip `id` attributes during cron-based wp_insert_post.
     * This filter re-adds them at render time so TOC anchor links work.
     */
    public function add_heading_anchor_ids($content) {
        if (!is_string($content) || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_kodanote_article_id', true)) {
            return $content;
        }

        // Strip empty anchor tags produced when generated markdown []()
        // links survive into HTML (e.g. <a href=""></a>).
        $content = preg_replace('/<a\s+href=["\'][\s]*["\']>\s*<\/a>/i', '', $content) ?? $content;

        return preg_replace_callback(
            '/<h2(?![^>]*\bid=)([^>]*)>(.*?)<\/h2>/is',
            function ($matches) {
                $attributes = $matches[1];
                $headingHtml = $matches[2];
                $headingText = html_entity_decode(wp_strip_all_tags($headingHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $slug = $this->generate_heading_slug($headingText);

                if (trim($attributes) === '') {
                    return '<h2 id="' . esc_attr($slug) . '">' . $headingHtml . '</h2>';
                }
                return '<h2 id="' . esc_attr($slug) . '"' . $attributes . '>' . $headingHtml . '</h2>';
            },
            $content
        ) ?? $content;
    }

    /**
     * Unwrap legacy Kodanote heading anchor links at render time.
     *
     * Older sync payloads wrapped H2 text in named anchors for CMS compatibility.
     * Some themes (e.g. Infinite WP) render that markup as vertical text.
     */
    public function normalize_kodanote_heading_anchors($content) {
        if (!is_string($content) || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_kodanote_article_id', true)) {
            return $content;
        }

        $content = preg_replace(
            '/<a\b[^>]*\bclass=(["\'])kodanote-heading-anchor\1[^>]*>(.*?)<\/a>/is',
            '$2',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/<a\b[^>]*\bname=(["\'])[^"\']*\1[^>]*>\s*<\/a>/is',
            '',
            $content
        ) ?? $content;

        return $content;
    }

    /**
     * Generate a URL-friendly slug from heading text.
     * Must match the logic in Laravel's ArticleWritingService::generateHeadingSlug()
     */
    private function generate_heading_slug($heading) {
        $slug = mb_strtolower($heading, 'UTF-8');
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        if (empty($slug)) {
            $slug = 'section';
        }

        return $slug;
    }

    /**
     * Inject infographic image into post content
     * Inserts the infographic image before the middle H2 heading (matching Laravel placement)
     */
    public function inject_infographic_image_into_content($content) {
        // Only run on single post pages, inside the main loop
        // The in_the_loop() check prevents themes (e.g. Avada) from triggering
        // this filter in nav menus, related posts, footers, etc.
        if (!is_string($content) || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        global $post;
        if (!$post || empty($post->ID) || !$this->is_kodanote_article($post->ID)) {
            return $content;
        }

        // A page builder or manual WordPress edit owns the rendered body. Injecting
        // the original Kodanote infographic at display time can duplicate a cropped
        // or replaced image that the user intentionally added to their layout.
        if (
            get_post_meta($post->ID, '_kodanote_manual_content_override', true)
            || Kodanote_Publisher::has_page_builder_content($post->ID)
        ) {
            return $content;
        }

        // Content-based guard: check the filtered $content for the container class.
        // Existing posts can have a baked infographic image from an older plugin
        // version, so normalize it before returning.
        if (strpos($content, 'kodanote-infographic-container') !== false) {
            return $this->normalize_infographic_image_markup($content, $post->ID);
        }

        // Prevent multiple injections on the same page load
        static $already_injected = array();

        if (isset($already_injected[$post->ID])) {
            return $content;
        }

        // Database guard: if the infographic is baked into the stored post_content,
        // skip runtime injection. Some themes/page-builders modify $content before
        // this filter runs (stripping the baked infographic from the filtered string),
        // but the stored version still renders in the final output — injecting again
        // would duplicate it.
        $raw_content = get_post_field('post_content', $post->ID);
        if (strpos($raw_content, 'kodanote-infographic-container') !== false) {
            $already_injected[$post->ID] = true;
            return $content;
        }
        
        // Get the infographic image ID
        $infographic_image_id = get_post_meta($post->ID, '_kodanote_infographic_image_id', true);
        
        if (!$infographic_image_id) {
            return $content;
        }

        // Get the alt text from the attachment meta, fall back to post title
        $infographic_alt = get_post_meta($infographic_image_id, '_wp_attachment_image_alt', true);
        if (empty($infographic_alt)) {
            $infographic_alt = get_the_title($post->ID);
        }

        // Get the full image HTML
        $infographic_html = wp_get_attachment_image(
            $infographic_image_id,
            'full',
            false,
            $this->get_infographic_image_attributes($infographic_alt)
        );

        if (!$infographic_html) {
            return $content;
        }

        // Wrap the infographic in a container
        $infographic_container = '<div class="kodanote-infographic-container">' . $infographic_html . '</div>';

        $content = $this->insert_block_at_preferred_article_position($content, $infographic_container);

        $already_injected[$post->ID] = true;

        return $content;
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
     * Replace older baked infographic image markup with lazy-load-safe markup.
     */
    private function normalize_infographic_image_markup($content, $post_id) {
        $infographic_image_id = $post_id ? get_post_meta($post_id, '_kodanote_infographic_image_id', true) : 0;
        if (empty($infographic_image_id)) {
            return $content;
        }

        $infographic_alt = get_post_meta($infographic_image_id, '_wp_attachment_image_alt', true);
        if (empty($infographic_alt)) {
            $infographic_alt = get_the_title($post_id);
        }

        $infographic_html = wp_get_attachment_image(
            $infographic_image_id,
            'full',
            false,
            $this->get_infographic_image_attributes($infographic_alt)
        );

        if (empty($infographic_html)) {
            return $content;
        }

        $replacement = '<div class="kodanote-infographic-container">' . $infographic_html . '</div>';
        $updated = preg_replace(
            '/<div class="kodanote-infographic-container">\s*<img\b[^>]*>\s*<\/div>/is',
            $replacement,
            $content,
            1
        );

        return $updated ?: $content;
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
     * Register rewrite rules for .md URL support
     * This enables LLM-friendly markdown versions of Kodanote articles
     * Following the llms.txt specification: https://llmstxt.org/
     */
    public function register_md_rewrite_rules() {
        // Add query var for .md detection
        add_rewrite_tag('%kodanote_md%', '([0-1])');
    }

    /**
     * Handle .md URL requests and serve markdown content
     * When a URL like /blog/my-article.md is requested, serve the markdown version
     */
    public function handle_md_url_request() {
        // Get the current request URI
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        // Check if this is a .md request
        if (!preg_match('/\.md$/', $request_uri)) {
            return;
        }

        // Remove .md suffix to get the original URL
        $original_uri = preg_replace('/\.md$/', '', $request_uri);
        
        // Remove query string if present
        $original_uri = strtok($original_uri, '?');
        
        // Handle subdirectory installations (e.g., /blog/)
        // home_url() already includes the subdirectory, so we need to extract just the slug
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH);
        if ($home_path && $home_path !== '/') {
            // Remove the home path prefix from the request URI to avoid duplication
            $home_path = rtrim($home_path, '/');
            if (strpos($original_uri, $home_path) === 0) {
                $original_uri = substr($original_uri, strlen($home_path));
            }
        }
        
        // Try to find the post by URL
        $post_id = url_to_postid(home_url($original_uri));
        
        // If not found, try common permalink variations
        if (!$post_id) {
            // Try without trailing slash
            $post_id = url_to_postid(home_url(rtrim($original_uri, '/')));
        }
        if (!$post_id) {
            // Try with trailing slash
            $post_id = url_to_postid(home_url(trailingslashit($original_uri)));
        }
        
        // Also handle .html.md pattern (per llms.txt spec)
        if (!$post_id && preg_match('/\.html$/', $original_uri)) {
            $original_uri = preg_replace('/\.html$/', '', $original_uri);
            $post_id = url_to_postid(home_url($original_uri));
        }
        
        if (!$post_id) {
            // No matching post found - return 404
            status_header(404);
            echo '# 404 Not Found' . "\n\n";
            echo 'The requested article was not found.';
            exit;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || !empty($post->post_password) || !is_post_type_viewable($post->post_type)) {
            status_header(404);
            nocache_headers();
            echo '# 404 Not Found';
            exit;
        }

        // Check if this is a Kodanote article
        if (!$this->is_kodanote_article($post_id)) {
            // Not a Kodanote article - return 404 for .md version
            status_header(404);
            echo '# 404 Not Found' . "\n\n";
            echo 'Markdown version is only available for Kodanote articles.';
            exit;
        }

        // Get the markdown content
        $markdown_content = get_post_meta($post_id, '_kodanote_content_markdown', true);
        
        if (empty($markdown_content)) {
            // No markdown content stored - return 404
            status_header(404);
            echo '# 404 Not Found' . "\n\n";
            echo 'Markdown version is not available for this article.';
            exit;
        }

        // Get post data for additional context
        $post = get_post($post_id);
        
        // Set proper headers for markdown
        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400'); // Cache for 1 day
        
        // Output the markdown with metadata header (following llms.txt format)
        echo '# ' . esc_html($post->post_title) . "\n\n";
        
        // Add metadata as blockquote (per llms.txt spec)
        $meta_description = get_post_meta($post_id, '_kodanote_meta_description', true);
        if (!empty($meta_description)) {
            echo '> ' . esc_html($meta_description) . "\n\n";
        }
        
        // Add article info
        echo '---' . "\n";
        echo 'Published: ' . get_the_date('Y-m-d', $post_id) . "\n";
        $keywords = get_post_meta($post_id, '_kodanote_keywords', true);
        if (!empty($keywords) && is_array($keywords)) {
            echo 'Keywords: ' . esc_html(implode(', ', $keywords)) . "\n";
        }
        echo 'Source: ' . esc_url(get_permalink($post_id)) . "\n";
        echo '---' . "\n\n";
        
        // Convert HTML to markdown if content appears to be HTML
        if (strpos($markdown_content, '<p>') !== false || strpos($markdown_content, '<h') !== false) {
            $markdown_content = $this->convert_html_to_markdown($markdown_content);
        }
        
        // Output the actual markdown content
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown content is intentionally raw
        echo $markdown_content;
        
        exit;
    }

    /**
     * Convert HTML content to Markdown format
     * For LLM-friendly .md URLs following llms.txt specification
     * 
     * @param string $html HTML content to convert
     * @return string Markdown content
     */
    private function convert_html_to_markdown($html) {
        $markdown = $html;
        
        // Normalize line breaks
        $markdown = str_replace(array("\r\n", "\r"), "\n", $markdown);
        
        // Convert headings (h1-h6)
        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $markdown = preg_replace('/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is', "\n" . $prefix . ' $1' . "\n", $markdown);
        }
        
        // Convert paragraphs
        $markdown = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $markdown);
        
        // Convert bold
        $markdown = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $markdown);
        $markdown = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '**$1**', $markdown);
        
        // Convert italic
        $markdown = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '*$1*', $markdown);
        $markdown = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '*$1*', $markdown);
        
        // Convert links
        $markdown = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $markdown);
        
        // Convert unordered lists
        $markdown = preg_replace('/<ul[^>]*>(.*?)<\/ul>/is', "\n$1\n", $markdown);
        $markdown = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $markdown);
        
        // Convert ordered lists
        $markdown = preg_replace('/<ol[^>]*>(.*?)<\/ol>/is', "\n$1\n", $markdown);
        // Note: Ordered lists get converted to unordered for simplicity
        
        // Convert blockquotes
        $markdown = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n", $markdown);
        
        // Convert code blocks
        $markdown = preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "\n```\n$1\n```\n", $markdown);
        $markdown = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $markdown);
        
        // Convert line breaks
        $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown);
        
        // Convert horizontal rules
        $markdown = preg_replace('/<hr\s*\/?>/i', "\n---\n", $markdown);
        
        // Remove remaining HTML tags
        $markdown = wp_strip_all_tags($markdown);
        
        // Decode HTML entities
        $markdown = html_entity_decode($markdown, ENT_QUOTES, 'UTF-8');
        
        // Clean up excessive whitespace
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = trim($markdown);
        
        return $markdown;
    }
}

// Initialize the plugin
function kodanote_init() {
    return Kodanote_Plugin::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'kodanote_init');

/**
 * Shortcode for displaying Kodanote content
 */
function kodanote_shortcode($atts) {
    $atts = shortcode_atts(array(
        'type' => 'dashboard',
        'limit' => 10,
    ), $atts);

    if (!in_array($atts['type'], array('dashboard', 'articles'), true)) {
        return '';
    }
    if ($atts['type'] === 'dashboard' && !current_user_can('manage_options')) {
        return '';
    }
    $template = KODANOTE_PLUGIN_DIR . 'templates/frontend-' . $atts['type'] . '.php';
    if (!is_readable($template)) {
        return current_user_can('manage_options')
            ? '<p>' . esc_html__('This frontend template is not installed. Manage content from Kodanote in the WordPress admin.', 'kodanote-content-publisher') . '</p>'
            : '';
    }
    ob_start();
    include $template;
    return ob_get_clean();
}
add_shortcode('kodanote', 'kodanote_shortcode');

/**
 * Activation hook wrapper
 */
function kodanote_activate_plugin() {
    Kodanote_Plugin::get_instance()->activate();
}
register_activation_hook(__FILE__, 'kodanote_activate_plugin');

/**
 * Deactivation hook wrapper
 */
function kodanote_deactivate_plugin() {
    Kodanote_Plugin::get_instance()->deactivate();
}
register_deactivation_hook(__FILE__, 'kodanote_deactivate_plugin');
