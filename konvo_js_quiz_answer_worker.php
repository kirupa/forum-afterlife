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
require_once __DIR__ . '/konvo_anthropic_client.php';

$jsqaModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($jsqaModelRouter)) {
    require_once $jsqaModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'claude-sonnet-5';
    }
}

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

function jsqa_is_bot_user($username): bool
{
    $u = strtolower(trim((string)$username));
    $bots = array('baymax', 'kirupabot', 'vaultboy', 'mechaprime', 'yoshiii', 'bobamilk', 'wafflefries', 'quelly', 'sora', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon');
    return in_array($u, $bots, true);
}

function jsqa_post_text(array $post): string
{
    $raw = trim((string)($post['raw'] ?? ''));
    if ($raw !== '') return $raw;
    $cooked = (string)($post['cooked'] ?? '');
    if ($cooked === '') return '';
    $plain = html_entity_decode(strip_tags($cooked), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
    return trim((string)$plain);
}

/**
 * Human replies to the challenge, oldest first, so "who got it first" is simply
 * the earliest correct entry.
 */
function jsqa_collect_human_attempts(array $topicBody, int $challengePostNumber): array
{
    $posts = isset($topicBody['post_stream']['posts']) && is_array($topicBody['post_stream']['posts'])
        ? $topicBody['post_stream']['posts']
        : array();
    $out = array();
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $pn = (int)($post['post_number'] ?? 0);
        if ($pn <= $challengePostNumber) continue;
        $username = trim((string)($post['username'] ?? ''));
        if ($username === '' || jsqa_is_bot_user($username)) continue;
        $text = jsqa_post_text($post);
        if ($text === '') continue;
        $out[] = array(
            'username' => $username,
            'post_number' => $pn,
            'text' => $text,
            'created_at' => trim((string)($post['created_at'] ?? '')),
            'ts' => (int)strtotime((string)($post['created_at'] ?? '')),
        );
    }
    usort($out, function ($a, $b) {
        if ($a['ts'] === $b['ts']) return $a['post_number'] <=> $b['post_number'];
        return $a['ts'] <=> $b['ts'];
    });
    return $out;
}

/**
 * Grade every human attempt in one model call.
 * Returns [post_number => ['is_attempt'=>bool,'correct'=>bool,'why_wrong'=>string]].
 * A failure here is non-fatal: the reveal still posts, just without a scoreboard.
 */
function jsqa_grade_attempts(string $challenge, string $correctAnswer, array $attempts, string $kind = 'spot_the_bug'): array
{
    if ($attempts === array()) return array();

    $lines = array();
    foreach ($attempts as $i => $a) {
        $lines[] = ($i + 1) . '. @' . $a['username'] . ": " . mb_substr($a['text'], 0, 500);
    }

    $system = "You are grading answers to a code challenge on a friendly forum.\n"
        . "You are given the challenge, a reference answer, and numbered replies from people.\n"
        . "For each reply decide:\n"
        . "- is_attempt: did they actually try to answer, or were they just chatting/welcoming/joking?\n"
        . ($kind === 'coding_challenge'
            ? "- correct: does their code actually satisfy the task? A coding challenge has many valid solutions, so judge it on whether it works and respects the stated rules, NOT on whether it resembles the reference. Different style, different approach and different variable names are all fine. Minor syntax slips in an otherwise working answer still count as correct.\n"
            : "- correct: does their answer substantively match the correct answer? It does not need to be word for word, and partial-but-right reasoning counts as correct. Be fair, not pedantic.\n")
        . "- why_wrong: only when they attempted and got it wrong, one short sentence on what they missed. Be kind and specific. Empty string otherwise.\n"
        . "Return ONLY JSON: {\"grades\": [{\"n\": 1, \"is_attempt\": true, \"correct\": false, \"why_wrong\": \"...\"}]} with one entry per numbered reply.";

    $user = "Challenge:\n{$challenge}\n\nCorrect answer:\n{$correctAnswer}\n\nReplies:\n" . implode("\n", $lines) . "\n\nGrade each reply.";

    $res = konvo_anthropic_chat_json(array(
        'model' => konvo_model_for_task('quality_hard', array('technical' => true)),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'max_tokens' => 2048,
        'temperature' => 0.2,
    ), 90);

    if (!($res['ok'] ?? false)) return array();
    $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') return array();

    $obj = json_decode($content, true);
    if (!is_array($obj)) {
        $a = strpos($content, '{');
        $b = strrpos($content, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $obj = json_decode(substr($content, (int)$a, (int)($b - $a + 1)), true);
        }
    }
    if (!is_array($obj) || !isset($obj['grades']) || !is_array($obj['grades'])) return array();

    $out = array();
    foreach ($obj['grades'] as $g) {
        if (!is_array($g)) continue;
        $n = (int)($g['n'] ?? 0);
        if ($n < 1 || $n > count($attempts)) continue;
        $out[(int)$attempts[$n - 1]['post_number']] = array(
            'is_attempt' => (bool)($g['is_attempt'] ?? false),
            'correct' => (bool)($g['correct'] ?? false),
            'why_wrong' => trim((string)($g['why_wrong'] ?? '')),
        );
    }
    return $out;
}

/**
 * Markdown scoreboard: who was right, who was first, and why wrong answers missed.
 */
function jsqa_build_scoreboard(array $attempts, array $grades): array
{
    if ($attempts === array() || $grades === array()) return array('markdown' => '', 'first' => '');

    // One person can post several guesses; credit them once, at their earliest
    // correct attempt. Without this the scoreboard reads "@sock, @sock, @sock".
    $correct = array();
    $wrong = array();
    $seenCorrect = array();
    $seenWrong = array();
    foreach ($attempts as $a) {
        $g = $grades[(int)$a['post_number']] ?? null;
        if (!is_array($g) || empty($g['is_attempt'])) continue; // ignore chit-chat
        $uKey = strtolower((string)$a['username']);
        if (!empty($g['correct'])) {
            if (isset($seenCorrect[$uKey])) continue;
            $seenCorrect[$uKey] = true;
            $correct[] = $a;
        } else {
            if (isset($seenWrong[$uKey])) continue;
            $seenWrong[$uKey] = true;
            $wrong[] = array('attempt' => $a, 'why' => (string)$g['why_wrong']);
        }
    }
    // Someone who eventually got it right should not also be listed as wrong.
    $wrong = array_values(array_filter($wrong, function ($w) use ($seenCorrect) {
        return !isset($seenCorrect[strtolower((string)$w['attempt']['username'])]);
    }));
    if ($correct === array() && $wrong === array()) return array('markdown' => '', 'first' => '');

    $lines = array();
    $lines[] = '';
    $lines[] = '---';
    $lines[] = '';

    if ($correct !== array()) {
        $first = $correct[0]; // attempts are oldest-first
        if (count($correct) === 1) {
            $lines[] = '**Got it:** @' . $first['username'] . ' :trophy:';
        } else {
            $names = array();
            foreach ($correct as $c) $names[] = '@' . $c['username'];
            $lines[] = '**Got it:** ' . implode(', ', $names);
            $lines[] = '';
            $lines[] = '**First:** @' . $first['username'] . ' :trophy:';
        }
    } else {
        $lines[] = '**Nobody got this one.** It was a sneaky one.';
    }

    if ($wrong !== array()) {
        $lines[] = '';
        $lines[] = '**Close but not quite:**';
        foreach ($wrong as $w) {
            $why = $w['why'] !== '' ? $w['why'] : 'that is not the bug in this snippet.';
            $lines[] = '';
            $lines[] = '@' . $w['attempt']['username'] . ' - ' . $why;
        }
    }

    return array(
        'markdown' => implode("\n", $lines),
        'first' => $correct !== array() ? (string)$correct[0]['username'] : '',
    );
}

// ---------------------------------------------------------------------------
// Global "first correct answer" leaderboard.
// ---------------------------------------------------------------------------

function jsqa_leaderboard_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/quiz_first_answer_leaderboard.json';
}

