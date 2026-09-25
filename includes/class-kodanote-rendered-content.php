<?php
/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Collapse theme-rendered copies of a Kodanote article.
 *
 * Some themes (DesignThemes LMS and similar "load more" paginators) call
 * the_content() once, then print that same HTML into first-post-div plus
 * several all-post-div pages. The WordPress post body and the Kodanote
 * calendar stay clean; only the public HTML is wrong.
 *
 * This helper is WordPress-free so Laravel unit tests can load it.
 */

if (class_exists('Kodanote_Rendered_Content')) {
    return;
}

class Kodanote_Rendered_Content {

    /**
     * Remove repeated full-article copies from rendered page HTML.
     *
     * @param string $html Full page or article HTML
     * @return string
     */
    public static function collapse_repeated_copies($html) {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        $has_lms_wrappers = (stripos($html, 'first-post-div') !== false || stripos($html, 'all-post-div') !== false);
        if (!$has_lms_wrappers) {
            return $html;
        }

        try {
            $html = self::collapse_lms_load_more_copies($html);
        } catch (Throwable $e) {
            return $html;
        }

        return $html;
    }

    /**
     * DesignThemes LMS prints the full article in .first-post-div and again
     * in each .all-post-div "page". Keep the first copy. If first-post-div is
     * only an excerpt, keep one all-post-div when those siblings match.
     *
     * @param string $html Page HTML
     * @return string
     */
    private static function collapse_lms_load_more_copies($html) {
        $first_blocks = self::find_divs_with_class($html, 'first-post-div');
        $all_blocks = self::find_divs_with_class($html, 'all-post-div');

        if (empty($all_blocks)) {
            return $html;
        }

        $remove = array();

        if (!empty($first_blocks)) {
            $first_inner = $first_blocks[0]['inner'];
            foreach ($all_blocks as $block) {
                if (self::is_duplicate_copy($first_inner, $block['inner'])) {
                    $remove[] = $block;
                }
            }
        }

        if (empty($remove) && count($all_blocks) > 1) {
            $keep_inner = $all_blocks[0]['inner'];
            for ($i = 1, $n = count($all_blocks); $i < $n; $i++) {
                if (self::is_duplicate_copy($keep_inner, $all_blocks[$i]['inner'])) {
                    $remove[] = $all_blocks[$i];
                }
            }
        }

        if (empty($remove)) {
            return $html;
        }

        usort($remove, function ($a, $b) {
            return $b['start'] - $a['start'];
        });

        foreach ($remove as $block) {
            $html = substr($html, 0, $block['start']) . substr($html, $block['end']);
        }

        return $html;
    }

    /**
     * True when two HTML blobs are the same article copy.
     *
     * A short excerpt followed by the remaining body is not a duplicate:
     * the length ratio stays low.
     *
     * @param string $html_a First blob
     * @param string $html_b Second blob
     * @return bool
     */
    public static function is_duplicate_copy($html_a, $html_b) {
        $a = self::normalize_text($html_a);
        $b = self::normalize_text($html_b);

        if (strlen($a) < 80 || strlen($b) < 80) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        $prefix_len = 160;
        if (strlen($a) < $prefix_len || strlen($b) < $prefix_len) {
            return false;
        }

        if (stripos($b, substr($a, 0, $prefix_len)) !== 0
            && stripos($a, substr($b, 0, $prefix_len)) !== 0) {
            return false;
        }

        $ratio = min(strlen($a), strlen($b)) / max(strlen($a), strlen($b));

        return $ratio >= 0.85;
    }

    /**
     * Find each <div class="... $class ...">...</div> with nested-div support.
     *
     * @param string $html  HTML
     * @param string $class Class name
     * @return array<int, array{start:int,end:int,inner:string}>
     */
    public static function find_divs_with_class($html, $class) {
        $results = array();
        if (!is_string($html) || $html === '' || !is_string($class) || $class === '') {
            return $results;
        }

        $class_quoted = preg_quote($class, '/');
        $pattern = '/<div\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\b' . $class_quoted . '\b[^"\']*\1[^>]*>/i';
        $offset = 0;

        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $open_start = $match[0][1];
            $open_tag = $match[0][0];
            $open_end = $open_start + strlen($open_tag);
            $close_end = self::find_matching_div_close($html, $open_end);
            if ($close_end === null) {
                break;
            }

            $close_tag_start = strripos(substr($html, 0, $close_end), '</div');
            if ($close_tag_start === false || $close_tag_start < $open_end) {
                break;
            }

            $results[] = array(
                'start' => $open_start,
                'end' => $close_end,
                'inner' => substr($html, $open_end, $close_tag_start - $open_end),
            );
            $offset = $close_end;
        }

        return $results;
    }

    /**
     * Walk from inside an opened <div> to the matching </div>.
     *
     * @param string $html HTML
     * @param int    $from Offset after the opening tag
     * @return int|null Offset after the closing tag
     */
    private static function find_matching_div_close($html, $from) {
        $length = strlen($html);
        $depth = 1;
        $i = $from;

        while ($i < $length && $depth > 0) {
            $next_open = stripos($html, '<div', $i);
            $next_close = stripos($html, '</div', $i);

            if ($next_close === false) {
                return null;
            }

            if ($next_open !== false && $next_open < $next_close && self::is_div_open_tag($html, $next_open)) {
                $depth++;
                $gt = strpos($html, '>', $next_open);
                $i = ($gt === false) ? $next_open + 4 : $gt + 1;
                continue;
            }

            $depth--;
            $gt = strpos($html, '>', $next_close);
            $i = ($gt === false) ? $next_close + 5 : $gt + 1;
        }

        return $depth === 0 ? $i : null;
    }

    /**
     * True when $offset points at a real <div ...> tag, not "divider".
     *
     * @param string $html   HTML
     * @param int    $offset Offset of "<div"
     * @return bool
     */
    private static function is_div_open_tag($html, $offset) {
        $after = substr($html, $offset + 4, 1);

        return $after === '' || $after === '>' || $after === ' ' || $after === "\n" || $after === "\r" || $after === "\t";
    }

    /**
     * Strip tags and collapse whitespace for copy comparison.
     *
     * @param string $html HTML
     * @return string
     */
    private static function normalize_text($html) {
        if (!is_string($html) || $html === '') {
            return '';
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain);

        return is_string($plain) ? trim($plain) : '';
    }
}
