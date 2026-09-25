<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Scheduler
 * 
 * Handles scheduled tasks like automatic article syncing
 * 
 * Sync frequency is adaptive based on time since API key was set:
 * - First 10 minutes: every 1 minute
 * - 10-60 minutes: every 5 minutes  
 * - After 60 minutes: hourly
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Kodanote_Scheduler {

    /**
     * Constructor
     */
    public function __construct() {
        // Register custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        // Schedule sync with appropriate interval
        $this->schedule_adaptive_sync();

        // Hook into the scheduled event
        add_action('kodanote_auto_sync', array($this, 'run_auto_sync'));
    }

    /**
     * Add custom cron schedules for more frequent syncing
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
     * Get the appropriate sync interval based on time since API key was set
     * 
     * @return string WordPress cron schedule name
     */
    public function get_adaptive_interval() {
        $api_key_set_time = get_option('kodanote_api_key_set_time', 0);
        
        // If no timestamp stored, use hourly (legacy behavior)
        if (empty($api_key_set_time)) {
            return 'hourly';
        }
        
        $minutes_since_setup = (time() - $api_key_set_time) / 60;
        
        if ($minutes_since_setup < 10) {
            // First 10 minutes: sync every minute
            return 'kodanote_every_minute';
        } elseif ($minutes_since_setup < 60) {
            // 10-60 minutes: sync every 5 minutes
            return 'kodanote_every_five_minutes';
        } else {
            // After 60 minutes: sync hourly
            return 'hourly';
        }
    }

    /**
     * Schedule sync with the appropriate adaptive interval
     */
    public function schedule_adaptive_sync() {
        // Push publishing does not require an outbound API or polling cron.
        if (rtrim(trim((string) KODANOTE_API_BASE_URL), '/') === '') {
            wp_clear_scheduled_hook('kodanote_auto_sync');
            return;
        }

        $desired_interval = $this->get_adaptive_interval();
        $current_event = wp_get_scheduled_event('kodanote_auto_sync');
        
        // Check if we need to reschedule
        if ($current_event) {
            // If already scheduled with the correct interval, do nothing
            if ($current_event->schedule === $desired_interval) {
                return;
            }
            // Otherwise, clear and reschedule
            wp_clear_scheduled_hook('kodanote_auto_sync');
        }
        
        // Schedule with the appropriate interval
        wp_schedule_event(time(), $desired_interval, 'kodanote_auto_sync');
        $this->log_debug('Sync scheduled with interval: ' . $desired_interval);
    }

    /**
     * Run automatic article sync
     * 
     * This is called by WordPress cron on a scheduled basis
     */
    public function run_auto_sync() {
        // A previously queued cron may still fire after pull sync is disabled.
        if (rtrim(trim((string) KODANOTE_API_BASE_URL), '/') === '') {
            wp_clear_scheduled_hook('kodanote_auto_sync');
            return;
        }

        // Only run if API key is configured
        $api_key = get_option('kodanote_api_key', '');
        if (empty($api_key)) {
            $this->log_debug('Auto-sync skipped: No API key configured');
            return;
        }

        // Re-evaluate and reschedule if interval should change
        $this->schedule_adaptive_sync();

        $this->log_debug('Starting automatic article sync');

        try {
            $api = new Kodanote_API();
            $result = $api->sync_articles();

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                $this->log_debug('Auto-sync failed: ' . $error_message);
                self::store_sync_error($error_message);
            } elseif (!empty($result['skipped'])) {
                // A skipped sync published nothing, so it must never be reported as a
                // success: doing so cleared the error state and told Kodanote everything
                // was fine while the site silently stopped publishing.
                $this->handle_skipped_auto_sync($api, $result);
            } else {
                $this->log_debug('Auto-sync completed: ' . $result['synced_count'] . ' articles synced');

                delete_option('kodanote_consecutive_sync_skips');

                // Clear connection error on successful API call (individual article errors are different)
                self::clear_sync_error();
                
                // Log errors if any
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->log_debug('Sync error: ' . $error);
                    }
                }
                
                // Send completion webhook with error details for remote diagnosis
                $api->send_webhook('sync_completed', array(
                    'articles_count' => $result['synced_count'],
                    'errors_count' => count($result['errors']),
                    'errors' => array_slice($result['errors'], 0, 10), // Limit to first 10 errors to avoid payload size issues
                ));
            }
        } catch (Exception $e) {
            $this->log_debug('Auto-sync exception: ' . $e->getMessage());
            self::store_sync_error($e->getMessage());
        }
    }

    /**
     * Handle an auto-sync that bailed out because another sync held the lock.
     *
     * One skipped run is normal when a manual sync or a server push overlaps the
     * cron. Several in a row means the lock is stranded or a sync keeps dying, so
     * report it as a real error to both the plugin admin and Kodanote.
     *
     * @param Kodanote_API $api    API client used to send the webhook
     * @param array       $result Skipped sync result
     */
    private function handle_skipped_auto_sync($api, $result) {
        $skips = (int) get_option('kodanote_consecutive_sync_skips', 0) + 1;
        update_option('kodanote_consecutive_sync_skips', $skips, false);

        $this->log_debug(sprintf('Auto-sync skipped (%d in a row): %s', $skips, $result['message']));

        if ($skips < 3) {
            return;
        }

        $error_message = sprintf(
            /* translators: %d: number of consecutive skipped syncs */
            __('Sync has been blocked by an unfinished sync %d times in a row. Articles are not being published.', 'kodanote-content-publisher'),
            $skips
        );

        self::store_sync_error($error_message);

        $api->send_webhook('sync_completed', array(
            'articles_count' => 0,
            'errors_count' => 1,
            'errors' => array($error_message),
            'skipped' => true,
        ));
    }

    /**
     * Manually trigger sync (for testing or admin actions)
     * 
     * @param bool $force_resync Force resync all articles
     * @return array|WP_Error
     */
    public function trigger_manual_sync($force_resync = false) {
        $api = new Kodanote_API();
        return $api->sync_articles($force_resync);
    }

    /**
     * Clear scheduled sync events
     */
    public function clear_scheduled_events() {
        wp_clear_scheduled_hook('kodanote_auto_sync');
        $this->log_debug('Scheduled sync events cleared');
    }

    /**
     * Get next scheduled sync time
     * 
     * @return string|null
     */
    public function get_next_sync_time() {
        $timestamp = wp_next_scheduled('kodanote_auto_sync');
        
        if (!$timestamp) {
            return null;
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Store a sync connection error for display in the admin UI
     */
    public static function store_sync_error($error_message) {
        if (empty(trim($error_message))) {
            return;
        }

        $error_data = array(
            'message' => $error_message,
            'timestamp' => current_time('c'),
            'friendly_message' => self::get_friendly_error_message($error_message),
        );
        update_option('kodanote_sync_error', $error_data, false);
    }

    /**
     * Clear the stored sync error (called on successful sync)
     */
    public static function clear_sync_error() {
        delete_option('kodanote_sync_error');
    }

    /**
     * Get the stored sync error, or null if none
     */
    public static function get_sync_error() {
        return get_option('kodanote_sync_error', null);
    }

    /**
     * Translate technical cURL/connection errors into actionable user-facing messages
     */
    private static function get_friendly_error_message($error_message) {
        if (strpos($error_message, 'cURL error 7') !== false || strpos($error_message, 'Failed to connect') !== false) {
            return __('Your server could not connect to the configured publishing API. Check the API URL and service availability, or ask your hosting provider to check outbound connectivity.', 'kodanote-content-publisher');
        }
        if (strpos($error_message, 'cURL error 28') !== false || strpos($error_message, 'timed out') !== false) {
            return __('The connection to the configured publishing API timed out. Check service availability and contact your hosting provider if the problem persists.', 'kodanote-content-publisher');
        }
        if (strpos($error_message, 'cURL error 6') !== false || strpos($error_message, 'Could not resolve') !== false) {
            return __('Your server cannot resolve the configured publishing API hostname. Check the API URL and DNS settings, or contact your hosting provider.', 'kodanote-content-publisher');
        }
        if (strpos($error_message, 'cURL error 35') !== false || strpos($error_message, 'SSL') !== false) {
            return __('There is an SSL/TLS error connecting to the configured publishing API. Check the service certificate and ask your hosting provider to check TLS support.', 'kodanote-content-publisher');
        }
        return null;
    }

    /**
     * Log debug message (only if debug mode is enabled)
     * 
     * @param string $message
     */
    private function log_debug($message) {
        $debug_mode = get_option('kodanote_debug_mode', '0');
        if ($debug_mode === '1') {
            error_log('[Kodanote Scheduler] ' . $message);
        }
    }
}