function jsqa_leaderboard_empty(): array
{
    return array('counts' => array(), 'counted_topics' => array(), 'total_awarded' => 0, '_unreadable' => false);
}

/**
 * The tally is an accumulator, never recomputed from the forum: deleting posts
 * or a topic ageing out of latest.json can never reduce it.
 *
 * The real risk is the state file itself. A missing file legitimately means
 * "no wins yet", but an unreadable or malformed one must NOT be treated that
 * way: writing a fresh board over it would silently erase every past win. In
 * that case the board is flagged unreadable and awarding refuses to persist.
 */
function jsqa_load_leaderboard(): array
{
    $empty = jsqa_leaderboard_empty();
    $p = jsqa_leaderboard_path();
    if (!is_file($p)) return $empty; // genuinely new board

    $raw = @file_get_contents($p);
    $bad = $empty;
    $bad['_unreadable'] = true;

    $unreadable = (!is_string($raw) || trim($raw) === '');
    $d = $unreadable ? null : json_decode($raw, true);
    if ($unreadable || !is_array($d) || !isset($d['counts']) || !is_array($d['counts'])) {
        // Preserve the damaged file once for forensics. Guarded so repeated runs
        // do not litter the state directory with copies.
        if (glob($p . '.corrupt.*') === array()) {
            @copy($p, $p . '.corrupt.' . date('Ymd-His'));
        }
        return $bad;
    }

    if (!isset($d['counted_topics']) || !is_array($d['counted_topics'])) $d['counted_topics'] = array();
    if (!isset($d['total_awarded'])) {
        $sum = 0;
        foreach ($d['counts'] as $row) {
            if (is_array($row)) $sum += (int)($row['wins'] ?? 0);
        }
        $d['total_awarded'] = $sum;
    }
    $d['_unreadable'] = false;
    return $d;
}

