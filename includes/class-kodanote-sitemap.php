<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Make sure search engines can find a sitemap through robots.txt.
 *
 * 1. If robots.txt already lists a sitemap, change nothing.
 * 2. If the site has a sitemap (SEO plugin, WordPress core, or another URL),
 *    list that sitemap in robots.txt.
 * 3. If the site has no sitemap, serve our own sitemap of all public content
 *    at /content-sitemap.xml and list that in robots.txt.
 *
 * WordPress-generated (virtual) robots.txt is changed with the robots_txt
 * filter. A physical robots.txt file is changed only when it is writable, and
 * only inside a marked block that the daily check can update or remove.
 *
 * The public URL and robots.txt marker are brand-neutral because white-label
 * builds ship this file unchanged. The static text helpers are WordPress-free
 * so Laravel unit tests can load them.
 */

if (class_exists('Kodanote_Sitemap')) {
    return;
}

class Kodanote_Sitemap {

    const CRON_HOOK = 'kodanote_sitemap_check';
    const STATUS_OPTION = 'kodanote_sitemap_status';
    const ENABLED_OPTION = 'kodanote_sitemap_robots_enabled';
    const QUERY_VAR = 'content_sitemap';
    const BLOCK_BEGIN = '# BEGIN content-sitemap';
    const BLOCK_END = '# END content-sitemap';
    const URLS_PER_SITEMAP = 1000;

    /**
     * Sitemap URLs that other tools often use, in probe order.
     */
    const COMMON_SITEMAP_PATHS = array(
        'sitemap_index.xml',
        'sitemap.xml',
        'wp-sitemap.xml',
        'sitemaps.xml',
        'sitemap-index.xml',
    );

    public function register_hooks() {
        // Yoast adds its Sitemap line at PHP_INT_MAX. Registering during
        // do_robotstxt puts us after every filter added at plugin load.
        add_action('do_robotstxt', array($this, 'register_robots_filter'), PHP_INT_MAX);
        add_action('parse_request', array($this, 'maybe_serve_sitemap'), 0);
        add_action('init', array($this, 'schedule_daily_check'));
        add_action(self::CRON_HOOK, array($this, 'run_check'));

        // Re-check soon when something changes which sitemap is correct.
        add_action('activated_plugin', array($this, 'on_plugin_toggled'));
        add_action('deactivated_plugin', array($this, 'on_plugin_toggled'));
        add_action('update_option_blog_public', array($this, 'schedule_check_soon'));
        add_action('update_option_permalink_structure', array($this, 'schedule_check_soon'));
    }

    /* ------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------ */

