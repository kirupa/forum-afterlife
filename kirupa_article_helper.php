<?php

declare(strict_types=1);

function kirupa_is_technical_text(string $text): bool
{
    return (bool)preg_match('/\b(js|javascript|typescript|python|php|css|html|sql|api|dom|nodelist|htmlcollection|queryselectorall|code|coding|programming|debug|bug|error|exception|algorithm|function|class|framework|performance|frontend|backend)\b/i', $text);
}

function kirupa_article_keywords(string $text): array
{
    $q = strtolower($text);
    $q = preg_replace('/[^a-z0-9\s]/', ' ', $q) ?? $q;
    $parts = preg_split('/\s+/', $q) ?: [];
    $stop = [
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'for', 'in', 'on', 'at', 'is', 'it', 'this', 'that',
        'how', 'what', 'why', 'when', 'where', 'with', 'from', 'about', 'can', 'should', 'would', 'could',
        'have', 'has', 'had', 'you', 'your', 'they', 'them', 'their', 'our', 'we', 'are', 'was', 'were',
        'just', 'into', 'than', 'then', 'also', 'very', 'more', 'most', 'some', 'like',
    ];
    $keywords = [];
    $shortKeep = ['js', 'ts', 'ui', 'ux', 'ai', 'ml', 'api', 'dom', 'css', 'sql'];
    foreach ($parts as $p) {
        if ((strlen($p) < 3 && !in_array($p, $shortKeep, true)) || in_array($p, $stop, true)) {
            continue;
        }
        $keywords[] = $p;
    }
    $keywords = array_values(array_unique($keywords));

    $expand = [];
    if (in_array('js', $keywords, true)) {
        $expand[] = 'javascript';
    }
    if (in_array('ts', $keywords, true)) {
        $expand[] = 'typescript';
    }
    if (in_array('dom', $keywords, true)) {
        $expand[] = 'document';
    }
    if (in_array('nodelist', $keywords, true) || in_array('htmlcollection', $keywords, true)) {
        $expand[] = 'dom';
        $expand[] = 'queryselectorall';
    }
    if (in_array('queryselectorall', $keywords, true)) {
        $expand[] = 'queryselector';
        $expand[] = 'dom';
    }

    if ($expand !== []) {
        $keywords = array_values(array_unique(array_merge($keywords, $expand)));
    }
    return $keywords;
}

function kirupa_normalize_url_key(string $url): string
{
    $u = strtolower(trim($url));
    $u = rtrim($u, "/ \t\n\r\0\x0B");
    return $u;
}

function kirupa_fetch_llms_links(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $ch = curl_init('https://www.kirupa.com/llms.txt');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        $localPath = __DIR__ . '/llms.txt';
        if (is_file($localPath) && is_readable($localPath)) {
            $fallback = @file_get_contents($localPath);
            if (is_string($fallback) && trim($fallback) !== '') {
                $raw = $fallback;
                $err = '';
            }
        }
    }
    if (!is_string($raw) || trim($raw) === '' || $err !== '') {
        $cache = [];
        return $cache;
    }

    $items = [];
    $seen = [];
    if (preg_match_all('/\[(.*?)\]\((https?:\/\/www\.kirupa\.com\/[^)\s]+)\)/i', (string)$raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $title = trim((string)$m[1]);
            $url = trim((string)$m[2]);
            if ($title !== '' && $url !== '') {
                $k = kirupa_normalize_url_key($url);
                if ($k !== '' && !isset($seen[$k])) {
                    $items[] = ['title' => $title, 'url' => $url];
                    $seen[$k] = true;
                }
            }
        }
    }

    // Newer llms.txt format may contain plain URL lines without markdown titles.
    $lines = preg_split('/\R/u', (string)$raw) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (!preg_match('/^https?:\/\/www\.kirupa\.com\/\S+$/i', $line)) continue;
        $url = $line;
        $k = kirupa_normalize_url_key($url);
        if ($k === '' || isset($seen[$k])) continue;
        $items[] = ['title' => kirupa_guess_title_from_url($url), 'url' => $url];
        $seen[$k] = true;
    }

    $cache = $items;
    return $cache;
}

function kirupa_guess_title_from_url(string $url): string
{
    $u = trim($url);
    if ($u === '') return 'Kirupa article';
    $path = (string)(parse_url($u, PHP_URL_PATH) ?? '');
    $path = trim($path, '/');
    if ($path === '') return 'Kirupa article';

    $segments = array_values(array_filter(explode('/', $path), static function ($s) {
        return trim((string)$s) !== '';
    }));
    $last = $segments === [] ? '' : (string)$segments[count($segments) - 1];
    if ($last === '' || strtolower($last) === 'index.htm' || strtolower($last) === 'index.html') {
        $last = $segments !== [] ? (string)$segments[max(0, count($segments) - 2)] : '';
    }
    if ($last === '') return 'Kirupa article';

    $last = preg_replace('/\.(md|txt|htm|html)$/i', '', $last) ?? $last;
    $last = str_replace(['_', '-'], ' ', $last);
    $last = preg_replace('/\s+/', ' ', (string)$last) ?? $last;
    $last = trim((string)$last);
    if ($last === '') return 'Kirupa article';

    $last = preg_replace('/\bjs\b/i', 'JavaScript', (string)$last) ?? $last;
    $last = preg_replace('/\bcss\b/i', 'CSS', (string)$last) ?? $last;
    $last = preg_replace('/\bdom\b/i', 'DOM', (string)$last) ?? $last;
    $last = preg_replace('/\bapi\b/i', 'API', (string)$last) ?? $last;
    $last = ucwords((string)$last);

    if (strlen($last) > 96) {
        $short = trim((string)substr($last, 0, 96));
        $cut = strrpos($short, ' ');
        if ($cut !== false && $cut > 30) {
            $short = trim((string)substr($short, 0, (int)$cut));
        }
        $last = $short;
    }
    return $last !== '' ? $last : 'Kirupa article';
}

