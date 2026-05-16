<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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
if (!defined('KONVO_DISCOURSE_API_KEY')) define('KONVO_DISCOURSE_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));

function out_json(int $status, array $data): void
{
    if (function_exists('http_response_code')) http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_hash_equals(string $a, string $b): bool
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

function consensus_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/casual_consensus_state.json';
}

function consensus_load(): array
{
    $path = consensus_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function consensus_save(array $state): void
{
    @file_put_contents(consensus_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function fetch_json(string $url, array $headers = array()): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    $baseHeaders = array('Accept: application/json');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
        CURLOPT_USERAGENT => 'konvo-casual-consensus-worker/1.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) return null;
    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : null;
}

function post_json(string $url, array $payload, array $headers = array()): array
{
    if (!function_exists('curl_init')) return array('ok' => false, 'status' => 0, 'error' => 'curl unavailable', 'body' => array(), 'raw' => '');
    $ch = curl_init($url);
    $baseHeaders = array('Content-Type: application/json', 'Accept: application/json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
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

function post_content_text(array $post): string
{
    $raw = trim((string)($post['raw'] ?? ''));
    if ($raw !== '') return $raw;
    $cooked = (string)($post['cooked'] ?? '');
    $plain = strip_tags(html_entity_decode($cooked, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
    return trim((string)$plain);
}

function is_bot_user(string $username): bool
{
    $u = strtolower(trim($username));
    $botUsers = array('baymax', 'kirupabot', 'vaultboy', 'mechaprime', 'yoshiii', 'bobamilk', 'wafflefries', 'quelly', 'sora', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon');
    return in_array($u, $botUsers, true);
}

function pick_consensus_target(array $state): ?array
{
    $now = time();
    $candidates = array();
    foreach ($state as $k => $row) {
        if (!is_array($row)) continue;
        $topicId = (int)($row['topic_id'] ?? (int)$k);
        if ($topicId <= 0) continue;
        $phase = strtolower(trim((string)($row['phase'] ?? 'open')));
        $posted = !empty($row['consensus_posted']);
        if ($posted || $phase === 'closed') continue;

        $createdTs = (int)($row['created_ts'] ?? 0);
        $updatedTs = (int)($row['updated_ts'] ?? $createdTs);
        $age = $createdTs > 0 ? ($now - $createdTs) : 0;
        $idle = $updatedTs > 0 ? ($now - $updatedTs) : 0;
        $discussion = (int)($row['discussion_reply_count'] ?? 0);

        $ready = ($phase === 'ready_for_consensus')
            || ($discussion >= 3 && $idle >= 15 * 60)
            || ($discussion >= 2 && $age >= 3 * 3600);
        if (!$ready) continue;
        if ($age > 5 * 24 * 3600) continue;

        $score = 0;
        $score += min(6, $discussion) * 10;
        $score += ($phase === 'ready_for_consensus') ? 20 : 0;
        $score += (int)min(12, floor($idle / 900));
        $score -= (int)min(10, floor($age / 86400));

        $candidates[] = array('score' => $score, 'row' => $row, 'topic_id' => $topicId);
    }
    if ($candidates === array()) return null;
    usort($candidates, function ($a, $b) {
        return ((int)$b['score']) <=> ((int)$a['score']);
    });
    return $candidates[0];
}

function generate_consensus_reply(string $topicTitle, string $opRaw, string $threadContext): array
{
    if (KONVO_OPENAI_API_KEY === '' || !function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'openai unavailable');
    }

    $system = 'You are writing one final consensus-style forum reply in a casual AI/tech discussion thread. '
        . 'Keep it human, concise, and practical. '
        . 'Requirements: 2 short paragraphs. Paragraph 1 = where people mostly agree. Paragraph 2 = one unresolved caveat plus one practical takeaway. '
        . 'No bullet points, no headings, no links, no sign-off, no emojis, no rhetorical filler. '
        . 'Complete thoughts only. Do not end with a question.';
    $user = "Topic title:\n{$topicTitle}\n\nOriginal post:\n{$opRaw}\n\nThread replies:\n{$threadContext}\n\nWrite the consensus reply now.";

    $res = post_json(
        'https://api.openai.com/v1/chat/completions',
        array(
            'model' => konvo_model_for_task('reply_summary', array('technical' => false)),
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'temperature' => 0.55,
        ),
        array('Authorization: Bearer ' . KONVO_OPENAI_API_KEY)
    );

    if (!$res['ok'] || !isset($res['body']['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'generation failed', 'status' => $res['status'], 'raw' => $res['raw']);
    }

    $txt = trim((string)$res['body']['choices'][0]['message']['content']);
    if ($txt === '') return array('ok' => false, 'error' => 'empty consensus text');
    $txt = preg_replace('/```[\s\S]*?```/m', '', $txt);
    $txt = preg_replace('/\n{3,}/', "\n\n", (string)$txt);
    $txt = trim((string)$txt);
    if (!preg_match('/[.!?]$/', $txt)) $txt .= '.';
    $txt = preg_replace('/\?\s*$/', '.', (string)$txt) ?? $txt;

    return array('ok' => true, 'text' => $txt);
}

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') out_json(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
if ($key === '' || !safe_hash_equals(KONVO_SECRET, $key)) out_json(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Use ?key=YOUR_SECRET'));
if (KONVO_DISCOURSE_API_KEY === '') out_json(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
if (KONVO_OPENAI_API_KEY === '') out_json(500, array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.'));

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$state = consensus_load();
$pick = pick_consensus_target($state);
if (!is_array($pick) || !isset($pick['topic_id'])) {
    out_json(200, array('ok' => true, 'posted' => false, 'reason' => 'No consensus-ready discussion topic found.'));
}

$topicId = (int)$pick['topic_id'];
$row = isset($pick['row']) && is_array($pick['row']) ? $pick['row'] : array();
$topic = fetch_json(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', array(
    'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
    'Api-Username: BayMax',
));
if (!is_array($topic) || !isset($topic['post_stream']['posts']) || !is_array($topic['post_stream']['posts'])) {
    out_json(500, array('ok' => false, 'error' => 'Could not fetch topic detail.', 'topic_id' => $topicId));
}

$posts = $topic['post_stream']['posts'];
if ($posts === array()) {
    out_json(200, array('ok' => true, 'posted' => false, 'reason' => 'Topic had no posts.', 'topic_id' => $topicId));
}
$op = $posts[0];
$opUsername = strtolower(trim((string)($op['username'] ?? '')));
if ($opUsername === '' || !is_bot_user($opUsername)) {
    out_json(200, array('ok' => true, 'posted' => false, 'reason' => 'Topic OP is not a bot; skipping.', 'topic_id' => $topicId));
}

$title = trim((string)($topic['title'] ?? 'Untitled topic'));
$opRaw = post_content_text($op);
$lines = array();
$count = 0;
for ($i = max(1, count($posts) - 8); $i < count($posts); $i++) {
    $p = $posts[$i] ?? null;
    if (!is_array($p)) continue;
    $u = trim((string)($p['username'] ?? ''));
    $raw = post_content_text($p);
    if ($raw === '') continue;
    $lines[] = 'Post #' . (int)($p['post_number'] ?? 0) . ' by @' . $u . ': ' . $raw;
    $count++;
}
if ($lines === array()) {
    $lines[] = 'No substantial replies yet.';
}
$threadContext = implode("\n\n", $lines);

$generated = generate_consensus_reply($title, $opRaw, $threadContext);
if (empty($generated['ok']) || !isset($generated['text'])) {
    out_json(502, array(
        'ok' => false,
        'error' => 'Failed to generate consensus response.',
        'topic_id' => $topicId,
        'detail' => $generated,
    ));
}
$rawReply = (string)$generated['text'];

if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_consensus',
        'topic_id' => $topicId,
        'topic_title' => $title,
        'op_bot' => $opUsername,
        'state' => $row,
        'reply_preview' => $rawReply,
        'thread_context_posts_used' => $count,
    ));
}

$postRes = post_json(
    rtrim(KONVO_BASE_URL, '/') . '/posts.json',
    array(
        'topic_id' => $topicId,
        'raw' => $rawReply,
    ),
    array(
        'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
        'Api-Username: ' . $opUsername,
    )
);

if (!$postRes['ok']) {
    out_json(500, array(
        'ok' => false,
        'error' => 'Failed to post consensus reply.',
        'topic_id' => $topicId,
        'status' => $postRes['status'],
        'curl_error' => $postRes['error'],
        'response' => $postRes['body'],
        'raw' => $postRes['raw'],
    ));
}

$postNumber = (int)($postRes['body']['post_number'] ?? 0);
$postId = (int)($postRes['body']['id'] ?? 0);
$postUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

$keyId = (string)$topicId;
if (!isset($state[$keyId]) || !is_array($state[$keyId])) $state[$keyId] = array('topic_id' => $topicId);
$state[$keyId]['phase'] = 'closed';
$state[$keyId]['consensus_posted'] = true;
$state[$keyId]['consensus_post_id'] = $postId;
$state[$keyId]['consensus_post_number'] = $postNumber;
$state[$keyId]['consensus_post_url'] = $postUrl;
$state[$keyId]['updated_ts'] = time();
consensus_save($state);

out_json(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_consensus_reply',
    'topic_id' => $topicId,
    'topic_title' => $title,
    'op_bot' => $opUsername,
    'post_url' => $postUrl,
    'state' => $state[$keyId],
));
