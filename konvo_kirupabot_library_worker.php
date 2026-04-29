<?php

/*
 * Browser-callable kirupaBot daily library highlight worker.
 *
 * Example:
 * https://www.kirupa.com/konvo_kirupabot_library_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_kirupabot_library_worker.php?key=YOUR_SECRET&dry_run=1
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/kirupa_article_helper.php';
require_once __DIR__ . '/konvo_soul_helper.php';
$konvoForumPromptHelper = __DIR__ . '/konvo_forum_prompt_helper.php';
if (is_file($konvoForumPromptHelper)) {
    require_once $konvoForumPromptHelper;
}
$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'gpt-5.4';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_DESIGN_CATEGORY_ID')) define('KONVO_DESIGN_CATEGORY_ID', 114);

function library_out(int $status, array $data): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function library_safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function library_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/kirupabot_library_seen.json';
}

function library_load_seen(): array
{
    $path = library_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function library_save_seen(array $seen): void
{
    $clean = array();
    $cutoff = time() - (180 * 24 * 60 * 60);
    foreach ($seen as $url => $ts) {
        $u = trim((string)$url);
        $t = (int)$ts;
        if ($u === '' || $t <= 0 || $t < $cutoff) continue;
        $clean[$u] = $t;
    }
    arsort($clean);
    $clean = array_slice($clean, 0, 1200, true);
    @file_put_contents(library_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function library_pick_article(array $links, array $seen): ?array
{
    if ($links === array()) return null;
    shuffle($links);
    foreach ($links as $item) {
        if (!is_array($item)) continue;
        $url = trim((string)($item['url'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        if ($url === '' || $title === '') continue;
        if (!isset($seen[$url])) return $item;
    }
    foreach ($links as $item) {
        if (!is_array($item)) continue;
        $url = trim((string)($item['url'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        if ($url !== '' && $title !== '') return $item;
    }
    return null;
}

function library_clean_topic_name(string $title): string
{
    $title = trim(strip_tags($title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    $title = trim((string)$title, " \t\n\r\0\x0B\"'`“”‘’");
    $title = preg_replace('/[:;,\.\-]+$/', '', (string)$title) ?? $title;
    $title = trim((string)$title);
    if ($title === '') $title = 'A useful web dev deep dive';
    if (strlen($title) > 110) {
        $short = trim((string)substr($title, 0, 110));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 60) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $title = $short;
    }
    return $title;
}

function library_build_title(string $articleTitle): string
{
    $topic = library_clean_topic_name($articleTitle);
    // Discourse title policy allows only one emoji; reserve it for the prefix.
    $topic = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', (string)$topic) ?? $topic;
    $topic = trim((string)$topic);
    $prefix = '✨ Archive Spotlight: ';
    $maxTopicLen = 240 - strlen($prefix);
    if ($maxTopicLen < 20) $maxTopicLen = 20;
    if (strlen($topic) > $maxTopicLen) {
        $short = trim((string)substr($topic, 0, $maxTopicLen));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 30) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $topic = $short;
    }
    return $prefix . $topic;
}

function library_pick_title_emoji(string $articleTitle): string
{
    $t = strtolower(trim((string)$articleTitle));
    if ($t === '') return '📚';

    if (preg_match('/\b(security|auth|authentication|authorization|password|encrypt|privacy|csrf|xss|sql injection)\b/i', $t)) return '🔒';
    if (preg_match('/\b(performance|optimiz|latency|cache|caching|speed|faster|slow|rendering)\b/i', $t)) return '⚡';
    if (preg_match('/\b(algorithm|data structure|binary|tree|graph|heap|sort|search|recursion|complexity|big o)\b/i', $t)) return '🧠';
    if (preg_match('/\b(animation|motion|timeline|transition|tween|easing)\b/i', $t)) return '🎞️';
    if (preg_match('/\b(canvas|svg|grid|draw|drawing|pixel|color|palette|design|ux|ui|typography)\b/i', $t)) return '🎨';
    if (preg_match('/\b(game|gaming|sprite|spritesheet|tilemap|platformer)\b/i', $t)) return '🎮';
    if (preg_match('/\b(react|vue|angular|svelte|component|state|props|hook)\b/i', $t)) return '⚛️';
    if (preg_match('/\b(javascript|js|typescript|ts|html|css|dom|api|json|xml|browser|frontend|web)\b/i', $t)) return '💻';
    return '📚';
}

function library_url_is_reachable_html(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (!function_exists('curl_init')) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'konvo-kirupabot-library-worker/1.0',
    ));
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $status < 200 || $status >= 300) return false;
    return (bool)preg_match('/text\/html/i', $ctype);
}

function library_forum_article_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return $url;
    if (!preg_match('/^https?:\/\/www\.kirupa\.com\/.+$/i', $url)) return $url;

    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    if ($path === '') return $url;
    if (!preg_match('/\.(md|txt)$/i', $path)) return $url;

    $base = preg_replace('/\.(md|txt)$/i', '', $path) ?? $path;
    $withoutAi = str_replace('/ai/', '/', $base);
    $withoutAi = preg_replace('#/+#', '/', (string)$withoutAi) ?? $withoutAi;
    $host = 'https://www.kirupa.com';

    $candidates = array(
        $host . $withoutAi . '.htm',
        $host . $withoutAi . '.html',
        $host . $base . '.htm',
        $host . $base . '.html',
    );

    $seen = array();
    foreach ($candidates as $cand) {
        $cand = trim((string)$cand);
        if ($cand === '' || isset($seen[$cand])) continue;
        $seen[$cand] = true;
        if (library_url_is_reachable_html($cand)) return $cand;
    }
    return $url;
}

function library_guess_category_id(array $article): int
{
    $blob = strtolower(
        trim((string)($article['title'] ?? '')) . "\n"
        . trim((string)($article['url'] ?? ''))
    );
    if ($blob !== '' && preg_match('/\b(ui|ux|design|typography|color|layout|figma|wireframe|prototype|visual)\b/i', $blob)) {
        return (int)KONVO_DESIGN_CATEGORY_ID;
    }
    return (int)KONVO_WEBDEV_CATEGORY_ID;
}

function library_normalize_blurb(string $text): string
{
    $text = trim(strip_tags($text));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/https?:\/\/\S+/i', '', (string)$text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text, " \t\n\r\0\x0B\"'`“”‘’");
    if ($text === '') return '';
    $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: array($text);
    $parts = array_values(array_filter(array_map(static function ($line) {
        return trim((string)$line);
    }, $parts), static function ($line) {
        return $line !== '';
    }));
    if (count($parts) > 2) {
        $parts = array_slice($parts, 0, 2);
    }
    $text = trim(implode(' ', $parts));
    if (!preg_match('/[.!?]$/', $text)) {
        $text .= '.';
    }
    return $text;
}

function library_format_blurb_paragraphs(string $text): string
{
    $text = trim((string)$text);
    if ($text === '') return '';
    if (str_contains($text, "\n\n")) return $text;

    $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: array($text);
    $sentences = array_values(array_filter(array_map(static function ($s) {
        return trim((string)$s);
    }, $sentences), static function ($s) {
        return $s !== '';
    }));
    if ($sentences === array()) return '';
    if (count($sentences) === 1) return $sentences[0];

    // Keep concise and readable: one thought per paragraph with a blank line between.
    $out = implode("\n\n", array_slice($sentences, 0, 2));
    if (!str_contains($out, "\n\n") && preg_match('/^(.+?[.!?])\s+(.+)$/u', $text, $m)) {
        $first = trim((string)$m[1]);
        $second = trim((string)$m[2]);
        if ($first !== '' && $second !== '') {
            $out = $first . "\n\n" . $second;
        }
    }
    return $out;
}

function library_generate_blurb_with_llm(array $article, bool $strict): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.');
    }

    $botName = 'kirupaBot';
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul('kirupabot', 'You are kirupaBot. Concise, practical, and friendly.')
    );
    $articleTitle = trim((string)($article['title'] ?? ''));
    $articleUrl = trim((string)($article['url'] ?? ''));
    $strictLine = $strict
        ? 'Your previous draft was generic or too long. Rewrite to be punchier and more human.'
        : 'Write the best first draft now.';

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'Write a witty, human one- or two-sentence forum blurb highlighting a kirupa.com article. '
        . 'English only. '
        . 'No bullets, no headings, no emojis, no sign-off, and no questions at the end. '
        . 'If you write two sentences, put them on separate paragraphs (blank line between). '
        . 'Keep it concise and useful, like a fast recommendation from a smart forum regular. '
        . 'Return only the blurb text.';

    $user = "Article title: {$articleTitle}\n"
        . "Article URL: {$articleUrl}\n"
        . "Posting bot: {$botName}\n"
        . $strictLine;

    $payload = array(
        'model' => konvo_model_for_task('article_summary'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.8,
    );

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . KONVO_OPENAI_API_KEY,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        return array('ok' => false, 'error' => 'OpenAI network error: ' . $err);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'OpenAI response format error', 'status' => $status);
    }
    $text = library_normalize_blurb((string)$decoded['choices'][0]['message']['content']);
    if ($text === '') {
        return array('ok' => false, 'error' => 'Generated blurb was empty');
    }
    return array('ok' => true, 'text' => $text, 'status' => $status);
}

function library_fallback_blurb(array $article): string
{
    $topic = library_clean_topic_name((string)($article['title'] ?? 'this pick'));
    $line = 'Still one of the cleanest explainers on this topic.';
    if ($topic !== '') {
        $line = $topic . ', and the practical examples still hold up.';
    }
    return library_normalize_blurb($line);
}

function library_post_topic(string $botUsername, string $title, string $raw, int $categoryId): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }
    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => $categoryId,
    );

    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Api-Key: ' . KONVO_API_KEY,
            'Api-Username: ' . $botUsername,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);

    return array(
        'ok' => ($err === '' && $status >= 200 && $status < 300 && is_array($decoded)),
        'status' => $status,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    library_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !library_safe_hash_equals(KONVO_SECRET, $providedKey)) {
    library_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    library_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_OPENAI_API_KEY === '') {
    library_out(500, array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$links = function_exists('kirupa_fetch_llms_links') ? kirupa_fetch_llms_links() : array();
if (!is_array($links) || $links === array()) {
    library_out(500, array('ok' => false, 'error' => 'No kirupa.com article links were available.'));
}

$seen = library_load_seen();
$article = library_pick_article($links, $seen);
if (!is_array($article)) {
    library_out(500, array('ok' => false, 'error' => 'Could not pick a kirupa.com article.'));
}

$title = library_build_title((string)($article['title'] ?? ''));
$blurbRes = library_generate_blurb_with_llm($article, false);
if (!is_array($blurbRes) || empty($blurbRes['ok']) || !isset($blurbRes['text'])) {
    $blurbRes = library_generate_blurb_with_llm($article, true);
}
$blurb = (is_array($blurbRes) && !empty($blurbRes['ok']) && isset($blurbRes['text']))
    ? trim((string)$blurbRes['text'])
    : library_fallback_blurb($article);
$blurb = library_format_blurb_paragraphs($blurb);
$sourceUrl = trim((string)($article['url'] ?? ''));
$postUrl = library_forum_article_url($sourceUrl);
$raw = $blurb . "\n\n" . $postUrl;
$categoryId = library_guess_category_id($article);

if ($dryRun) {
    library_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_kirupabot_library_topic',
        'bot' => array('username' => 'kirupabot', 'name' => 'kirupaBot'),
        'topic' => array(
            'title' => $title,
            'raw_preview' => $raw,
            'category_id' => $categoryId,
        ),
        'article' => array(
            'title' => (string)($article['title'] ?? ''),
            'url' => $postUrl,
            'source_url' => $sourceUrl,
        ),
        'source_count' => count($links),
    ));
}

$post = library_post_topic('kirupabot', $title, $raw, $categoryId);
if (!$post['ok']) {
    library_out(500, array(
        'ok' => false,
        'error' => 'Failed to post kirupaBot library topic.',
        'status' => $post['status'],
        'curl_error' => $post['error'],
        'response' => $post['body'],
        'raw' => $post['raw'],
    ));
}

$topicId = (int)($post['body']['topic_id'] ?? 0);
$postNumber = (int)($post['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

if ($sourceUrl !== '') {
    $seen[$sourceUrl] = time();
    library_save_seen($seen);
}

library_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_kirupabot_library_topic',
    'topic_url' => $topicUrl,
    'bot' => array('username' => 'kirupabot', 'name' => 'kirupaBot'),
    'topic' => array(
        'title' => $title,
        'category_id' => $categoryId,
    ),
    'article' => array(
        'title' => (string)($article['title'] ?? ''),
        'url' => $postUrl,
        'source_url' => $sourceUrl,
    ),
));
