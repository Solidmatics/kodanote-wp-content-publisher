/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Admin JavaScript
 */

(function($) {
    'use strict';

    // Kodanote Admin object
    window.Kodanote_Admin = {

        /**
         * Debug logging system
         */
        debug: {
            enabled: typeof kodanote_ajax !== 'undefined' && String(kodanote_ajax.debug_mode) === '1',

            init: function() {
                this.info('Debug logging enabled');
            },

            log: function(message, data = null, level = 'info') {
                if (!this.enabled) return;

                const timestamp = new Date().toISOString();
                const prefix = '[Kodanote ' + level.toUpperCase() + '] ' + timestamp;

                // Log event names only: configuration and API responses may contain credentials.
                console.log(prefix, message);
            },

            info: function(message, data = null) {
                this.log(message, data, 'info');
            },

            warn: function(message, data = null) {
                this.log(message, data, 'warn');
            },

            error: function(message, data = null) {
                this.log(message, data, 'error');
            },

            trace: function(message, data = null) {
                if (!this.enabled) return;
                console.trace('[Kodanote TRACE] ' + message);
            }
        },

        /**
         * Initialize the admin interface
         */
        init: function() {
            // Initialize debug system
            this.debug.init();

            this.debug.info('🚀 Kodanote Admin initializing...');
            this.debug.info('Debug mode status:', this.debug.enabled ? 'ENABLED' : 'DISABLED');

            this.bindEvents();
            this.initTooltips();
            this.initModals();
            this.initDashboard();
            this.initSettings();
            this.initArticles();

            this.debug.info('✅ Kodanote Admin initialization complete');
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Test API connection
            $(document).on('click', '.kodanote-test-connection', this.testConnection);

            // Sync articles
            $(document).on('click', '.kodanote-sync-articles', this.syncArticles);

            // Publish article
            $(document).on('click', '.kodanote-publish-article', this.publishArticle);

            // Bulk actions
            $(document).on('click', '.kodanote-bulk-publish', this.bulkPublish);

            // Save settings
            $(document).on('submit', '.kodanote-settings-form', this.saveSettings);

            // Delete article
            $(document).on('click', '.kodanote-delete-article', this.deleteArticle);

            // View article details
            $(document).on('click', '.kodanote-view-article', this.viewArticle);

            // Copy to clipboard
            $(document).on('click', '.kodanote-copy-to-clipboard', this.copyToClipboard);

            // Regenerate API key
            $(document).on('click', '.kodanote-regenerate-api-key', this.regenerateApiKey);

            // Toggle debug mode
            $(document).on('change', '.kodanote-toggle-debug', this.toggleDebugMode);

            // Clear sync data
            $(document).on('click', '.kodanote-clear-sync-data', this.clearSyncData);

            // Export settings
            $(document).on('click', '.kodanote-export-settings', this.exportSettings);
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('.kodanote-tooltip').each(function() {
                const $element = $(this);
                const tooltipText = $element.data('tooltip');

                if (tooltipText) {
                    $element.tooltipster({
                        content: tooltipText,
                        theme: 'tooltipster-light',
                        position: 'top',
                        delay: 100
                    });
                }
            });
        },

        /**
         * Initialize modals
         */
        initModals: function() {
            // Article detail modal
            if ($('#kodanote-article-modal').length === 0) {
                var s = kodanote_ajax.strings;
                $('body').append(`
                    <div id="kodanote-article-modal" class="kodanote-modal">
                        <div class="kodanote-modal-content">
                            <div class="kodanote-modal-header">
                                <h2 id="kodanote-modal-title">${s.article_details}</h2>
                                <button class="kodanote-modal-close">&times;</button>
                            </div>
                            <div class="kodanote-modal-body" id="kodanote-modal-content">
                                <!-- Article content will be loaded here -->
                            </div>
                            <div class="kodanote-modal-footer">
                                <button class="kodanote-button-secondary kodanote-modal-close">${s.close}</button>
                            </div>
                        </div>
                    </div>
                `);
            }

            // Close modal events
            $(document).on('click', '.kodanote-modal-close', this.closeModal);
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) { // Escape key
                    Kodanote_Admin.closeModal();
                }
            });
        },

        /**
         * Test API connection
         */
        testConnection: function(e) {
            e.preventDefault();

            const $button = $(this);
            const originalText = $button.text();

            $button.prop('disabled', true).html('<span class="kodanote-loading"></span> ' + kodanote_ajax.strings.testing);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kodanote_test_connection',
                    nonce: kodanote_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.connection_success + ' ' + response.data.message, 'success');
                    } else {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.connection_failed + ': ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    Kodanote_Admin.showNotice(kodanote_ajax.strings.connection_failed, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Sync articles
         */
        syncArticles: function(e) {
            e.preventDefault();

            const $button = $(this);
            const originalText = $button.text();

            $button.prop('disabled', true).html('<span class="kodanote-loading"></span> Syncing...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kodanote_sync_articles',
                    nonce: kodanote_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const syncedCount = response.data.synced_count || 0;
                        Kodanote_Admin.debug.info('✅ Sync successful, synced count:', syncedCount);
                        
                        Kodanote_Admin.showNotice(
                            kodanote_ajax.strings.sync_success + ' ' + kodanote_ajax.strings.articles_synced.replace('%d', syncedCount),
                            'success'
                        );
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        Kodanote_Admin.debug.error('❌ Sync failed:', response.data);
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.sync_failed + ': ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    Kodanote_Admin.showNotice(kodanote_ajax.strings.sync_failed, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Publish article
         */
        publishArticle: function(e) {
            e.preventDefault();

            const $button = $(this);
            const articleId = $button.data('article-id');
            const originalText = $button.text();

            if (!confirm(kodanote_ajax.strings.confirm_publish)) {
                return;
            }

            $button.prop('disabled', true).html('<span class="kodanote-loading"></span> ' + kodanote_ajax.strings.publishing);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kodanote_publish_article',
                    article_id: articleId,
                    nonce: kodanote_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.publish_success, 'success');
                        $button.closest('tr').fadeOut();
                    } else {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.publish_failed + ': ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    Kodanote_Admin.showNotice(kodanote_ajax.strings.publish_failed, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Bulk publish articles
         */
        bulkPublish: function(e) {
            e.preventDefault();

            const selectedArticles = $('.kodanote-article-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (selectedArticles.length === 0) {
                Kodanote_Admin.showNotice(kodanote_ajax.strings.select_articles, 'warning');
                return;
            }

            if (!confirm(kodanote_ajax.strings.confirm_bulk.replace('%d', selectedArticles.length))) {
                return;
            }

            Kodanote_Admin.showNotice(kodanote_ajax.strings.not_available, 'info');
        },

        /**
         * Delete article
         */
        deleteArticle: function(e) {
            e.preventDefault();

            const $button = $(this);
            const articleId = $button.data('article-id');
            const articleTitle = $button.data('article-title');

            if (!confirm(kodanote_ajax.strings.confirm_delete.replace('%s', articleTitle))) {
                return;
            }

            Kodanote_Admin.showNotice(kodanote_ajax.strings.not_available, 'info');
        },

        /**
         * View article details
         */
        viewArticle: function(e) {
            e.preventDefault();

            const articleId = $(this).data('article-id');

            Kodanote_Admin.showNotice(kodanote_ajax.strings.not_available, 'info');
        },

        /**
         * Copy to clipboard
         */
        copyToClipboard: function(e) {
            e.preventDefault();

            const $element = $(this);
            const textToCopy = $element.data('clipboard-text') || $element.text();

            navigator.clipboard.writeText(textToCopy).then(function() {
                const originalText = $element.text();
                $element.text(kodanote_ajax.strings.copied);
                setTimeout(function() {
                    $element.text(originalText);
                }, 2000);
            }).catch(function(err) {
                var textArea = document.createElement('textarea');
                textArea.value = textToCopy;
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();

                try {
                    document.execCommand('copy');
                    $element.text(kodanote_ajax.strings.copied);
                    setTimeout(function() {
                        $element.text(kodanote_ajax.strings.copied);
                    }, 2000);
                } catch (err) {
                    Kodanote_Admin.showNotice(kodanote_ajax.strings.copy_failed, 'error');
                }

                document.body.removeChild(textArea);
            });
        },

        /**
         * Regenerate API key
         */
        regenerateApiKey: function(e) {
            e.preventDefault();

            if (!confirm(kodanote_ajax.strings.confirm_regen_key)) {
                return;
            }

            Kodanote_Admin.showNotice(kodanote_ajax.strings.not_available, 'info');
        },

        /**
         * Toggle debug mode
         */
        toggleDebugMode: function(e) {
            const enabled = $(this).is(':checked');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kodanote_toggle_debug',
                    enabled: enabled ? 1 : 0,
                    nonce: kodanote_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Kodanote_Admin.showNotice(enabled ? kodanote_ajax.strings.debug_enabled : kodanote_ajax.strings.debug_disabled, 'success');
                    } else {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.debug_update_failed, 'error');
                    }
                },
                error: function() {
                    Kodanote_Admin.showNotice(kodanote_ajax.strings.debug_update_failed, 'error');
                }
            });
        },

        /**
         * Clear sync data
         */
        clearSyncData: function(e) {
            e.preventDefault();

            if (!confirm(kodanote_ajax.strings.confirm_clear_sync)) {
                return;
            }

            Kodanote_Admin.showNotice(kodanote_ajax.strings.not_available, 'info');
        },

        /**
         * Export settings
         */
        exportSettings: function(e) {
            e.preventDefault();

            Kodanote_Admin.showNotice(kodanote_ajax.strings.not_available, 'info');
        },

        /**
         * Save settings
         */
        saveSettings: function(e) {
            e.preventDefault();

            const $form = $(this);
            const formData = $form.serialize();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData + '&action=kodanote_save_settings&nonce=' + kodanote_ajax.nonce,
                success: function(response) {
                    if (response.success) {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.settings_saved, 'success');
                    } else {
                        Kodanote_Admin.showNotice(kodanote_ajax.strings.settings_failed + ': ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    Kodanote_Admin.showNotice(kodanote_ajax.strings.settings_failed, 'error');
                }
            });
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('.kodanote-modal').removeClass('show');
        },

        /**
         * Show notice
         */
        showNotice: function(message, type = 'info') {
            const noticeClass = 'kodanote-notice kodanote-notice-' + type;

            // Remove existing notices
            $('.kodanote-notice').remove();

            // Create new notice
            const $notice = $('<div class="' + noticeClass + '">' + message + '</div>');
            $('.wrap > h1').after($notice);

            // Auto-hide after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Format date for display
         */
        formatDate: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        /**
         * Format number with commas
         */
        formatNumber: function(number) {
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        /**
         * Initialize dashboard page functionality
         */
        initDashboard: function() {
            if (!$('#kodanote-dashboard').length) return;

            // Sync articles buttons
            $('#sync-articles-btn, #quick-sync-btn, #sync-first-articles-btn').on('click', function() {
                const $btn = $(this);
                const originalText = $btn.text();

                $btn.prop('disabled', true).text(kodanote_ajax.strings.syncing);

                $.post(ajaxurl, {
                    action: 'kodanote_sync_articles',
                    nonce: kodanote_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        const syncedCount = response.data.synced_count || 0;
                        alert(kodanote_ajax.strings.sync_success + '\n\n' + kodanote_ajax.strings.articles_synced.replace('%d', syncedCount));
                        location.reload();
                    } else {
                        var errorMsg = response.data.friendly_message || response.data.message;
                        alert(kodanote_ajax.strings.error + '\n\n' + errorMsg);
                        location.reload();
                    }
                }).fail(function() {
                    alert(kodanote_ajax.strings.error + ': ' + kodanote_ajax.strings.sync_failed);
                }).always(function() {
                    $btn.prop('disabled', false).text(originalText);
                });
            });

            // Publish article buttons
            $(document).on('click', '.kodanote-publish-btn', function() {
                const articleId = $(this).data('article-id');
                const $btn = $(this);
                const originalText = $btn.text();

                if (!confirm(kodanote_ajax.strings.confirm_publish)) {
                    return;
                }

                $btn.prop('disabled', true).text(kodanote_ajax.strings.publishing);

                $.post(ajaxurl, {
                    action: 'kodanote_publish_article',
                    article_id: articleId,
                    nonce: kodanote_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        alert(kodanote_ajax.strings.publish_success);
                        location.reload();
                    } else {
                        alert(kodanote_ajax.strings.error + '\n\n' + response.data.message);
                    }
                }).fail(function() {
                    alert(kodanote_ajax.strings.error + ': ' + kodanote_ajax.strings.publish_failed);
                }).always(function() {
                    $btn.prop('disabled', false).text(originalText);
                });
            });

            // View article modal
            $(document).on('click', '.kodanote-view-btn', function() {
                const articleId = $(this).data('article-id');
                alert(kodanote_ajax.strings.coming_soon);
            });

            // Modal close
            $(document).on('click', '.kodanote-modal-close', function() {
                $('#kodanote-article-modal').hide();
            });

            // Close modal on escape
            $(document).on('keyup', function(e) {
                if (e.keyCode === 27) {
                    $('#kodanote-article-modal').hide();
                }
            });

        },

        /**
         * Initialize settings page functionality
         */
        initSettings: function() {
            if (!$('#kodanote-settings').length) return;

            // Test API connection
            $('#test-api-connection').on('click', function() {
                const $btn = $(this);
                const $status = $('#connection-status');
                const originalText = $btn.text();

                Kodanote_Admin.debug.info('🔍 Settings Page: Test Connection button clicked');

                $btn.prop('disabled', true).text(kodanote_ajax.strings.testing);
                $status.html('<span style="color: #666;">' + kodanote_ajax.strings.testing_connection + '</span>');
                Kodanote_Admin.debug.info('✅ Settings test button disabled and status updated');

                const ajaxData = {
                    action: 'kodanote_test_connection',
                    nonce: kodanote_ajax.nonce
                };

                Kodanote_Admin.debug.info('📡 Settings: Sending test connection AJAX request');

                $.post(ajaxurl, ajaxData, function(response) {
                    Kodanote_Admin.debug.info('📥 Settings: Test connection response received');
                    Kodanote_Admin.debug.info('Settings response success:', response.success);

                    if (response.success) {
                        Kodanote_Admin.debug.info('✅ Settings: Connection test successful:', response.data.message);
                        $status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    } else {
                        Kodanote_Admin.debug.error('❌ Settings: Connection test failed:', response.data.message);
                        $status.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
                    }
                }).fail(function(xhr, status, error) {
                    Kodanote_Admin.debug.error('❌ Settings: AJAX request failed:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                    $status.html('<span style="color: #dc3232;">✗ ' + kodanote_ajax.strings.connection_failed + '</span>');
                }).always(function() {
                    Kodanote_Admin.debug.info('🔄 Settings: Restoring test button state');
                    $btn.prop('disabled', false).text(originalText);
                    Kodanote_Admin.debug.info('✅ Settings: Test connection complete');
                });
            });

            // Reset settings
            $('#reset-settings').on('click', function() {
                if (!confirm(kodanote_ajax.strings.confirm_reset)) {
                    return;
                }
                alert(kodanote_ajax.strings.coming_soon);
            });

            // Enable debugging
            $('#enable-debugging').on('change', function() {
                const enabled = $(this).is(':checked');

                $.post(ajaxurl, {
                    action: 'kodanote_toggle_debug',
                    enabled: enabled ? 1 : 0,
                    nonce: kodanote_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(kodanote_ajax.strings.debug_update_failed);
                    }
                }).fail(function() {
                    alert(kodanote_ajax.strings.debug_update_failed);
                });
            });
        },

        /**
         * Initialize articles page functionality
         */
        initArticles: function() {
            if (!$('#kodanote-articles').length) return;

            // Select all checkbox
            $('#select-all-articles').on('change', function() {
                $('.article-checkbox').prop('checked', $(this).is(':checked'));
                updateBulkActions();
            });

            // Individual checkboxes
            $(document).on('change', '.article-checkbox', function() {
                updateBulkActions();
            });

            // Update bulk actions state
            function updateBulkActions() {
                const checkedCount = $('.article-checkbox:checked').length;
                $('#bulk-publish-btn, #bulk-action-submit').prop('disabled', checkedCount === 0);

                if (checkedCount > 0) {
                    $('#bulk-publish-btn').text(kodanote_ajax.strings.bulk_publish_count.replace('%d', checkedCount));
                } else {
                    $('#bulk-publish-btn').text(kodanote_ajax.strings.bulk_publish);
                }
            }

            // Sync articles
            $('#sync-articles-btn, #sync-first-articles-link').on('click', function(e) {
                e.preventDefault();

                const $btn = $(this);
                const originalText = $btn.text();

                $btn.prop('disabled', true).text(kodanote_ajax.strings.syncing);

                $.post(ajaxurl, {
                    action: 'kodanote_sync_articles',
                    nonce: kodanote_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        const syncedCount = response.data.synced_count || 0;
                        alert(kodanote_ajax.strings.sync_success + '\n\n' + kodanote_ajax.strings.articles_synced.replace('%d', syncedCount));
                        location.reload();
                    } else {
                        alert(kodanote_ajax.strings.error + '\n\n' + response.data.message);
                    }
                }).fail(function() {
                    alert(kodanote_ajax.strings.error + ': ' + kodanote_ajax.strings.sync_failed);
                }).always(function() {
                    $btn.prop('disabled', false).text(originalText);
                });
            });

            // Bulk publish
            $('#bulk-publish-btn').on('click', function() {
                const selectedArticles = $('.article-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();

                if (selectedArticles.length === 0) {
                    alert(kodanote_ajax.strings.select_articles);
                    return;
                }

                if (!confirm(kodanote_ajax.strings.confirm_bulk.replace('%d', selectedArticles.length))) {
                    return;
                }

                alert(kodanote_ajax.strings.coming_soon);
            });

            // Delete article
            $(document).on('click', '.delete-article-btn', function(e) {
                e.preventDefault();

                const articleId = $(this).data('article-id');
                const articleTitle = $(this).data('article-title');

                if (!confirm(kodanote_ajax.strings.confirm_delete.replace('%s', articleTitle))) {
                    return;
                }

                alert(kodanote_ajax.strings.coming_soon);
            });

            // View article
            $(document).on('click', '.view-article-btn', function(e) {
                e.preventDefault();

                const articleId = $(this).data('article-id');
                alert(kodanote_ajax.strings.coming_soon);
            });
        },

        /**
         * Debounce function
         */
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        Kodanote_Admin.init();
        Kodanote_Admin.initNotifications();
    });

})(jQuery);