function jsqa_leaderboard_total(array $board): int
{
    $sum = 0;
    foreach (($board['counts'] ?? array()) as $row) {
        if (is_array($row)) $sum += (int)($row['wins'] ?? 0);
    }
    return $sum;
}

/**
 * Atomic, monotonic save. Writes to a temp file and renames, so a crash cannot
 * leave a half-written board behind, and refuses any write that would lower the
 * running total.
 */
function jsqa_save_leaderboard(array $board): bool
{
    if (!empty($board['_unreadable'])) return false;

    $path = jsqa_leaderboard_path();
    $newTotal = jsqa_leaderboard_total($board);

    $onDisk = jsqa_load_leaderboard();
    if (empty($onDisk['_unreadable'])) {
        $oldTotal = jsqa_leaderboard_total($onDisk);
        if ($newTotal < $oldTotal) {
            return false; // never let the tally go backwards
        }
    } elseif (is_file($path)) {
        // On-disk board is unreadable (already backed up on load). Never
        // overwrite it with a fresh board - that would erase the real history.
        return false;
    }

    $board['total_awarded'] = $newTotal;
    unset($board['_unreadable']);

    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($board, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Award a win. Keyed by topic so a re-run can never double-count someone.
 * Returns the updated board without writing when $persist is false, so a dry
 * run previews the same numbers the live post would show.
 */
function jsqa_award_first(array $board, string $username, int $topicId, bool $persist): array
{
    $username = trim($username);
    if ($username === '' || $topicId <= 0) return $board;
    // Refuse to build on a board we could not read; awarding here would later be
    // written out as a brand new board and erase the real history.
    if (!empty($board['_unreadable'])) return $board;
    if (in_array($topicId, array_map('intval', $board['counted_topics']), true)) return $board;

    $key = strtolower($username);
    if (!isset($board['counts'][$key]) || !is_array($board['counts'][$key])) {
        $board['counts'][$key] = array('display' => $username, 'wins' => 0);
    }
    $board['counts'][$key]['display'] = $username;
    $board['counts'][$key]['wins'] = (int)$board['counts'][$key]['wins'] + 1;
    // Keep every awarded topic id. Trimming this list would let a very old topic
    // be awarded a second time if its answer post were ever removed.
    $board['counted_topics'][] = $topicId;

    if ($persist) {
        jsqa_save_leaderboard($board);
    }
    return $board;
}

function jsqa_render_leaderboard(array $board, int $limit = 10): string
{
    $counts = isset($board['counts']) && is_array($board['counts']) ? $board['counts'] : array();
    if ($counts === array()) return '';

    $rows = array();
    foreach ($counts as $key => $row) {
        if (!is_array($row)) continue;
        $wins = (int)($row['wins'] ?? 0);
        if ($wins < 1) continue;
        $rows[] = array('name' => (string)($row['display'] ?? $key), 'wins' => $wins);
    }
    if ($rows === array()) return '';

    usort($rows, function ($a, $b) {
        if ($a['wins'] === $b['wins']) return strcasecmp($a['name'], $b['name']);
        return $b['wins'] <=> $a['wins'];
    });
    $total = count($rows);
    $rows = array_slice($rows, 0, max(1, $limit));

    $lines = array();
    $lines[] = '';
    $lines[] = '**First-answer leaderboard**';
    $lines[] = '';
    $rank = 0;
    $lastWins = null;
    $shown = 0;
    foreach ($rows as $r) {
        $shown++;
        // Ties share a rank.
        if ($lastWins === null || $r['wins'] !== $lastWins) {
            $rank = $shown;
            $lastWins = $r['wins'];
        }
        $medal = $rank === 1 ? ' :trophy:' : '';
        $lines[] = $rank . '. @' . $r['name'] . ' - ' . $r['wins'] . ' (' . ($r['wins'] === 1 ? 'first' : 'firsts') . ')' . $medal;
    }
    if ($total > count($rows)) {
        $lines[] = '';
        $lines[] = '*Top ' . count($rows) . ' of ' . $total . '.*';
    }
    return implode("\n", $lines);
}

function jsqa_build_answer_raw(array $item, string $signature, string $scoreboard = ''): string
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
    $lines[] = '**JS Quiz answer:** Option ' . $answerIndex . ' (' . $letter . ').';
    $lines[] = '';
    $lines[] = '**Correct choice:** ' . $answerOption;
    $lines[] = '';
    $lines[] = '**Why:**';
    $lines[] = $explanation;
    if ($articleUrl !== '') {
        $lines[] = '';
        $lines[] = '**Go deeper:**';
        $lines[] = '';
        $lines[] = $articleUrl;
    }

    $body = implode("\n", $lines);
    if (trim($scoreboard) !== '') {
        $body .= "\n" . $scoreboard;
    }

    return normalize_signature_once($body, $signature);
}

// ---------------------------------------------------------------------------
// Spot the Bug reveal.
//
// Unlike the JS quiz, the Spot the Bug worker deliberately stores no answer
// (its prompt forbids including one), so there is nothing to look up. The
// reveal therefore works the bug out from the posted snippet, grades whoever
// guessed, and posts once per topic ~24h after it went up.
// ---------------------------------------------------------------------------

if (!defined('JSQA_SPOT_ANSWER_MARKER')) define('JSQA_SPOT_ANSWER_MARKER', '**Spot the Bug answer:**');

/**
 * Titles the bots generate for challenge topics we are allowed to answer.
 * Spot the Bug and Coding Challenge share the whole reveal pipeline: same
 * grading, same scoreboard, same leaderboard.
 */
function jsqa_challenge_title_pattern(): string
{
    return '/(?:spot\s+the\s+bug|coding\s+challenge)\s*-\s*#\d+/i';
}

function jsqa_is_challenge_title($title): bool
{
    return (bool)preg_match(jsqa_challenge_title_pattern(), trim((string)$title));
}

function jsqa_challenge_kind(string $title): string
{
    return (stripos($title, 'coding challenge') !== false) ? 'coding_challenge' : 'spot_the_bug';
}

function jsqa_topic_has_spot_answer(array $topicBody): bool
{
    $posts = isset($topicBody['post_stream']['posts']) && is_array($topicBody['post_stream']['posts'])
        ? $topicBody['post_stream']['posts']
        : array();
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        // Deliberately author-agnostic. Keying this on "was it a bot" meant an
        // answer posted under a non-bot name was invisible to the check, and the
        // worker re-answered the same topic every hour.
        $txt = jsqa_post_text($post);
        if (strpos($txt, 'Spot the Bug answer') !== false) return true;
        if (strpos($txt, 'Challenge solution') !== false) return true;
    }
    return false;
}

