<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Admin Settings Template
 */

if (!defined('ABSPATH')) {
    exit;
}
$kodanote_api_base_url = trim(KODANOTE_API_BASE_URL);
?>

<div class="wrap" id="kodanote-settings">
    <div class="kodanote-header">
        <div class="kodanote-title">
            <svg class="kodanote-brand-mark" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M7 12H15M7 12L15 5.25M7 12L15 18.75" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" opacity="0.4" />
                <rect x="1.25" y="8.75" width="6.5" height="6.5" rx="2.1" fill="currentColor" />
                <circle cx="18.25" cy="4.75" r="2.75" fill="#12a594" />
                <circle cx="18.25" cy="12" r="2.75" fill="#12a594" />
                <circle cx="18.25" cy="19.25" r="2.75" fill="#12a594" />
            </svg>
            <div>
                <h1><?php esc_html_e('Kodanote Settings', 'kodanote-content-publisher'); ?></h1>
                <p><?php esc_html_e('Connect your WordPress site to Kodanote.', 'kodanote-content-publisher'); ?></p>
            </div>
        </div>
    </div>

    <?php
    $kodanote_sync_error = Kodanote_Scheduler::get_sync_error();
    if (is_array($kodanote_sync_error) && !empty($kodanote_sync_error['message'])):
        $friendly = !empty($kodanote_sync_error['friendly_message']) ? $kodanote_sync_error['friendly_message'] : $kodanote_sync_error['message'];
    ?>
    <div class="notice notice-error" style="margin: 15px 0; padding: 12px 15px; border-left-color: #dc3232;">
        <p style="margin: 0 0 6px; font-size: 14px;">
            <strong>⚠ <?php esc_html_e('Article sync is failing', 'kodanote-content-publisher'); ?></strong>
        </p>
        <p style="margin: 0; font-size: 13px;">
            <?php echo esc_html($friendly); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="kodanote-settings-content">
        <form method="post" action="options.php">
            <?php settings_fields('kodanote_settings'); ?>

            <!-- API Configuration -->
            <div class="kodanote-info-box">
                <h3><?php esc_html_e('API Configuration', 'kodanote-content-publisher'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('API Endpoint', 'kodanote-content-publisher'); ?></th>
                        <td>
                            <?php if ($kodanote_api_base_url !== ''): ?>
                                <p><strong><?php esc_html_e('Configured', 'kodanote-content-publisher'); ?></strong> — <code><?php echo esc_html($kodanote_api_base_url); ?></code></p>
                            <?php else: ?>
                                <p><strong><?php esc_html_e('Not configured', 'kodanote-content-publisher'); ?></strong></p>
                                <p class="description"><?php esc_html_e('Publishing is unavailable until a Kodanote API endpoint is configured. No endpoint is enabled by default.', 'kodanote-content-publisher'); ?></p>
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e('Set KODANOTE_API_BASE_URL in wp-config.php to the API base URL supplied by your Kodanote administrator. Configure the endpoint before saving your API key and testing the connection.', 'kodanote-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('API Key', 'kodanote-content-publisher'); ?></th>
                        <td>
                            <input type="password"
                                   name="kodanote_api_key"
                                   autocomplete="new-password"
                                   value="<?php echo esc_attr(get_option('kodanote_api_key')); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_html_e('Your Kodanote API Key', 'kodanote-content-publisher'); ?>" />
                            <p class="description">
                                <?php esc_html_e('Use the API key supplied for this site by your Kodanote administrator. Save your settings before testing the connection.', 'kodanote-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="kodanote-connection-test">
                    <button type="button" id="test-api-connection" class="button button-secondary">
                        <?php esc_html_e('Test API Connection', 'kodanote-content-publisher'); ?>
                    </button>
                    <span id="connection-status"></span>
                </div>
            </div>

            <!-- Publishing Settings -->
            <div class="kodanote-info-box">
                <h3><?php esc_html_e('Publishing Settings', 'kodanote-content-publisher'); ?></h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Default Category', 'kodanote-content-publisher'); ?></th>
                        <td>
                            <?php wp_dropdown_categories(array(
                                'name' => 'kodanote_post_category',
                                'selected' => get_option('kodanote_post_category', '1'),
                                'hide_empty' => false,
                                'show_option_none' => __('Select Category', 'kodanote-content-publisher'),
                            )); ?>
                            <p class="description">
                                <?php esc_html_e('Default category for new Kodanote articles. If you change an article\'s category in WordPress, it will be preserved on future updates.', 'kodanote-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Default Author', 'kodanote-content-publisher'); ?></th>
                        <td>
                            <?php wp_dropdown_users(array(
                                'name' => 'kodanote_author_id',
                                'selected' => get_option('kodanote_author_id', get_current_user_id()),
                                'show_option_none' => __('Select Author', 'kodanote-content-publisher'),
                            )); ?>
                            <p class="description">
                                <?php esc_html_e('Default author for Kodanote articles.', 'kodanote-content-publisher'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Debug Settings -->
            <div class="kodanote-info-box">
                <h3><?php esc_html_e('Debug Information', 'kodanote-content-publisher'); ?></h3>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Debug Logging', 'kodanote-content-publisher'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="kodanote_debug_mode" value="0" />
                                <input type="checkbox"
                                       name="kodanote_debug_mode"
                                       value="1"
                                       <?php checked(get_option('kodanote_debug_mode'), '1'); ?> />
                                <?php esc_html_e('Enable debug logging', 'kodanote-content-publisher'); ?>
                            </label>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: log file path */
                                    esc_html__('Log file: %s', 'kodanote-content-publisher'),
                                    '<code>' . esc_html(WP_CONTENT_DIR . '/debug.log') . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button(__('Save Settings', 'kodanote-content-publisher')); ?>
        </form>

        <!-- System Information & Maintenance (outside the form) -->
        <div class="kodanote-info-box">
            <h3><?php esc_html_e('System Information', 'kodanote-content-publisher'); ?></h3>
            <table class="widefat">
                <tr>
                    <td><strong><?php esc_html_e('Plugin Version', 'kodanote-content-publisher'); ?></strong></td>
                    <td><?php echo esc_html(KODANOTE_VERSION); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('WordPress Version', 'kodanote-content-publisher'); ?></strong></td>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('PHP Version', 'kodanote-content-publisher'); ?></strong></td>
                    <td><?php echo esc_html(PHP_VERSION); ?></td>
                </tr>
            </table>
        </div>

        <div class="kodanote-info-box">
            <h3><?php esc_html_e('Maintenance Actions', 'kodanote-content-publisher'); ?></h3>
            <p><?php esc_html_e('Use these actions carefully as they cannot be undone.', 'kodanote-content-publisher'); ?></p>
            <div class="kodanote-actions">
                <button type="button" id="reset-settings" class="button button-secondary">
                    <?php esc_html_e('Reset All Settings', 'kodanote-content-publisher'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$kodanote_settings_inline_css = '
.kodanote-settings-content {
    margin-top: 20px;
    max-width: 800px;
}

.kodanote-info-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.kodanote-info-box h3 {
    margin: 0 0 15px 0;
    color: #1d2327;
    font-size: 1.2em;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f0;
}

.kodanote-info-box table.widefat {
    border: none;
    margin: 0;
}

.kodanote-info-box table.widefat tr {
    border-bottom: 1px solid #f0f0f0;
}

.kodanote-info-box table.widefat td {
    padding: 8px 0;
    border: none;
}

.kodanote-info-box table.widefat td:first-child {
    font-weight: 600;
    width: 200px;
}

#kodanote-settings .form-table th {
    width: 200px;
}

.kodanote-connection-test {
    margin: 15px 0 0 0;
    padding: 15px;
    background: #edf9f6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.kodanote-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

@media (max-width: 768px) {
    .kodanote-settings-content {
        max-width: 100%;
    }

    .kodanote-connection-test {
        flex-direction: column;
        align-items: flex-start;
    }
}
';
wp_add_inline_style('kodanote-admin', $kodanote_settings_inline_css);
?>