function kirupa_find_relevant_article(string $text, int $minScore = 2): ?array
{
    return kirupa_find_relevant_article_scored_excluding($text, [], $minScore);
}

function kirupa_extract_urls_from_text(string $text): array
{
    if (!preg_match_all('/https?:\/\/[^\s<>()"\']+/i', $text, $matches)) {
        return [];
    }
    $urls = [];
    foreach ($matches[0] as $url) {
        $urls[] = rtrim((string)$url, ".,);]");
    }
    return array_values(array_unique($urls));
}

function kirupa_find_relevant_article_excluding(string $text, array $excludeUrls, int $minScore = 2): ?array
{
    return kirupa_find_relevant_article_scored_excluding($text, $excludeUrls, $minScore);
}

function kirupa_find_relevant_article_scored_excluding(string $text, array $excludeUrls, int $minScore = 2): ?array
{
    $links = kirupa_fetch_llms_links();
    if ($links === []) {
        return null;
    }

    $excludeKeys = [];
    foreach ($excludeUrls as $u) {
        $k = kirupa_normalize_url_key((string)$u);
        if ($k !== '') {
            $excludeKeys[$k] = true;
        }
    }

    $keywords = kirupa_article_keywords($text);
    if ($keywords === []) {
        return null;
    }

    $best = null;
    $bestScore = 0;
    $bestMatched = [];
    $bestTitleHits = 0;
    foreach ($links as $link) {
        $url = trim((string)($link['url'] ?? ''));
        $title = trim((string)($link['title'] ?? ''));
        if ($url === '' || $title === '') {
            continue;
        }
        $urlKey = kirupa_normalize_url_key($url);
        if ($urlKey !== '' && isset($excludeKeys[$urlKey])) {
            continue;
        }

        $haystack = strtolower($title . ' ' . $url);
        $titleHaystack = strtolower($title);
        $score = 0;
        $matched = [];
        $titleHits = 0;
        foreach ($keywords as $kw) {
            if (strpos($haystack, $kw) !== false) {
                $matched[] = $kw;
                $kwLen = strlen($kw);
                $score += ($kwLen >= 8) ? 2 : 1;
                if (strpos($titleHaystack, $kw) !== false) {
                    $titleHits++;
                    $score += 1;
                }
            }
        }
        if ($score > $bestScore || ($score === $bestScore && $titleHits > $bestTitleHits)) {
            $bestScore = $score;
            $best = $link;
            $bestMatched = $matched;
            $bestTitleHits = $titleHits;
        }
    }

    $minScore = max(1, $minScore);
    if (!is_array($best) || $bestScore < $minScore) {
        return null;
    }
    $best['score'] = (int)$bestScore;
    $best['title_hits'] = (int)$bestTitleHits;
    $best['matched_keywords'] = array_slice(array_values(array_unique($bestMatched)), 0, 24);
    $best['keyword_count'] = count($keywords);
    return $best;
}

function kirupa_fallback_technical_article(string $text, array $excludeUrls = []): ?array
{
    $blob = strtolower(trim($text));
    if ($blob === '') {
        return null;
    }

    $candidates = [];
    if (preg_match('/\b(queryselector|queryselectorall|getelementsby|nodelist|htmlcollection|dom)\b/i', $blob)) {
        $candidates[] = [
            'title' => 'Finding Elements In The DOM Using querySelector',
            'url' => 'https://www.kirupa.com/html5/finding_elements_dom_using_querySelector.htm',
        ];
    }
    if (preg_match('/\b(requestanimationframe|animation|animated|motion|canvas)\b/i', $blob)) {
        $candidates[] = [
            'title' => 'Learn Animation',
            'url' => 'https://www.kirupa.com/html5/learn_animation.htm',
        ];
    }
    if (preg_match('/\b(css|style|selector|layout|responsive)\b/i', $blob)) {
        $candidates[] = [
            'title' => 'Learn HTML and CSS',
            'url' => 'https://www.kirupa.com/html5/learn_html_css.htm',
        ];
    }
    if (preg_match('/\b(react|component|state|props|hooks)\b/i', $blob)) {
        $candidates[] = [
            'title' => 'Learn React',
            'url' => 'https://www.kirupa.com/react/index.htm',
        ];
    }
    if (preg_match('/\b(js|javascript|function|array|object|promise|async|await|event loop)\b/i', $blob)) {
        $candidates[] = [
            'title' => 'Learn JavaScript',
            'url' => 'https://www.kirupa.com/javascript/learn_javascript.htm',
        ];
    }
    if ($candidates === []) {
        $candidates[] = [
            'title' => 'Kirupa JavaScript Tutorials',
            'url' => 'https://www.kirupa.com/javascript/',
        ];
    }

    $excludeKeys = [];
    foreach ($excludeUrls as $u) {
        $k = kirupa_normalize_url_key((string)$u);
        if ($k !== '') {
            $excludeKeys[$k] = true;
        }
    }

    foreach ($candidates as $candidate) {
        $key = kirupa_normalize_url_key((string)($candidate['url'] ?? ''));
        if ($key !== '' && !isset($excludeKeys[$key])) {
            return $candidate;
        }
    }
    return null;
}