/**
 * Only ever reveal on challenges the bots actually set: the generated format is
 * "Spot the bug - #N: Title". A human thread that merely mentions spotting a bug
 * is not ours to answer, and must never be posted into under their name.
 */
function jsqa_is_bot_authored_challenge(string $title, string $opUsername): bool
{
    if (!jsqa_is_bot_user($opUsername)) return false;
    return jsqa_is_challenge_title($title);
}

/**
 * Oldest un-revealed Spot the Bug topic that is at least $minAgeSecs old.
 */
function jsqa_find_spot_target(array $headers, int $minAgeSecs, int $topicFilter = 0): ?array
{
    $latest = jsqa_call_api(rtrim(KONVO_BASE_URL, '/') . '/latest.json?order=created', $headers, null);
    if (!$latest['ok'] || !is_array($latest['body'])) return null;
    $topics = $latest['body']['topic_list']['topics'] ?? array();
    if (!is_array($topics)) return null;

    $now = time();
    $candidates = array();
    foreach ($topics as $t) {
        if (!is_array($t)) continue;
        $topicId = (int)($t['id'] ?? 0);
        if ($topicId <= 0) continue;
        if ($topicFilter > 0 && $topicId !== $topicFilter) continue;
        if (!jsqa_is_challenge_title((string)($t['title'] ?? ''))) continue;
        if (!empty($t['closed']) || !empty($t['archived'])) continue;
        if (isset($t['visible']) && !$t['visible']) continue;

        $createdTs = (int)strtotime((string)($t['created_at'] ?? ''));
        if ($createdTs <= 0) continue;
        if ($topicFilter <= 0 && ($now - $createdTs) < $minAgeSecs) continue;

        $candidates[] = array('topic_id' => $topicId, 'created_ts' => $createdTs, 'title' => (string)($t['title'] ?? ''));
    }
    if ($candidates === array()) return null;

    usort($candidates, function ($a, $b) { return $a['created_ts'] <=> $b['created_ts']; });

    foreach ($candidates as $c) {
        $detail = jsqa_call_api(rtrim(KONVO_BASE_URL, '/') . '/t/' . (int)$c['topic_id'] . '.json', $headers, null);
        if (!$detail['ok'] || !is_array($detail['body'])) continue;
        if (jsqa_topic_has_spot_answer($detail['body'])) continue;
        $posts = $detail['body']['post_stream']['posts'] ?? array();
        if (!is_array($posts) || $posts === array()) continue;
        $op = $posts[0];
        $c['body'] = $detail['body'];
        $c['op_text'] = jsqa_post_text(is_array($op) ? $op : array());
        $c['op_post_number'] = (int)($op['post_number'] ?? 1);
        $c['bot_username'] = trim((string)($op['username'] ?? 'BayMax'));
        // Hard stop: never post into a topic a human started, and never post
        // under a human's name.
        if (!jsqa_is_bot_authored_challenge((string)$c['title'], (string)$c['bot_username'])) continue;
        if ($c['op_text'] === '') continue;
        return $c;
    }
    return null;
}