/**
 * Notification Bar Handler
 * Handles the notification bar sync button and dismissal
 */
(function($) {
    'use strict';

    window.Kodanote_Admin = window.Kodanote_Admin || {};

    /**
     * Initialize notification handlers
     */
    Kodanote_Admin.initNotifications = function() {
        // Handle sync now button in notification
        $(document).on('click', '.kodanote-sync-now-btn', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const $notice = $btn.closest('.kodanote-sync-notice');
            const originalHtml = $btn.html();
            const syncingText = $btn.data('syncing-text') || 'Syncing...';
            
            // Disable button and show loading state
            $btn.prop('disabled', true)
                .addClass('syncing')
                .html('<span class="dashicons dashicons-update"></span> ' + syncingText);
            
            Kodanote_Admin.debug.info('🔄 Notification: Triggering manual sync...');
            
            // Trigger manual sync
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kodanote_manual_sync',
                    nonce: kodanote_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const syncedCount = response.data.synced_count || 0;
                        Kodanote_Admin.debug.info('✅ Notification: Sync successful, synced count:', syncedCount);
                        
                        // Hide the notification
                        $notice.fadeOut(300, function() {
                            $(this).remove();
                        });
                        
                        // Show success message
                        Kodanote_Admin.showSyncSuccessNotice(syncedCount);
                        
                        // Reload page after 2 seconds to show updated content
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                        
                    } else {
                        Kodanote_Admin.debug.error('❌ Notification: Sync failed:', response.data);
                        
                        // Re-enable button
                        $btn.prop('disabled', false)
                            .removeClass('syncing')
                            .html(originalHtml);
                        
                        // Show error message
                        Kodanote_Admin.showSyncErrorNotice(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    Kodanote_Admin.debug.error('❌ Notification: AJAX request failed:', {
                        status: xhr.status,
                        error: error
                    });
                    
                    $btn.prop('disabled', false)
                        .removeClass('syncing')
                        .html(originalHtml);
                    
                    Kodanote_Admin.showSyncErrorNotice(kodanote_ajax.strings.sync_failed);
                }
            });
        });
        
        // Handle notification dismissal
        $(document).on('click', '.kodanote-sync-notice .notice-dismiss, .kodanote-cron-notice .notice-dismiss', function() {
            const $notice = $(this).closest('.notice');
            const noticeType = $notice.data('notice-type');
            
            Kodanote_Admin.debug.info('🔕 Dismissing notification:', noticeType);
            
            // Send AJAX request to save dismissal
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kodanote_dismiss_notification',
                    notice_type: noticeType,
                    nonce: kodanote_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Kodanote_Admin.debug.info('✅ Notification dismissed successfully');
                    }
                },
                error: function() {
                    Kodanote_Admin.debug.warn('⚠️ Failed to save notification dismissal');
                }
            });
        });
    };
    
    /**
     * Show sync success notice
     */
    Kodanote_Admin.showSyncSuccessNotice = function(count) {
        var s = kodanote_ajax.strings;
        const message = count > 0 
            ? s.sync_count_msg.replace('%d', count)
            : s.sync_complete_msg;
        
        const $successNotice = $('<div class="notice notice-success is-dismissible"><p><strong>' + s.sync_complete + '</strong><br>' + message + '</p></div>');
        
        // Remove any existing sync notices
        $('.kodanote-sync-notice, .notice-success').remove();
        
        // Add new success notice
        $('.wrap h1, .wrap h2').first().after($successNotice);
        
        // Make it dismissible
        if (typeof wp !== 'undefined' && wp.notices) {
            wp.notices.init();
        }
    };
    
    /**
     * Show sync error notice
     */
    Kodanote_Admin.showSyncErrorNotice = function(errorMessage) {
        const $errorNotice = $('<div class="notice notice-error is-dismissible"><p><strong>' + kodanote_ajax.strings.sync_failed_title + '</strong><br>' + errorMessage + '</p></div>');
        
        // Remove any existing error notices
        $('.notice-error').remove();
        
        // Add new error notice
        $('.wrap h1, .wrap h2').first().after($errorNotice);
        
        // Make it dismissible
        if (typeof wp !== 'undefined' && wp.notices) {
            wp.notices.init();
        }
    };

})(jQuery);
