/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote WordPress Plugin - Frontend JavaScript
 * Version: 1.3.113
 */

(function($) {
    'use strict';

    function normalizeCopyText(html) {
        var text = $('<div>').html(html).text() || '';
        return text.replace(/\s+/g, ' ').trim();
    }

    function isDuplicateCopy(htmlA, htmlB) {
        var a = normalizeCopyText(htmlA);
        var b = normalizeCopyText(htmlB);
        if (a.length < 80 || b.length < 80) {
            return false;
        }
        if (a === b) {
            return true;
        }
        if (a.length < 160 || b.length < 160) {
            return false;
        }
        var prefixA = a.substring(0, 160).toLowerCase();
        var prefixB = b.substring(0, 160).toLowerCase();
        if (b.toLowerCase().indexOf(prefixA) !== 0 && a.toLowerCase().indexOf(prefixB) !== 0) {
            return false;
        }
        return Math.min(a.length, b.length) / Math.max(a.length, b.length) >= 0.85;
    }

    /**
     * DesignThemes LMS (and similar themes) can print the full article in
     * .first-post-div and again in each .all-post-div page. The server-side
     * output buffer removes those copies; this is a fallback when that
     * buffer does not run (cached HTML from before the plugin update).
     */
    function collapseRepeatedThemeCopies() {
        var $first = $('.kodanote .first-post-div').first();
        var $copies = $('.kodanote .all-post-div');
        if (!$copies.length) {
            return;
        }

        if ($first.length && $copies.length) {
            var firstHtml = $first.html() || '';
            $copies.each(function() {
                if (isDuplicateCopy(firstHtml, $(this).html() || '')) {
                    $(this).remove();
                }
            });
            return;
        }

        var $remaining = $('.kodanote .all-post-div');
        if ($remaining.length < 2) {
            return;
        }
        var keepHtml = $remaining.first().html() || '';
        $remaining.slice(1).each(function() {
            if (isDuplicateCopy(keepHtml, $(this).html() || '')) {
                $(this).remove();
            }
        });
    }

    $(document).ready(function() {
        collapseRepeatedThemeCopies();

        // Lazy load infographic images if needed
        $('.kodanote-infographic-container img').each(function() {
            const $img = $(this);
            if ($img.attr('data-src')) {
                $img.attr('src', $img.attr('data-src'));
                $img.removeAttr('data-src');
            }
        });

        // Add smooth scroll to anchor links within Kodanote articles
        // Uses getElementById instead of jQuery selector to handle Unicode/URL-encoded IDs
        $('.kodanote-article a[href^="#"]').on('click', function(e) {
            var hash = this.getAttribute('href');
            if (!hash || hash === '#') return;

            var decoded;
            try {
                decoded = decodeURIComponent(hash.substring(1));
            } catch (ex) {
                decoded = hash.substring(1);
            }

            var el = document.getElementById(decoded);
            if (el) {
                e.preventDefault();
                $('html, body').stop().animate({
                    scrollTop: $(el).offset().top - 100
                }, 500);
            }
        });
    });

})(jQuery);