/**
 * Work out the bug and the fix from the snippet itself.
 */
function jsqa_solve_spot_the_bug(string $opText, string $kind = 'spot_the_bug'): array
{
    if ($kind === 'coding_challenge') {
        $system = "You are writing the official solution to a small coding challenge posted on a friendly developer forum.\n"
            . "Read the task and write a correct, idiomatic solution. It is published as the reference answer, so it must actually work.\n"
            . "Keep it plain CSS or plain JavaScript with no libraries, no build step and no framework, matching how the challenge was set.\n"
            . "Identify the language from the task itself and report it.\n"
            . "If the task is too vague to have a definite solution, set bug to an empty string rather than guessing.\n"
            . "Return ONLY JSON: {\"language\": \"css|js|...\", \"bug\": \"one sentence describing the approach\", \"fix\": \"the solution code\", \"why\": \"2 to 3 sentences on why this works\"}.\n"
            . "No em dash anywhere. Do not add commentary outside the JSON.";
    } else {
        $system = "You are solving a 'Spot the Bug' challenge posted on a friendly developer forum.\n"
            . "Read the snippet and work out the single intended bug. Be precise and correct - this is published as the official answer.\n"
            . "Answer in the language the snippet is actually written in. Do not assume JavaScript: identify the language from the code itself and report it.\n"
            . "If you cannot identify a definite bug, set bug to an empty string rather than guessing.\n"
            . "Return ONLY JSON: {\"language\": \"js|cpp|python|...\", \"bug\": \"one sentence naming the bug\", \"fix\": \"the corrected code or the specific change, short\", \"why\": \"2 to 3 sentences explaining why it behaves that way\"}.\n"
            . "No em dash anywhere. Do not add commentary outside the JSON.";
    }

    $res = konvo_anthropic_chat_json(array(
        'model' => konvo_model_for_task('code_repair', array('technical' => true)),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => "Challenge post:\n" . $opText . "\n\nSolve it."),
        ),
        'max_tokens' => 1200,
        'temperature' => 0.2,
    ), 90);

    if (!($res['ok'] ?? false)) return array();
    $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') return array();

    $obj = json_decode($content, true);
    if (!is_array($obj)) {
        $a = strpos($content, '{');
        $b = strrpos($content, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $obj = json_decode(substr($content, (int)$a, (int)($b - $a + 1)), true);
        }
    }
    if (!is_array($obj)) return array();
    return array(
        'language' => trim((string)($obj['language'] ?? '')),
        'bug' => trim((string)($obj['bug'] ?? '')),
        'fix' => trim((string)($obj['fix'] ?? '')),
        'why' => trim((string)($obj['why'] ?? '')),
    );
}