    public function schedule_daily_check() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'daily', self::CRON_HOOK);
        }
    }

    public function schedule_check_soon() {
        wp_schedule_single_event(time() + 30, self::CRON_HOOK);
    }

    /**
     * @param string $plugin Plugin basename
     */
    public function on_plugin_toggled($plugin) {
        // Our own deactivation clears the cron; do not schedule a new event.
        if (defined('KODANOTE_PLUGIN_BASENAME') && $plugin === KODANOTE_PLUGIN_BASENAME) {
            return;
        }
        $this->schedule_check_soon();
    }

    /**
     * Undo our changes when the plugin is deactivated.
     */
    public static function deactivate_cleanup() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $instance = new self();
        $instance->remove_physical_block();
    }

    public static function is_enabled() {
        $enabled = get_option(self::ENABLED_OPTION, '1') === '1';
        return (bool) apply_filters('kodanote_sitemap_robots_enabled', $enabled);
    }

    /* ------------------------------------------------------------------
     * Virtual robots.txt
     * ------------------------------------------------------------------ */

    public function register_robots_filter() {
        add_filter('robots_txt', array($this, 'filter_robots_txt'), PHP_INT_MAX, 2);
    }

    /**
     * Add a Sitemap line to WordPress-generated robots.txt when none is listed.
     * Runs last so SEO plugins and core add their own lines first.
     *
     * @param string $output robots.txt text
     * @param string $public blog_public option value
     * @return string
     */
    public function filter_robots_txt($output, $public) {
        if (!$public || !self::is_enabled() || self::robots_has_sitemap($output)) {
            return $output;
        }

        $url = $this->get_sitemap_url_for_robots();
        if ($url === '') {
            return $output;
        }

        return self::append_sitemap_block($output, $url);
    }

    /**
     * Prefer the live plugin state so a sitemap that was switched off today
     * is not listed until tomorrow's check. Plugin detection makes no HTTP
     * requests, so it is cheap on every robots.txt hit. A sitemap found by
     * HTTP probing has no plugin to check, so reuse the stored URL for it.
     *
     * @return string
     */
    private function get_sitemap_url_for_robots() {
        $found = $this->detect_plugin_sitemap();
        if ($found) {
            return $found['url'];
        }

        $status = get_option(self::STATUS_OPTION, array());
        if (is_array($status) && !empty($status['sitemap_url'])
            && isset($status['source']) && $status['source'] === 'probe') {
            return (string) $status['sitemap_url'];
        }

        return self::own_sitemap_url();
    }

    /* ------------------------------------------------------------------
     * Daily check: pick a sitemap and fix a physical robots.txt
     * ------------------------------------------------------------------ */

    public function run_check() {
        if (!self::is_enabled()) {
            $this->remove_physical_block();
            delete_option(self::STATUS_OPTION);
            return;
        }

        $home = home_url('/');
        $live_robots = $this->fetch(home_url('/robots.txt'));
        $loopback_ok = $live_robots !== null;

        $found = $this->detect_plugin_sitemap();
        if ($found && $loopback_ok && !$this->url_is_sitemap($found['url'])) {
            $found = null;
        }
        if (!$found && $loopback_ok) {
            $found = $this->probe_common_sitemaps();
        }

        $use_query_urls = get_option('permalink_structure', '') === '';
        if (!$found) {
            // Some servers never pass unknown .xml paths to WordPress. Fall
            // back to a query-string URL when the pretty URL does not load.
            if (!$use_query_urls && $loopback_ok
                && !$this->url_is_sitemap(self::build_own_url($home, false, 'index'))
                && $this->url_is_sitemap(self::build_own_url($home, true, 'index'))) {
                $use_query_urls = true;
            }
            $found = array(
                'url' => self::build_own_url($home, $use_query_urls, 'index'),
                'source' => 'own',
            );
        }

        $robots_state = $this->sync_physical_robots($found['url']);

        if ($robots_state === 'physical_updated') {
            $live_robots = $this->fetch(home_url('/robots.txt'));
        }

        $live_has_sitemap = null;
        if ($live_robots !== null && $live_robots['code'] === 200) {
            $live_has_sitemap = self::robots_has_sitemap($live_robots['body']);
        }

        update_option(self::STATUS_OPTION, array(
            'checked_at' => time(),
            'sitemap_url' => $found['url'],
            'source' => $found['source'],
            'use_query_urls' => $use_query_urls,
            'robots' => $robots_state,
            'robots_live_has_sitemap' => $live_has_sitemap,
        ), false);
    }

    /**
     * Find a sitemap made by a known SEO plugin or WordPress core.
     * No HTTP requests, so it is safe to call on any page load.
     *
     * @return array{url:string,source:string}|null
     */
    private function detect_plugin_sitemap() {
        $checks = array(
            'yoast' => function () {
                return class_exists('WPSEO_Options') && WPSEO_Options::get('enable_xml_sitemap')
                    ? 'sitemap_index.xml' : null;
            },
            'rank_math' => function () {
                return class_exists('\RankMath\Helper') && method_exists('\RankMath\Helper', 'is_module_active')
                    && \RankMath\Helper::is_module_active('sitemap')
                    ? 'sitemap_index.xml' : null;
            },
            'aioseo' => function () {
                if (!function_exists('aioseo')) {
                    return null;
                }
                // Read into a variable: empty() on a magic getter can be wrong.
                $enabled = aioseo()->options->sitemap->general->enable;
                return $enabled ? 'sitemap.xml' : null;
            },
            'seopress' => function () {
                $options = get_option('seopress_xml_sitemap_option_name');
                return defined('SEOPRESS_VERSION') && is_array($options)
                    && !empty($options['seopress_xml_sitemap_general_enable'])
                    ? 'sitemaps.xml' : null;
            },
            'the_seo_framework' => function () {
                return function_exists('tsf') && tsf()->get_option('sitemaps_output') ? 'sitemap.xml' : null;
            },
            'jetpack' => function () {
                return class_exists('Jetpack') && method_exists('Jetpack', 'is_module_active')
                    && Jetpack::is_module_active('sitemaps')
                    ? 'sitemap.xml' : null;
            },
            'google_xml_sitemaps' => function () {
                return class_exists('GoogleSitemapGeneratorLoader') ? 'sitemap.xml' : null;
            },
        );

        foreach ($checks as $source => $check) {
            try {
                $path = $check();
            } catch (Throwable $e) {
                $path = null;
            }
            if ($path) {
                return array('url' => home_url('/' . $path), 'source' => $source);
            }
        }

        try {
            if (function_exists('wp_sitemaps_get_server') && function_exists('get_sitemap_url')
                && wp_sitemaps_get_server()->sitemaps_enabled()) {
                $url = get_sitemap_url('index');
                if ($url) {
                    return array('url' => $url, 'source' => 'wordpress_core');
                }
            }
        } catch (Throwable $e) {
            // Fall through to HTTP probing or our own sitemap.
        }

        return null;
    }

    /**
     * @return array{url:string,source:string}|null
     */
    private function probe_common_sitemaps() {
        foreach (self::COMMON_SITEMAP_PATHS as $path) {
            $url = home_url('/' . $path);
            if ($this->url_is_sitemap($url)) {
                return array('url' => $url, 'source' => 'probe');
            }
        }
        return null;
    }

    /**
     * Keep our block in a physical robots.txt in line with the chosen sitemap.
     * We never create robots.txt: a new file would replace the WordPress one.
     *
     * @param string $sitemap_url Sitemap to list
     * @return string State for the status option
     */
    private function sync_physical_robots($sitemap_url) {
        if (!get_option('blog_public')) {
            $this->remove_physical_block();
            return 'not_public';
        }

        // Crawlers only read robots.txt at the domain root.
        if (self::home_has_subdirectory()) {
            return 'subdirectory';
        }

        // One robots.txt file serves every site in the network.
        if (is_multisite()) {
            return 'multisite';
        }

        $path = $this->physical_robots_path();
        if (!file_exists($path)) {
            return 'virtual';
        }

        $current = $this->read_file($path);
        if ($current === null) {
            return 'physical_not_readable';
        }
        $without_block = self::strip_sitemap_block($current);

        if (self::robots_has_sitemap($without_block)) {
            if ($without_block !== $current) {
                $this->write_file($path, $without_block);
            }
            return 'physical_has_sitemap';
        }

        if (self::block_sitemap_url($current) === $sitemap_url) {
            return 'physical_ok';
        }

        $updated = self::append_sitemap_block($without_block, $sitemap_url);
        return $this->write_file($path, $updated) ? 'physical_updated' : 'physical_not_writable';
    }

    public function remove_physical_block() {
        if (is_multisite() || self::home_has_subdirectory()) {
            return;
        }
        $path = $this->physical_robots_path();
        if (!file_exists($path)) {
            return;
        }
        $current = $this->read_file($path);
        if ($current === null) {
            return;
        }
        $without_block = self::strip_sitemap_block($current);
        if ($without_block !== $current) {
            $this->write_file($path, $without_block);
        }
    }

    private static function home_has_subdirectory() {
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH);
        return is_string($home_path) && trim($home_path, '/') !== '';
    }

    /**
     * robots.txt lives in the directory that serves the home URL. When
     * WordPress is installed in a subfolder (site_url ends in /wp while the
     * home URL is the domain root), that is the parent of ABSPATH.
     *
     * Computed from the URLs, not from $_SERVER['SCRIPT_FILENAME'] like
     * get_home_path(), because that value is unreliable in cron and WP-CLI.
     */
    private function physical_robots_path() {
        $dir = untrailingslashit(wp_normalize_path(ABSPATH));
        $site_path = trim((string) wp_parse_url(site_url(), PHP_URL_PATH), '/');

        if ($site_path !== '' && !self::home_has_subdirectory()) {
            $suffix = '/' . $site_path;
            if (substr($dir, -strlen($suffix)) === $suffix) {
                $dir = substr($dir, 0, -strlen($suffix));
            }
        }

        return $dir . '/robots.txt';
    }

    /**
     * @return string|null Null when the file cannot be read
     */
    private function read_file($path) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, same approach as core's insert_with_markers()
        $contents = @file_get_contents($path);
        return is_string($contents) ? $contents : null;
    }

    /**
     * Overwrite an existing file under an exclusive lock. Same approach as
     * WordPress core uses for .htaccess (insert_with_markers), which also
     * works on hosts where WP_Filesystem would demand FTP credentials.
     * Never creates a file: a new robots.txt would replace the WordPress one.
     *
     * @return bool
     */
    private function write_file($path, $contents) {
        if (!file_exists($path) || !wp_is_writable($path)) {
            return false;
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions -- Direct local write with a lock, see insert_with_markers()
        $handle = @fopen($path, 'r+');
        if (!$handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        $ok = ftruncate($handle, 0) && rewind($handle) && fwrite($handle, $contents) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        // phpcs:enable

        clearstatcache(true, $path);

        return $ok;
    }

    /**
     * @param string $url
     * @return array{code:int,body:string}|null Null when the request failed
     */
    private function fetch($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'redirection' => 3,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'limit_response_size' => 262144,
        ));
        if (is_wp_error($response)) {
            return null;
        }
        return array(
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        );
    }

    private function url_is_sitemap($url) {
        $response = $this->fetch($url);
        return $response !== null && $response['code'] === 200 && self::is_sitemap_xml($response['body']);
    }

    /* ------------------------------------------------------------------
     * Our own sitemap
     * ------------------------------------------------------------------ */

    /**
     * Serve /content-sitemap.xml, /content-sitemap-{pt|tax}-{name}-{page}.xml,
     * or the ?content_sitemap= fallback for sites without pretty permalinks.
     *
     * @param WP $wp
     */
    public function maybe_serve_sitemap($wp) {
        $use_query_urls = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only sitemap
        if (isset($_GET[self::QUERY_VAR])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only sitemap
            $slug = sanitize_key(wp_unslash($_GET[self::QUERY_VAR]));
            $use_query_urls = true;
        } else {
            $slug = self::slug_from_path(isset($wp->request) ? (string) $wp->request : '');
        }

        $request = $slug !== null ? self::parse_sitemap_slug($slug) : null;
        if (!$request || !self::is_enabled() || !get_option('blog_public')) {
            return;
        }

        $xml = $request['kind'] === 'index'
            ? $this->render_index($use_query_urls)
            : $this->render_sitemap_page($request['kind'], $request['name'], $request['page']);

        if ($xml === null) {
            return;
        }

        // Stop page-cache plugins from storing this XML as a page.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is escaped in the render helpers
        echo $xml;
        exit;
    }

    public static function own_sitemap_url() {
        $status = get_option(self::STATUS_OPTION, array());
        $use_query_urls = get_option('permalink_structure', '') === ''
            || (is_array($status) && !empty($status['use_query_urls']));
        return self::build_own_url(home_url('/'), $use_query_urls, 'index');
    }

    /**
     * @param bool $use_query_urls Keep child URLs in the same style as the index URL
     * @return string
     */
    private function render_index($use_query_urls) {
        $home = home_url('/');
        $home_type = $this->home_sitemap_type();
        $locs = array();

        foreach ($this->get_post_types() as $type) {
            $pages = (int) ceil($this->count_posts($type) / self::URLS_PER_SITEMAP);
            if ($type === $home_type) {
                $pages = max(1, $pages);
            }
            for ($page = 1; $page <= $pages; $page++) {
                $locs[] = self::build_own_url($home, $use_query_urls, 'pt', $type, $page);
            }
        }

        foreach ($this->get_taxonomies() as $taxonomy) {
            $pages = (int) ceil($this->count_terms($taxonomy) / self::URLS_PER_SITEMAP);
            for ($page = 1; $page <= $pages; $page++) {
                $locs[] = self::build_own_url($home, $use_query_urls, 'tax', $taxonomy, $page);
            }
        }

        return self::render_index_xml($locs);
    }

    /**
     * @return string|null Null for unknown types (WordPress shows 404).
     *                     Pages past the end return an empty, valid sitemap.
     */
    private function render_sitemap_page($kind, $name, $page) {
        $entries = $kind === 'pt'
            ? $this->post_type_entries($name, $page)
            : $this->taxonomy_entries($name, $page);

        if ($entries === null) {
            return null;
        }

        return self::render_urlset_xml($entries);
    }

    private function post_type_entries($type, $page) {
        if (!in_array($type, $this->get_post_types(), true)) {
            return null;
        }

        $entries = array();
        $home = home_url('/');
        // The homepage is listed once, at the top of the first sitemap. A
        // static front page has the same permalink, so skip it in its own type.
        $seen = array($home => true, untrailingslashit($home) => true);

        if ($page === 1 && $type === $this->home_sitemap_type()) {
            $entries[] = array('loc' => $home, 'lastmod' => '');
        }

        $query = new WP_Query(array(
            'post_type' => $type,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => self::URLS_PER_SITEMAP,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ));

        foreach ($query->posts as $post) {
            if ($this->is_noindex_post($post->ID)) {
                continue;
            }
            $loc = get_permalink($post);
            if (!$loc || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $entries[] = array(
                'loc' => $loc,
                'lastmod' => $post->post_modified_gmt && $post->post_modified_gmt !== '0000-00-00 00:00:00'
                    ? gmdate(DATE_W3C, strtotime($post->post_modified_gmt . ' UTC'))
                    : '',
            );
        }

        return $entries;
    }

    private function taxonomy_entries($taxonomy, $page) {
        if (!in_array($taxonomy, $this->get_taxonomies(), true)) {
            return null;
        }

        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'hierarchical' => false,
            'orderby' => 'term_id',
            'number' => self::URLS_PER_SITEMAP,
            'offset' => ($page - 1) * self::URLS_PER_SITEMAP,
        ));

        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        $entries = array();
        foreach ($terms as $term) {
            $loc = get_term_link($term);
            if (!is_wp_error($loc) && $loc) {
                $entries[] = array('loc' => $loc, 'lastmod' => '');
            }
        }
        return $entries;
    }

    private function get_post_types() {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        $types = array_values(array_filter($types, 'is_post_type_viewable'));
        return array_values((array) apply_filters('kodanote_sitemap_post_types', $types));
    }

    private function get_taxonomies() {
        $taxonomies = get_taxonomies(array('public' => true), 'names');
        unset($taxonomies['post_format']);
        if (function_exists('is_taxonomy_viewable')) {
            $taxonomies = array_filter($taxonomies, 'is_taxonomy_viewable');
        }
        return array_values((array) apply_filters('kodanote_sitemap_taxonomies', array_values($taxonomies)));
    }

    /**
     * The homepage goes at the top of the first post type sitemap with content.
     */
    private function home_sitemap_type() {
        $types = $this->get_post_types();
        foreach ($types as $type) {
            if ($this->count_posts($type) > 0) {
                return $type;
            }
        }
        return isset($types[0]) ? $types[0] : '';
    }

    private function count_posts($type) {
        $counts = wp_count_posts($type);
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    private function count_terms($taxonomy) {
        $count = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'hierarchical' => false,
            'fields' => 'count',
        ));
        return is_wp_error($count) ? 0 : (int) $count;
    }

    /**
     * Respect noindex set in common SEO plugins, even when their sitemap is off.
     */
    private function is_noindex_post($post_id) {
        if (get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
            return true;
        }
        $rank_math = get_post_meta($post_id, 'rank_math_robots', true);
        if (is_array($rank_math) && in_array('noindex', $rank_math, true)) {
            return true;
        }
        if (get_post_meta($post_id, '_seopress_robots_index', true) === 'yes') {
            return true;
        }
        if (get_post_meta($post_id, '_genesis_noindex', true)) {
            return true;
        }
        return (bool) apply_filters('kodanote_sitemap_exclude_post', false, $post_id);
    }

    /* ------------------------------------------------------------------
     * WordPress-free helpers (unit tested)
     * ------------------------------------------------------------------ */

    /**
     * @param string $text robots.txt contents
     * @return bool True when any Sitemap: line with a URL is present
     */
    public static function robots_has_sitemap($text) {
        return is_string($text) && preg_match('/^[ \t]*sitemap[ \t]*:[ \t]*\S+/mi', $text) === 1;
    }

    /**
     * Append our marked Sitemap block after the existing rules.
     */
    public static function append_sitemap_block($text, $sitemap_url) {
        $text = rtrim((string) $text, "\r\n");
        $block = self::BLOCK_BEGIN . "\n" . 'Sitemap: ' . $sitemap_url . "\n" . self::BLOCK_END . "\n";
        return $text === '' ? $block : $text . "\n\n" . $block;
    }

    /**
     * Remove our marked block. Text outside the block is kept as-is.
     */
    public static function strip_sitemap_block($text) {
        $text = (string) $text;
        if (strpos($text, self::BLOCK_BEGIN) === false) {
            return $text;
        }

        // Keep one line break so the lines around the block do not join.
        $pattern = '/(\r?\n)?(?:\r?\n)?' . preg_quote(self::BLOCK_BEGIN, '/') . '.*?'
            . preg_quote(self::BLOCK_END, '/') . '[ \t]*(?:\r?\n)?/s';
        $stripped = preg_replace($pattern, '$1', $text);
        if (!is_string($stripped)) {
            return $text;
        }

        if ($stripped !== '' && substr($stripped, -1) !== "\n") {
            $stripped .= "\n";
        }
        return $stripped;
    }

    /**
     * @return string|null Sitemap URL inside our block
     */
    public static function block_sitemap_url($text) {
        $pattern = '/' . preg_quote(self::BLOCK_BEGIN, '/') . '.*?^[ \t]*sitemap[ \t]*:[ \t]*(\S+).*?'
            . preg_quote(self::BLOCK_END, '/') . '/msi';
        return is_string($text) && preg_match($pattern, $text, $m) ? $m[1] : null;
    }

    /**
     * True when a response body is a sitemap or sitemap index, not an HTML page.
     */
    public static function is_sitemap_xml($body) {
        if (!is_string($body) || $body === '') {
            return false;
        }
        $head = substr(ltrim($body), 0, 4096);
        return stripos($head, '<urlset') !== false || stripos($head, '<sitemapindex') !== false;
    }

    /**
     * @param string $home           Home URL
     * @param bool   $use_query_urls Use ?content_sitemap= instead of .xml paths
     * @param string $kind           index, pt or tax
     * @param string $name           Post type or taxonomy name
     * @param int    $page           1-based page
     * @return string
     */
    public static function build_own_url($home, $use_query_urls, $kind, $name = '', $page = 1) {
        $home = rtrim((string) $home, '/') . '/';
        $slug = $kind === 'index' ? 'index' : $kind . '-' . $name . '-' . (int) $page;

        if ($use_query_urls) {
            return $home . '?' . self::QUERY_VAR . '=' . rawurlencode($slug);
        }
        return $slug === 'index' ? $home . 'content-sitemap.xml' : $home . 'content-sitemap-' . $slug . '.xml';
    }

    /**
     * @param string $request Path relative to the home URL, no leading slash
     * @return string|null Slug for parse_sitemap_slug()
     */
    public static function slug_from_path($request) {
        $request = trim((string) $request, '/');
        if ($request === 'content-sitemap.xml') {
            return 'index';
        }
        if (preg_match('/^content-sitemap-([a-z0-9_-]+)\.xml$/', $request, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{kind:string,name:string,page:int}|null
     */
    public static function parse_sitemap_slug($slug) {
        if ($slug === 'index') {
            return array('kind' => 'index', 'name' => '', 'page' => 1);
        }
        if (is_string($slug) && preg_match('/^(pt|tax)-([a-z0-9_-]+)-(\d+)$/', $slug, $m)) {
            return array('kind' => $m[1], 'name' => $m[2], 'page' => max(1, (int) $m[3]));
        }
        return null;
    }

    public static function render_urlset_xml(array $entries) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= "\t<url>\n\t\t<loc>" . self::xml_escape($entry['loc']) . "</loc>\n";
            if (!empty($entry['lastmod'])) {
                $xml .= "\t\t<lastmod>" . self::xml_escape($entry['lastmod']) . "</lastmod>\n";
            }
            $xml .= "\t</url>\n";
        }
        return $xml . "</urlset>\n";
    }

    public static function render_index_xml(array $locs) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($locs as $loc) {
            $xml .= "\t<sitemap>\n\t\t<loc>" . self::xml_escape($loc) . "</loc>\n\t</sitemap>\n";
        }
        return $xml . "</sitemapindex>\n";
    }

    private static function xml_escape($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
