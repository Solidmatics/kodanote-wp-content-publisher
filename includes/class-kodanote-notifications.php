<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Notifications
 * 
 * Handles notification system for sync status and cron detection
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Kodanote_Notifications {

    /**
     * Time threshold for sync warning (24 hours in seconds)
     */
    const SYNC_WARNING_THRESHOLD = 86400; // 24 hours

    /**
     * Constructor
     */
    public function __construct() {
        // Add admin notices
        add_action('admin_notices', array($this, 'display_notifications'));
        
        // Add AJAX handler for manual sync
        add_action('wp_ajax_kodanote_manual_sync', array($this, 'ajax_manual_sync'));
        
        // Add AJAX handler for dismissing notifications
        add_action('wp_ajax_kodanote_dismiss_notification', array($this, 'ajax_dismiss_notification'));
    }

    /**
     * Check if last sync was more than 24 hours ago
     * 
     * @return bool
     */
    public function needs_sync() {
        $last_sync = get_option('kodanote_last_sync_time');
        
        // If never synced, we need to sync
        if (empty($last_sync)) {
            return true;
        }

        // Convert last sync time to timestamp
        // strtotime() on an ISO 8601 string (from current_time('c')) returns a UTC Unix timestamp,
        // so we must compare against time() (also UTC), NOT current_time('timestamp') (local time).
        $last_sync_timestamp = strtotime($last_sync);
        $current_timestamp = time();
        
        // Check if more than 24 hours have passed
        $time_difference = $current_timestamp - $last_sync_timestamp;
        
        return $time_difference > self::SYNC_WARNING_THRESHOLD;
    }

    /**
     * Get time since last sync in human-readable format
     * 
     * @return string|null
     */
    public function get_time_since_last_sync() {
        $last_sync = get_option('kodanote_last_sync_time');
        
        if (empty($last_sync)) {
            return null;
        }

        // strtotime() returns UTC Unix timestamp, so compare against time() (UTC)
        $last_sync_timestamp = strtotime($last_sync);
        return human_time_diff($last_sync_timestamp, time());
    }

    /**
     * Check if WordPress cron is working
     * 
     * @return bool
     */
    public function is_cron_working() {
        // Note: We do NOT check DISABLE_WP_CRON here. Many hosting providers
        // disable WordPress's built-in cron triggering and use a server-level
        // cron job to hit wp-cron.php instead — this is actually the recommended
        // setup for reliable cron. Cron events still execute normally in that
        // configuration, so DISABLE_WP_CRON does not mean "cron is broken."

        // Check if our scheduled event exists -- if not, try to reschedule it
        // rather than blaming cron (the event may simply not have been registered yet)
        if (!wp_next_scheduled('kodanote_auto_sync')) {
            // Attempt to re-register the event before concluding cron is broken
            wp_schedule_event(time(), 'hourly', 'kodanote_auto_sync');
        }

        // If scheduling still failed, something is genuinely wrong
        if (!wp_next_scheduled('kodanote_auto_sync')) {
            return false;
        }

        // Check if there are many past-due scheduled events
        // This would indicate cron isn't actually executing
        $crons = _get_cron_array();
        if (!is_array($crons) || empty($crons)) {
            return true; // No crons = can't determine, assume it's okay
        }

        // IMPORTANT: Cron timestamps are stored in UTC, so we must use time()
        // (UTC Unix timestamp), NOT current_time('timestamp') which returns
        // local time and causes false positives in non-UTC timezones.
        $current_time = time();
        $overdue_count = 0;

        foreach ($crons as $timestamp => $cron) {
            // Skip any non-numeric keys (safety guard)
            if (!is_numeric($timestamp)) {
                continue;
            }
            if ($timestamp < ($current_time - 600)) { // More than 10 minutes overdue
                $overdue_count++;
            }
        }

        // If there are multiple overdue cron jobs, likely cron isn't running
        if ($overdue_count > 3) {
            return false;
        }

        return true;
    }

    /**
     * Display admin notifications
     */
    public function display_notifications() {
        // Only show on admin pages
        if (!is_admin()) {
            return;
        }

        // Don't show if API key is not configured
        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            return;
        }

        $screen = get_current_screen();
        
        // Show on dashboard and Kodanote plugin pages
        $show_on_pages = array(
            'dashboard',
            'toplevel_page_kodanote',
            'kodanote_page_kodanote-settings'
        );

        if (!in_array($screen->id, $show_on_pages, true)) {
            return;
        }

        // Check if notification was dismissed (expires after 1 hour)
        $dismissed_until = get_transient('kodanote_notification_dismissed');
        if ($dismissed_until) {
            return;
        }

        // Display sync warning notification
        if ($this->needs_sync()) {
            $this->display_sync_warning();
        }

        // Display cron warning notification
        if (!$this->is_cron_working()) {
            $this->display_cron_warning();
        }
    }

    /**
     * Display sync warning notification
     */
    private function display_sync_warning() {
        $time_since = $this->get_time_since_last_sync();
        $message = $time_since 
            /* translators: %s: human-readable time since last sync (e.g., "2 hours", "3 days") */
            ? sprintf(__('Your articles haven\'t synced in %s.', 'kodanote-content-publisher'), $time_since)
            : __('Your articles have never been synced.', 'kodanote-content-publisher');
        
        ?>
        <div class="notice notice-warning kodanote-sync-notice is-dismissible" data-notice-type="sync">
            <div class="kodanote-notification-content">
                <div class="kodanote-notification-icon">
                    <span class="dashicons dashicons-update-alt"></span>
                </div>
                <div class="kodanote-notification-message">
                    <p>
                        <strong><?php esc_html_e('Kodanote Sync Needed', 'kodanote-content-publisher'); ?></strong><br>
                        <?php echo esc_html($message); ?>
                        <?php esc_html_e('Keep your content fresh—sync now to publish your latest articles.', 'kodanote-content-publisher'); ?>
                    </p>
                </div>
                <div class="kodanote-notification-actions">
                    <button type="button" class="button button-primary kodanote-sync-now-btn" data-syncing-text="<?php esc_attr_e('Syncing...', 'kodanote-content-publisher'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Sync Now', 'kodanote-content-publisher'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display cron warning notification
     */
    private function display_cron_warning() {
        ?>
        <div class="notice notice-error kodanote-cron-notice is-dismissible" data-notice-type="cron">
            <div class="kodanote-notification-content">
                <div class="kodanote-notification-icon">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="kodanote-notification-message">
                    <p>
                        <strong><?php esc_html_e('WordPress Cron Not Running', 'kodanote-content-publisher'); ?></strong><br>
                        <?php esc_html_e('Kodanote needs WordPress cron to automatically sync your articles. Your articles won\'t sync on schedule until this is fixed.', 'kodanote-content-publisher'); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('How to fix:', 'kodanote-content-publisher'); ?></strong>
                    </p>
                    <ul style="margin-left: 20px; list-style: disc;">
                        <li><?php esc_html_e('Contact your hosting provider to enable WordPress cron', 'kodanote-content-publisher'); ?></li>
                        <li><?php esc_html_e('Or set up a server cron job to run wp-cron.php', 'kodanote-content-publisher'); ?></li>
                        <li>
                            <?php 
                            printf(
                                /* translators: %s: link to WordPress documentation */
                                esc_html__('Learn more: %s', 'kodanote-content-publisher'),
                                '<a href="https://developer.wordpress.org/plugins/cron/" target="_blank" rel="noopener noreferrer">' . esc_html__('WordPress Cron Documentation', 'kodanote-content-publisher') . '</a>'
                            ); 
                            ?>
                        </li>
                    </ul>
                    <p>
                        <em><?php esc_html_e('In the meantime, you can manually sync articles using the "Sync Now" button.', 'kodanote-content-publisher'); ?></em>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for manual sync
     */
    public function ajax_manual_sync() {
        // Verify nonce
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions', 'kodanote-content-publisher')
            ));
            return;
        }

        try {
            // Initialize API class and trigger sync
            $api = new Kodanote_API();
            $result = $api->sync_articles();
            
            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'message' => $result->get_error_message()
                ));
                return;
            }

            // A skipped sync did nothing, so telling the user it succeeded left them
            // clicking "Sync Now" against a stuck sync with no idea why nothing changed.
            if (!empty($result['skipped'])) {
                wp_send_json_error(array(
                    'message' => __('Another sync is still running, so this one was skipped. Wait a few minutes and try again.', 'kodanote-content-publisher'),
                    'skipped' => true,
                ));
                return;
            }

            // Clear any dismissed notification transients
            delete_transient('kodanote_notification_dismissed');
            
            // Send completion webhook with error details for remote diagnosis
            $api->send_webhook('sync_completed', array(
                'articles_count' => $result['synced_count'],
                'errors_count' => count($result['errors']),
                'errors' => array_slice($result['errors'], 0, 10), // Limit to first 10 errors
                'trigger' => 'manual', // Indicate this was a manual sync
            ));
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'synced_count' => $result['synced_count'],
                'errors' => $result['errors']
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Sync failed: ', 'kodanote-content-publisher') . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX handler for dismissing notifications
     */
    public function ajax_dismiss_notification() {
        // Verify nonce
        check_ajax_referer('kodanote_ajax_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions', 'kodanote-content-publisher')
            ));
            return;
        }

        // Set transient to hide notification for 1 hour
        set_transient('kodanote_notification_dismissed', time(), HOUR_IN_SECONDS);

        wp_send_json_success(array(
            'message' => __('Notification dismissed', 'kodanote-content-publisher')
        ));
    }
}

