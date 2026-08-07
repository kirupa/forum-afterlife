<?php

/*
 * Shared link formatting.
 *
 * Posts should link the destination's title, not paste a raw URL:
 *   [Debounce Click Counter](https://forum.kirupa.com/t/.../682928)
 * rather than
 *   https://forum.kirupa.com/t/coding-challenge-2-debounce-click-counter/682928/2
 *
 * Two entry points:
 *   konvo_markdown_link()      - build one link when the title is known (or resolvable)
 *   konvo_linkify_bare_urls()  - deterministic cleanup pass over generated text
 */

declare(strict_types=1);

if (!function_exists('konvo_link_title_cache_path')) {
    function konvo_link_title_cache_path(): string
    {
        $dir = __DIR__ . '/.konvo_state';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/link_titles.json';
    }
}

if (!function_exists('konvo_link_title_cache')) {
    function konvo_link_title_cache(?array $set = null): array
    {
        static $cache = null;
        if ($set !== null) {
            $cache = $set;
            $json = json_encode($cache, JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $path = konvo_link_title_cache_path();
                $tmp = $path . '.tmp.' . getmypid();
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (!@rename($tmp, $path)) @unlink($tmp);
                }
            }
            return $cache;
        }
        if (is_array($cache)) return $cache;
        $raw = @file_get_contents(konvo_link_title_cache_path());
        $d = is_string($raw) ? json_decode($raw, true) : null;
        $cache = is_array($d) ? $d : array();
        return $cache;
    }
}

/**
 * Human-readable fallback derived from the URL itself, used when the page has
 * no usable title. "coding-challenge-2-debounce-click-counter" becomes
 * "Coding Challenge 2 Debounce Click Counter".
 */
if (!function_exists('konvo_title_from_url')) {
    function konvo_title_from_url(string $url): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            $host = (string)parse_url($url, PHP_URL_HOST);
            return $host !== '' ? preg_replace('/^www\./i', '', $host) : '';
        }
        $parts = array_values(array_filter(explode('/', $path)));
        // Trailing numeric ids (Discourse post numbers, topic ids) are not titles.
        while ($parts !== array() && preg_match('/^\d+$/', (string)end($parts))) {
            array_pop($parts);
        }
        if ($parts === array()) return '';
        $slug = (string)end($parts);
        $slug = preg_replace('/\.(htm|html|php|md|txt)$/i', '', $slug) ?? $slug;
        $slug = str_replace(array('_', '-', '+'), ' ', $slug);
        $slug = trim(preg_replace('/\s+/', ' ', $slug) ?? $slug);
        if ($slug === '' || preg_match('/^\d+$/', $slug)) return '';
        return ucwords($slug);
    }
}

/**
 * Fetch the destination's <title>. Cached, short timeout, and never fatal:
 * a link with a slug-derived title is far better than a failed post.
 */
if (!function_exists('konvo_fetch_page_title')) {
    function konvo_fetch_page_title(string $url, int $timeout = 6): string
    {
        $cache = konvo_link_title_cache();
        $key = md5($url);
        if (isset($cache[$key])) return (string)$cache[$key];

        $title = '';
        if (function_exists('curl_init') && preg_match('#^https?://#i', $url)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'konvo-link-helper/1.0',
                CURLOPT_RANGE => '0-40000', // the <title> lives near the top
            ));
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (is_string($body) && $status >= 200 && $status < 400) {
                if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
                    $t = html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
                    // Drop trailing site branding: "Some Article | Site Name"
                    $t = preg_replace('/\s*[|\x{2013}\x{2014}]\s*[^|]{2,40}$/u', '', $t) ?? $t;
                    $title = trim($t);
                }
            }
        }

        if ($title === '') $title = konvo_title_from_url($url);
        $cache[$key] = $title;
        if (count($cache) > 400) $cache = array_slice($cache, -400, 400, true);
        konvo_link_title_cache($cache);
        return $title;
    }
}

if (!function_exists('konvo_clean_link_text')) {
    function konvo_clean_link_text(string $title): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        // Square brackets would break the markdown link.
        $t = str_replace(array('[', ']'), array('(', ')'), $t);

        // Aggregator headlines are long and get cut mid-phrase ("...continue as
        // an"). Prefer a clause boundary, then a word boundary, and never slice
        // through a word.
        $max = 62;
        if (mb_strlen($t) > $max) {
            $head = mb_substr($t, 0, $max);
            $cut = '';
            foreach (array(': ', ' - ', ', ') as $sep) {
                $pos = mb_strrpos($head, $sep);
                if ($pos !== false && $pos >= 24) {
                    $cut = rtrim(mb_substr($head, 0, $pos), " ,-:");
                    break;
                }
            }
            if ($cut === '') {
                $sp = mb_strrpos($head, ' ');
                $cut = ($sp !== false && $sp >= 24) ? mb_substr($head, 0, $sp) : $head;
            }
            $t = rtrim($cut, " ,;:-") . '...';
        }
        return trim($t);
    }
}

/**
 * Build [Title](url). Falls back to the bare URL only when no title can be
 * derived at all, since a link with no text is worse than a visible URL.
 */
if (!function_exists('konvo_markdown_link')) {
    function konvo_markdown_link(string $url, string $title = '', bool $allowFetch = true): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) return $url;

        $title = konvo_clean_link_text($title);
        if ($title === '') $title = konvo_clean_link_text(konvo_title_from_url($url));
        if ($title === '' && $allowFetch) $title = konvo_clean_link_text(konvo_fetch_page_title($url));
        if ($title === '') return $url;

        return '[' . $title . '](' . $url . ')';
    }
}

/**
 * Convert bare URLs in generated text into titled markdown links.
 *
 * Skips fenced and inline code, anything already inside a markdown link or an
 * image, and bare-domain mentions. $titleMap lets a caller supply titles it
 * already knows (feed items, article records) so no fetch is needed.
 */
if (!function_exists('konvo_linkify_bare_urls')) {
    function konvo_linkify_bare_urls(string $text, array $titleMap = array(), bool $allowFetch = false): string
    {
        if (trim($text) === '' || stripos($text, 'http') === false) return $text;

        // Protect fenced code blocks and inline code from rewriting.
        $placeholders = array();
        $protect = static function (string $pattern) use (&$text, &$placeholders): void {
            $text = preg_replace_callback($pattern, static function ($m) use (&$placeholders) {
                $token = "\x01KLP" . count($placeholders) . "\x02";
                $placeholders[$token] = $m[0];
                return $token;
            }, $text) ?? $text;
        };
        $protect('/```[\s\S]*?```/');
        $protect('/`[^`\n]+`/');
        // Already-formed markdown links and images.
        $protect('/!?\[[^\]\n]*\]\([^)\s]+\)/');

        $normalized = array();
        foreach ($titleMap as $u => $t) {
            $normalized[rtrim((string)$u, '/')] = (string)$t;
        }

        $text = preg_replace_callback(
            '#(?<![\(\[<])https?://[^\s<>()\[\]]+#i',
            static function ($m) use ($normalized, $allowFetch) {
                $url = rtrim((string)$m[0], '.,;:!?');
                $trailing = substr((string)$m[0], strlen($url));
                $known = $normalized[rtrim($url, '/')] ?? '';
                $link = konvo_markdown_link($url, $known, $allowFetch);
                return $link . $trailing;
            },
            $text
        ) ?? $text;

        foreach ($placeholders as $token => $original) {
            $text = str_replace($token, $original, $text);
        }
        return $text;
    }
}