function jsqa_build_spot_answer_raw(array $solution, string $scoreboard, string $signature, string $kind = 'spot_the_bug'): string
{
    $lines = array();
    $marker = ($kind === 'coding_challenge') ? '**Challenge solution:**' : JSQA_SPOT_ANSWER_MARKER;
    $lines[] = $marker . ' ' . (string)($solution['bug'] ?? '');
    $fix = trim((string)($solution['fix'] ?? ''));
    if ($fix !== '') {
        $lines[] = '';
        $lines[] = ($kind === 'coding_challenge') ? '**One way to do it:**' : '**The fix:**';
        $lang = preg_replace('/[^a-z0-9+#]/i', '', (string)($solution['language'] ?? ''));
        if ($lang === '') $lang = 'text';
        $lines[] = (strpos($fix, "\n") !== false || strpos($fix, '(') !== false)
            ? ("```" . $lang . "\n" . $fix . "\n```")
            : $fix;
    }
    $why = trim((string)($solution['why'] ?? ''));
    if ($why !== '') {
        $lines[] = '';
        $lines[] = '**Why:**';
        $lines[] = $why;
    }

    $body = implode("\n", $lines);
    if (trim($scoreboard) !== '') {
        $body .= "\n" . $scoreboard;
    }
    return normalize_signature_once($body, $signature);
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
    // No JS quiz answer is due, so look for a Spot the Bug topic that is old
    // enough to reveal. Same hourly cron covers both.
    $spotHeaders = array(
        'Content-Type: application/json',
        'Api-Key: ' . KONVO_API_KEY,
        'Api-Username: BayMax',
    );
    $minAge = $force ? 0 : (24 * 60 * 60);
    $spot = jsqa_find_spot_target($spotHeaders, $minAge, $topicFilter);

    if (!is_array($spot)) {
        out_json(200, array(
            'ok' => true,
            'ignored' => true,
            'reason' => 'nothing_due_for_reveal',
            'pending_count' => count($pending),
            'force' => $force,
            'topic_filter' => $topicFilter,
        ));
    }

    // Post the answer as the bot that set the challenge.
    $spotBot = (string)$spot['bot_username'];
    $spotHeaders[2] = 'Api-Username: ' . $spotBot;

    $spotKind = jsqa_challenge_kind((string)$spot['title']);
    $solution = jsqa_solve_spot_the_bug((string)$spot['op_text'], $spotKind);
    if (!is_array($solution) || trim((string)($solution['bug'] ?? '')) === '') {
        out_json(502, array(
            'ok' => false,
            'error' => 'Could not work out the challenge answer.',
            'topic_id' => (int)$spot['topic_id'],
        ));
    }

    $spotAttempts = jsqa_collect_human_attempts($spot['body'], (int)$spot['op_post_number']);
    $spotCorrect = trim((string)$solution['bug']) . "\n" . trim((string)($solution['fix'] ?? '')) . "\n" . trim((string)($solution['why'] ?? ''));
    $spotGrades = $spotAttempts !== array()
        ? jsqa_grade_attempts((string)$spot['op_text'], $spotCorrect, $spotAttempts, $spotKind)
        : array();
    $spotBoardData = jsqa_build_scoreboard($spotAttempts, $spotGrades);
    $spotScoreboard = (string)$spotBoardData['markdown'];
    // Award the win and show the running leaderboard. Persist only on a real
    // post, so a dry run previews the same numbers without banking them.
    $spotLb = jsqa_load_leaderboard();
    $spotLb = jsqa_award_first($spotLb, (string)$spotBoardData['first'], (int)$spot['topic_id'], !$dryRun);
    $spotLbMd = jsqa_render_leaderboard($spotLb, 10);
    if ($spotLbMd !== '') {
        $spotScoreboard .= "\n" . $spotLbMd;
    }

    $spotSigSeed = strtolower($spotBot . '|' . (int)$spot['topic_id'] . '|spot-answer');
    $spotSignature = function_exists('konvo_signature_base_name') ? konvo_signature_base_name($spotBot) : $spotBot;
    if (function_exists('konvo_signature_with_optional_emoji')) {
        $spotSignature = konvo_signature_with_optional_emoji($spotSignature, $spotSigSeed);
    }

    $spotRaw = jsqa_build_spot_answer_raw($solution, $spotScoreboard, $spotSignature, $spotKind);

    if ($dryRun) {
        out_json(200, array(
            'ok' => true,
            'dry_run' => true,
            'action' => 'would_post_challenge_answer',
            'challenge_kind' => $spotKind,
            'topic_id' => (int)$spot['topic_id'],
            'topic_title' => (string)$spot['title'],
            'bot_username' => $spotBot,
            'human_attempts' => count($spotAttempts),
            'graded' => count($spotGrades),
            'leaderboard_unreadable' => !empty($spotLb['_unreadable']),
            'raw_preview' => $spotRaw,
        ));
    }

    $spotPost = jsqa_call_api(rtrim(KONVO_BASE_URL, '/') . '/posts.json', $spotHeaders, array(
        'topic_id' => (int)$spot['topic_id'],
        'raw' => $spotRaw,
        'reply_to_post_number' => (int)$spot['op_post_number'],
    ));
    if (!$spotPost['ok'] || !is_array($spotPost['body'])) {
        out_json(502, array(
            'ok' => false,
            'error' => 'Failed to post Spot the Bug answer.',
            'status' => (int)($spotPost['status'] ?? 0),
            'raw' => (string)($spotPost['raw'] ?? ''),
        ));
    }

    $spotPostNumber = (int)($spotPost['body']['post_number'] ?? 0);
    out_json(200, array(
        'ok' => true,
        'action' => 'posted_challenge_answer',
        'challenge_kind' => $spotKind,
        'topic_id' => (int)$spot['topic_id'],
        'topic_url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . (int)$spot['topic_id'] . '/' . $spotPostNumber,
        'bot_username' => $spotBot,
        'human_attempts' => count($spotAttempts),
        'graded' => count($spotGrades),
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

// Grade everyone who guessed before revealing, so the post can name who got it
// right, who was first, and what the wrong answers missed.
$attempts = jsqa_collect_human_attempts($topicRes['body'], $quizPostNumber);
$correctAnswerText = 'Option ' . (int)($item['answer_index'] ?? 1)
    . ' - ' . trim((string)($item['answer_option'] ?? ''))
    . "\n" . trim((string)($item['explanation'] ?? ''));
$challengeText = trim((string)($item['quiz_title'] ?? ''));
$quizPosts = isset($topicRes['body']['post_stream']['posts']) && is_array($topicRes['body']['post_stream']['posts'])
    ? $topicRes['body']['post_stream']['posts']
    : array();
if (isset($quizPosts[0]) && is_array($quizPosts[0])) {
    $challengeText = jsqa_post_text($quizPosts[0]);
}
$grades = $attempts !== array() ? jsqa_grade_attempts($challengeText, $correctAnswerText, $attempts) : array();
$boardData = jsqa_build_scoreboard($attempts, $grades);
$scoreboard = (string)$boardData['markdown'];
$leaderboard = jsqa_load_leaderboard();
$leaderboard = jsqa_award_first($leaderboard, (string)$boardData['first'], $topicId, !$dryRun);
$leaderboardMd = jsqa_render_leaderboard($leaderboard, 10);
if ($leaderboardMd !== '') {
    $scoreboard .= "\n" . $leaderboardMd;
}

$answerRaw = jsqa_build_answer_raw($item, $signature, $scoreboard);
if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_js_quiz_answer',
        'topic_id' => $topicId,
        'bot_username' => $botUsername,
        'reply_to_post_number' => $quizPostNumber,
        'human_attempts' => count($attempts),
        'graded' => count($grades),
        'leaderboard_unreadable' => !empty($leaderboard['_unreadable']),
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
