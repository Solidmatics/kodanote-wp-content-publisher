<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Admin Dashboard Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get dashboard stats
$kodanote_stats = Kodanote_Admin::get_dashboard_stats();
$kodanote_api_connection = !empty(get_option('kodanote_api_key')) && trim(KODANOTE_API_BASE_URL) !== '';
?>

<div class="wrap" id="kodanote-dashboard">
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
                <h1><?php esc_html_e('Kodanote Dashboard', 'kodanote-content-publisher'); ?></h1>
                <p><?php esc_html_e('Publish content from Kodanote to WordPress.', 'kodanote-content-publisher'); ?></p>
            </div>
        </div>
    </div>

    <?php 
    // Show success message for auto-verification
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['auto_verified']) && $_GET['auto_verified'] === '1'): 
        $kodanote_current_cat = get_option('kodanote_post_category', '1');
        $kodanote_cat_obj = get_category($kodanote_current_cat);
        $kodanote_cat_name = ($kodanote_cat_obj && !is_wp_error($kodanote_cat_obj)) ? $kodanote_cat_obj->name : __('Uncategorized', 'kodanote-content-publisher');
    ?>
    <div style="margin: 15px 0; padding: 20px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px;">
        <p style="margin: 0 0 12px; font-size: 15px;">
            <strong>🎉 <?php esc_html_e('Connected automatically!', 'kodanote-content-publisher'); ?></strong>
            <?php esc_html_e('Your WordPress site is now linked to Kodanote. Articles will sync automatically.', 'kodanote-content-publisher'); ?>
        </p>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <label for="kodanote-dashboard-category" style="font-weight: 600; color: #374151; font-size: 14px; white-space: nowrap;">
                <?php esc_html_e('Articles will be published in:', 'kodanote-content-publisher'); ?>
            </label>
            <?php wp_dropdown_categories(array(
                'name' => 'kodanote_dashboard_category',
                'id' => 'kodanote-dashboard-category',
                'selected' => $kodanote_current_cat,
                'hide_empty' => false,
                'show_option_none' => __('Select Category', 'kodanote-content-publisher'),
                'class' => 'kodanote-dash-cat-select',
            )); ?>
            <span id="kodanote-cat-save-status" style="font-size: 13px; color: #059669; display: none;">✓ <?php esc_html_e('Saved', 'kodanote-content-publisher'); ?></span>
        </div>
        <p style="margin: 8px 0 0; color: #9ca3af; font-size: 12px;">
            <?php esc_html_e('You can change this later in Settings.', 'kodanote-content-publisher'); ?>
        </p>
    </div>
    <style>.kodanote-dash-cat-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; cursor: pointer; } .kodanote-dash-cat-select:focus { border-color: #12a594; outline: none; }</style>
    <script>
    (function() {
        var select = document.getElementById('kodanote-dashboard-category');
        var status = document.getElementById('kodanote-cat-save-status');
        if (!select) return;
        select.addEventListener('change', function() {
            var categoryId = select.value;
            if (!categoryId || categoryId === '-1' || categoryId === '0') return;
            status.style.display = 'none';
            var formData = new FormData();
            formData.append('action', 'kodanote_save_post_category');
            formData.append('nonce', '<?php echo esc_js(wp_create_nonce('kodanote_ajax_nonce')); ?>');
            formData.append('category_id', categoryId);
            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST', body: formData, credentials: 'same-origin'
            }).then(function() {
                status.style.display = 'inline';
            });
        });
    })();
    </script>
    <?php 
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    elseif (isset($_GET['setup']) && $_GET['setup'] === 'complete'): 
    ?>
    <div class="notice notice-success is-dismissible" style="margin: 15px 0; padding: 12px 15px;">
        <p style="margin: 0; font-size: 14px;">
            <strong>✅ <?php esc_html_e('Setup complete!', 'kodanote-content-publisher'); ?></strong>
            <?php esc_html_e('Your Kodanote plugin is ready. Articles will sync automatically.', 'kodanote-content-publisher'); ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    $kodanote_sync_error = Kodanote_Scheduler::get_sync_error();
    if (is_array($kodanote_sync_error) && !empty($kodanote_sync_error['message'])):
        $friendly = !empty($kodanote_sync_error['friendly_message']) ? $kodanote_sync_error['friendly_message'] : $kodanote_sync_error['message'];
        $error_time = !empty($kodanote_sync_error['timestamp']) ? human_time_diff(strtotime($kodanote_sync_error['timestamp']), current_time('timestamp')) . ' ' . esc_html__('ago', 'kodanote-content-publisher') : '';
    ?>
    <div class="notice notice-error" style="margin: 15px 0; padding: 12px 15px; border-left-color: #dc3232;">
        <p style="margin: 0 0 6px; font-size: 14px;">
            <strong>⚠ <?php esc_html_e('Article sync is failing', 'kodanote-content-publisher'); ?></strong>
        </p>
        <p style="margin: 0 0 6px; font-size: 13px;">
            <?php echo esc_html($friendly); ?>
        </p>
        <?php if ($error_time): ?>
        <p style="margin: 0; font-size: 12px; color: #666;">
            <?php
            /* translators: %s: human-readable time difference (e.g. "2 hours ago") */
            printf(esc_html__('Last error: %s', 'kodanote-content-publisher'), esc_html($error_time));
            ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="kodanote-stats-grid">
        <div class="kodanote-stat-card">
            <div class="kodanote-stat-icon">
                <span class="dashicons dashicons-media-document"></span>
            </div>
            <div class="kodanote-stat-content">
                <div class="kodanote-stat-number"><?php echo number_format($kodanote_stats['total_synced']); ?></div>
                <div class="kodanote-stat-label"><?php esc_html_e('Total Articles', 'kodanote-content-publisher'); ?></div>
            </div>
        </div>

        <div class="kodanote-stat-card">
            <div class="kodanote-stat-icon">
                <span class="dashicons dashicons-yes"></span>
            </div>
            <div class="kodanote-stat-content">
                <div class="kodanote-stat-number"><?php echo number_format($kodanote_stats['published']); ?></div>
                <div class="kodanote-stat-label"><?php esc_html_e('Published', 'kodanote-content-publisher'); ?></div>
            </div>
        </div>

        <div class="kodanote-stat-card">
            <div class="kodanote-stat-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="kodanote-stat-content">
                <div class="kodanote-stat-number"><?php echo number_format($kodanote_stats['pending']); ?></div>
                <div class="kodanote-stat-label"><?php esc_html_e('Pending', 'kodanote-content-publisher'); ?></div>
            </div>
        </div>

        <div class="kodanote-stat-card">
            <div class="kodanote-stat-icon">
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="kodanote-stat-content">
                <div class="kodanote-stat-number">
                    <?php echo $kodanote_stats['last_sync'] ? esc_html(human_time_diff(strtotime($kodanote_stats['last_sync']), current_time('timestamp'))) . ' ' . esc_html__('ago', 'kodanote-content-publisher') : esc_html__('Never', 'kodanote-content-publisher'); ?>
                </div>
                <div class="kodanote-stat-label"><?php esc_html_e('Last Sync', 'kodanote-content-publisher'); ?></div>
            </div>
        </div>
    </div>

    <!-- Recent Articles -->
    <div class="kodanote-recent-articles">
        <div class="kodanote-section-header">
            <h2><?php esc_html_e('Recent Articles', 'kodanote-content-publisher'); ?></h2>
        </div>

        <?php if (!empty($kodanote_stats['recent_articles'])): ?>
        <div class="kodanote-articles-table">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'kodanote-content-publisher'); ?></th>
                        <th><?php esc_html_e('Status', 'kodanote-content-publisher'); ?></th>
                        <th><?php esc_html_e('Synced', 'kodanote-content-publisher'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kodanote_stats['recent_articles'] as $kodanote_article): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($kodanote_article->title); ?></strong>
                        </td>
                        <td>
                            <span class="kodanote-status kodanote-status-<?php echo esc_attr($kodanote_article->status); ?>">
                                <?php echo esc_html(ucfirst($kodanote_article->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html(human_time_diff(strtotime($kodanote_article->synced_at), current_time('timestamp'))); ?> ago</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="kodanote-empty-state">
            <div class="kodanote-empty-icon">
                <span class="dashicons dashicons-media-document"></span>
            </div>
            <h3><?php esc_html_e('No articles yet', 'kodanote-content-publisher'); ?></h3>
            <p><?php esc_html_e('Articles from Kodanote will appear here once synced.', 'kodanote-content-publisher'); ?></p>
            <?php if ($kodanote_api_connection): ?>
            <button id="sync-first-articles-btn" class="button button-primary">
                <?php esc_html_e('Sync Your First Articles', 'kodanote-content-publisher'); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="kodanote-quick-actions" style="margin-top: 40px;">
        <div class="kodanote-section-header">
            <h2><?php esc_html_e('Quick Actions', 'kodanote-content-publisher'); ?></h2>
        </div>

        <div class="kodanote-stats-grid">
            <?php if ($kodanote_api_connection): ?>
            <div class="kodanote-stat-card">
                <div class="kodanote-stat-icon">
                    <span class="dashicons dashicons-update"></span>
                </div>
                <div class="kodanote-stat-content">
                    <div class="kodanote-stat-label"><?php esc_html_e('Sync Articles', 'kodanote-content-publisher'); ?></div>
                    <p style="margin: 10px 0; font-size: 13px; color: #646970;"><?php esc_html_e('Import latest articles from Kodanote', 'kodanote-content-publisher'); ?></p>
                    <button id="quick-sync-btn" class="button button-primary">
                        <?php esc_html_e('Sync Now', 'kodanote-content-publisher'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="kodanote-stat-card">
                <div class="kodanote-stat-icon">
                    <span class="dashicons dashicons-admin-settings"></span>
                </div>
                <div class="kodanote-stat-content">
                    <div class="kodanote-stat-label"><?php esc_html_e('Settings', 'kodanote-content-publisher'); ?></div>
                    <p style="margin: 10px 0; font-size: 13px; color: #646970;"><?php esc_html_e('Configure Kodanote integration', 'kodanote-content-publisher'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=kodanote-settings')); ?>" class="button button-secondary">
                        <?php esc_html_e('Configure', 'kodanote-content-publisher'); ?>
                    </a>
                </div>
            </div>

            <div class="kodanote-stat-card">
                <div class="kodanote-stat-icon">
                    <span class="dashicons dashicons-admin-site-alt3"></span>
                </div>
                <div class="kodanote-stat-content">
                    <div class="kodanote-stat-label"><?php esc_html_e('Kodanote Dashboard', 'kodanote-content-publisher'); ?></div>
                    <p style="margin: 10px 0; font-size: 13px; color: #646970;"><?php esc_html_e('Manage your content', 'kodanote-content-publisher'); ?></p>
                    <a href="<?php echo esc_url(KODANOTE_DASHBOARD_URL); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                        <?php esc_html_e('Open Kodanote', 'kodanote-content-publisher'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Article Detail Modal -->
    <div id="kodanote-article-modal" class="kodanote-modal" style="display: none;">
        <div class="kodanote-modal-content">
            <div class="kodanote-modal-header">
                <h2 id="modal-title"><?php esc_html_e('Article Details', 'kodanote-content-publisher'); ?></h2>
                <button class="kodanote-modal-close">&times;</button>
            </div>
            <div class="kodanote-modal-body" id="modal-content">
                <!-- Article content will be loaded here -->
            </div>
            <div class="kodanote-modal-footer">
                <button id="modal-publish-btn" class="button button-primary"><?php esc_html_e('Publish Article', 'kodanote-content-publisher'); ?></button>
                <button class="button kodanote-modal-close"><?php esc_html_e('Close', 'kodanote-content-publisher'); ?></button>
            </div>
        </div>
    </div>
</div>
