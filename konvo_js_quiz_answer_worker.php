<?php

/*
 * Browser-callable JS quiz answer worker.
 *
 * Example:
 * https://www.kirupa.com/konvo_js_quiz_answer_worker.php?key=YOUR_SECRET&dry_run=1
 * https://www.kirupa.com/konvo_js_quiz_answer_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_js_quiz_answer_worker.php?key=YOUR_SECRET&force=1
 * https://www.kirupa.com/konvo_js_quiz_answer_worker.php?key=YOUR_SECRET&topic_id=12345&force=1
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('js_quiz_answer_internal_error_out')) {
    function js_quiz_answer_internal_error_out(string $message, int $status = 500): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }
        echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_SLASHES);
        exit;
    }
}

set_exception_handler(static function (\Throwable $e): void {
    $where = basename((string)$e->getFile()) . ':' . (int)$e->getLine();
    $msg = trim((string)$e->getMessage());
    if ($msg === '') $msg = 'Unhandled exception';
    js_quiz_answer_internal_error_out('JS quiz answer exception: ' . $msg . ' [' . $where . ']', 500);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) return;
    $type = (int)($err['type'] ?? 0);
    if (!in_array($type, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
    $msg = trim((string)($err['message'] ?? 'Fatal error'));
    $file = basename((string)($err['file'] ?? ''));
    $line = (int)($err['line'] ?? 0);
    js_quiz_answer_internal_error_out('JS quiz answer fatal: ' . $msg . ' [' . $file . ':' . $line . ']', 500);
});

$signatureHelper = __DIR__ . '/konvo_signature_helper.php';
if (is_file($signatureHelper)) {
    require_once $signatureHelper;
}
require_once __DIR__ . '/kirupa_article_helper.php';

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_TZ')) define('KONVO_TZ', trim((string)(getenv('KONVO_TIMEZONE') ?: 'America/Los_Angeles')));

@date_default_timezone_set(KONVO_TZ);

function out_json($code, $data)
{
    if (function_exists('http_response_code')) {
        http_response_code((int)$code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_hash_equals($a, $b)
{
    $a = (string)$a;
    $b = (string)$b;
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function js_quiz_state_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/js_quiz_daily_state.json';
}

function js_quiz_load_state()
{
    $path = js_quiz_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function js_quiz_save_state($state)
{
    if (!is_array($state)) return;
    @file_put_contents(js_quiz_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function jsqa_call_api(string $url, array $headers, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => null, 'raw' => '');
    }

    $ch = curl_init($url);
    $opts = array(
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
    );
    if ($payload !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return array('ok' => false, 'status' => 0, 'error' => $error, 'body' => null, 'raw' => '');
    }

    $decoded = json_decode((string)$body, true);
    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => '',
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => (string)$body,
    );
}

function normalize_signature_once($text, $name)
{
    $candidates = function_exists('konvo_signature_name_candidates')
        ? konvo_signature_name_candidates((string)$name)
        : array((string)$name);
    if (!is_array($candidates) || count($candidates) === 0) {
        $candidates = array((string)$name);
    }

    $lines = preg_split('/\R/', trim((string)$text));
    if (!is_array($lines)) $lines = array();
    while (!empty($lines)) {
        $last = trim((string)end($lines));
        $matched = false;
        foreach ($candidates as $candidate) {
            if (preg_match('/^' . preg_quote((string)$candidate, '/') . '\\.?$/i', $last)) {
                $matched = true;
                break;
            }
        }
        if ($last === '' || $matched) {
            array_pop($lines);
            continue;
        }
        break;
    }
    $body = trim(implode("\n", $lines));
    foreach ($candidates as $candidate) {
        $body = preg_replace('/\s+' . preg_quote((string)$candidate, '/') . '\\.?$/i', '', (string)$body);
    }
    $body = trim((string)$body);
    if ($body === '') return '';
    return $body;
}

function jsqa_pick_pending_index($pending, $now, $force, $topicFilter)
{
    if (!is_array($pending) || count($pending) === 0) {
        return -1;
    }

    $bestIdx = -1;
    $bestDue = PHP_INT_MAX;
    foreach ($pending as $idx => $item) {
        if (!is_array($item)) continue;
        $answeredAt = (int)($item['answered_at'] ?? 0);
        if ($answeredAt > 0) continue;

        $topicId = (int)($item['topic_id'] ?? 0);
        if ($topicFilter > 0 && $topicId !== $topicFilter) continue;

        $dueAt = (int)($item['due_at'] ?? 0);
        if ($dueAt <= 0) {
            $createdAt = (int)($item['created_at'] ?? 0);
            $dueAt = $createdAt > 0 ? ($createdAt + (24 * 60 * 60)) : $now;
        }
        if (!$force && $dueAt > $now) continue;

        if ($dueAt < $bestDue) {
            $bestDue = $dueAt;
            $bestIdx = (int)$idx;
        }
    }
    return $bestIdx;
}

function jsqa_topic_has_answer_marker(array $topic, string $botUsername, int $quizPostNumber): array
{
    $posts = $topic['post_stream']['posts'] ?? array();
    if (!is_array($posts)) {
        return array('found' => false, 'post_number' => 0);
    }
    $bot = strtolower(trim($botUsername));
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $pn = (int)($post['post_number'] ?? 0);
        if ($pn <= $quizPostNumber) continue;
        $u = strtolower(trim((string)($post['username'] ?? '')));
        if ($u !== $bot) continue;
        $raw = trim((string)($post['raw'] ?? ''));
        if ($raw === '') {
            $cooked = (string)($post['cooked'] ?? '');
            if ($cooked !== '') {
                $raw = trim(html_entity_decode(strip_tags($cooked), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        if ($raw !== '' && stripos($raw, 'JS Quiz answer:') !== false) {
            return array('found' => true, 'post_number' => $pn);
        }
    }
    return array('found' => false, 'post_number' => 0);
}

function jsqa_build_answer_raw(array $item, string $signature): string
{
    $answerIndex = (int)($item['answer_index'] ?? 1);
    if ($answerIndex < 1) $answerIndex = 1;
    $letters = array(1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D');
    $letter = isset($letters[$answerIndex]) ? $letters[$answerIndex] : (string)$answerIndex;
    $answerOption = trim((string)($item['answer_option'] ?? ''));
    if ($answerOption === '') $answerOption = 'Option ' . $answerIndex;
    $explanation = trim((string)($item['explanation'] ?? ''));
    if ($explanation === '') {
        $explanation = 'The correct choice follows JavaScript execution order, scope, and runtime semantics in this snippet.';
    }
    $topicTitle = trim((string)($item['topic_title'] ?? ''));
    $quizTitle = trim((string)($item['quiz_title'] ?? ''));
    $articleUrl = '';
    if (function_exists('kirupa_find_relevant_article')) {
        $article = kirupa_find_relevant_article($topicTitle . "\n" . $quizTitle . "\n" . $explanation, 1);
        if (is_array($article) && isset($article['url'])) {
            $articleUrl = trim((string)$article['url']);
        }
    }

    $lines = array();
    $lines[] = 'JS Quiz answer: Option ' . $answerIndex . ' (' . $letter . ').';
    $lines[] = '';
    $lines[] = 'Correct choice: ' . $answerOption;
    $lines[] = '';
    $lines[] = 'Why:';
    $lines[] = $explanation;
    if ($articleUrl !== '') {
        $lines[] = '';
        $lines[] = 'Go deeper:';
        $lines[] = '';
        $lines[] = $articleUrl;
    }

    return normalize_signature_once(implode("\n", $lines), $signature);
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !safe_hash_equals(KONVO_SECRET, $providedKey)) {
    out_json(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$topicFilter = (int)($_GET['topic_id'] ?? 0);
$now = time();

$state = js_quiz_load_state();
$pending = isset($state['pending_answers']) && is_array($state['pending_answers']) ? $state['pending_answers'] : array();
$pickedIdx = jsqa_pick_pending_index($pending, $now, $force, $topicFilter);

if ($pickedIdx < 0) {
    out_json(200, array(
        'ok' => true,
        'ignored' => true,
        'reason' => 'no_due_pending_js_quiz_answers',
        'pending_count' => count($pending),
        'force' => $force,
        'topic_filter' => $topicFilter,
    ));
}

$item = is_array($pending[$pickedIdx] ?? null) ? $pending[$pickedIdx] : array();
$topicId = (int)($item['topic_id'] ?? 0);
$quizPostNumber = (int)($item['quiz_post_number'] ?? 1);
$botUsername = trim((string)($item['bot_username'] ?? 'BayMax'));
$botName = trim((string)($item['bot_name'] ?? $botUsername));
if ($topicId <= 0 || $botUsername === '') {
    out_json(500, array('ok' => false, 'error' => 'Invalid pending quiz answer metadata.'));
}

$headers = array(
    'Content-Type: application/json',
    'Api-Key: ' . KONVO_API_KEY,
    'Api-Username: ' . $botUsername,
);

$topicRes = jsqa_call_api(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', $headers, null);
if (!$topicRes['ok'] || !is_array($topicRes['body'])) {
    out_json(502, array(
        'ok' => false,
        'error' => 'Could not read topic for quiz answer.',
        'status' => (int)($topicRes['status'] ?? 0),
        'raw' => (string)($topicRes['raw'] ?? ''),
    ));
}

$already = jsqa_topic_has_answer_marker($topicRes['body'], $botUsername, $quizPostNumber);
if (!empty($already['found'])) {
    $pending[$pickedIdx]['answered_at'] = $now;
    $pending[$pickedIdx]['answer_post_number'] = (int)($already['post_number'] ?? 0);
    $state['pending_answers'] = $pending;
    js_quiz_save_state($state);
    out_json(200, array(
        'ok' => true,
        'ignored' => true,
        'reason' => 'answer_already_posted',
        'topic_id' => $topicId,
        'post_number' => (int)($already['post_number'] ?? 0),
    ));
}

$signatureSeed = strtolower($botUsername . '|' . $topicId . '|js-quiz-answer');
$signature = function_exists('konvo_signature_base_name')
    ? konvo_signature_base_name($botName)
    : ($botName !== '' ? $botName : $botUsername);
if (function_exists('konvo_signature_with_optional_emoji')) {
    $signature = konvo_signature_with_optional_emoji($signature, $signatureSeed);
}

$answerRaw = jsqa_build_answer_raw($item, $signature);
if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_js_quiz_answer',
        'topic_id' => $topicId,
        'bot_username' => $botUsername,
        'reply_to_post_number' => $quizPostNumber,
        'raw_preview' => $answerRaw,
    ));
}

$payload = array(
    'topic_id' => $topicId,
    'raw' => $answerRaw,
);
if ($quizPostNumber > 0) {
    $payload['reply_to_post_number'] = $quizPostNumber;
}

$postRes = jsqa_call_api(rtrim(KONVO_BASE_URL, '/') . '/posts.json', $headers, $payload);
if (!$postRes['ok'] || !is_array($postRes['body'])) {
    out_json(502, array(
        'ok' => false,
        'error' => 'Failed to post JS quiz answer.',
        'status' => (int)($postRes['status'] ?? 0),
        'api_error' => is_array($postRes['body']) ? (string)($postRes['body']['error'] ?? '') : '',
        'raw' => (string)($postRes['raw'] ?? ''),
    ));
}

$answerPostNumber = (int)($postRes['body']['post_number'] ?? 0);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $answerPostNumber;

$pending[$pickedIdx]['answered_at'] = $now;
$pending[$pickedIdx]['answer_post_number'] = $answerPostNumber;
$state['pending_answers'] = $pending;
js_quiz_save_state($state);

out_json(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_js_quiz_answer',
    'topic_id' => $topicId,
    'topic_url' => $topicUrl,
    'bot_username' => $botUsername,
    'reply_to_post_number' => $quizPostNumber,
    'answer_post_number' => $answerPostNumber,
));
