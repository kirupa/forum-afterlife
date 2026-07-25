<?php

/*
 * Daily engagement digest.
 *
 * Reports bot posts that HUMANS engaged with in the last window - either by
 * replying or by hitting the heart - and asks the model why each person
 * probably did it. Delivered as a Discourse PM, which Discourse then emails
 * out through its own authenticated SMTP.
 *
 * Test (renders the digest, sends nothing):
 * https://www.kirupa.com/konvo_engagement_digest_worker.php?key=YOUR_SECRET&dry_run=1
 *
 * Live:
 * https://www.kirupa.com/konvo_engagement_digest_worker.php?key=YOUR_SECRET
 *
 * Suggested cron (once a day):
 * 0 8 * * * /usr/bin/curl -fsS "https://www.kirupa.com/konvo_engagement_digest_worker.php?key=YOUR_SECRET"
 */

declare(strict_types=1);

require_once __DIR__ . '/konvo_anthropic_client.php';

$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'claude-sonnet-5';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_DISCOURSE_API_KEY')) define('KONVO_DISCOURSE_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));

// The digest is delivered as a Discourse private message. Discourse emails the
// notification through its own configured, authenticated SMTP, which sidesteps
// the web host's SPF problem (kirupa.com's SPF authorises Mailchimp only, so
// mail() from this server is silently dropped by Gmail).
if (!defined('KONVO_DIGEST_PM_TO')) {
    $digestTo = trim((string)getenv('KONVO_DIGEST_PM_TO'));
    define('KONVO_DIGEST_PM_TO', $digestTo !== '' ? $digestTo : 'kirupa');
}
if (!defined('KONVO_DIGEST_PM_FROM')) define('KONVO_DIGEST_PM_FROM', 'BayMax');

// Discourse user_action types we care about.
if (!defined('KONVO_ACTION_WAS_LIKED')) define('KONVO_ACTION_WAS_LIKED', 2);
if (!defined('KONVO_ACTION_RESPONSE')) define('KONVO_ACTION_RESPONSE', 6);

function digest_out(int $status, array $data): void
{
    if (function_exists('http_response_code')) http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function digest_safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    for ($i = 0, $len = strlen($a); $i < $len; $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
    return $res === 0;
}

function digest_bot_usernames(): array
{
    return array('baymax', 'kirupabot', 'vaultboy', 'mechaprime', 'yoshiii', 'bobamilk', 'wafflefries', 'quelly', 'sora', 'sarah_connor', 'ellen1979', 'arthurdent', 'hariseldon');
}

function digest_is_bot(string $username): bool
{
    return in_array(strtolower(trim($username)), digest_bot_usernames(), true);
}

function digest_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/engagement_digest_state.json';
}

function digest_load_state(): array
{
    $p = digest_state_path();
    if (!is_file($p)) return array();
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') return array();
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}

function digest_save_state(array $state): void
{
    // Keep the reported-key list bounded so the file cannot grow without limit.
    if (isset($state['reported']) && is_array($state['reported']) && count($state['reported']) > 800) {
        $state['reported'] = array_slice($state['reported'], -800, 800);
    }
    @file_put_contents(digest_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function digest_fetch_json(string $url): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
            'Api-Username: kirupa',
        ),
        CURLOPT_USERAGENT => 'konvo-engagement-digest/1.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) return null;
    $d = json_decode((string)$body, true);
    return is_array($d) ? $d : null;
}

function digest_clean_excerpt(string $html): string
{
    $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = strip_tags($t);
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;
    return trim((string)$t);
}

/**
 * Collect human engagement (likes + replies) on bot posts inside the window.
 */
function digest_collect(int $sinceTs, array $reportedKeys): array
{
    $items = array();
    foreach (digest_bot_usernames() as $bot) {
        foreach (array(KONVO_ACTION_WAS_LIKED, KONVO_ACTION_RESPONSE) as $filter) {
            $url = rtrim(KONVO_BASE_URL, '/') . '/user_actions.json?username=' . rawurlencode($bot)
                . '&filter=' . (int)$filter . '&offset=0';
            $data = digest_fetch_json($url);
            if (!is_array($data) || !isset($data['user_actions']) || !is_array($data['user_actions'])) continue;

            foreach ($data['user_actions'] as $a) {
                if (!is_array($a)) continue;
                $actor = trim((string)($a['acting_username'] ?? ''));
                if ($actor === '' || digest_is_bot($actor)) continue; // humans only

                $createdAt = trim((string)($a['created_at'] ?? ''));
                $ts = $createdAt !== '' ? (int)strtotime($createdAt) : 0;
                if ($ts <= 0 || $ts < $sinceTs) continue;

                $postId = (int)($a['post_id'] ?? 0);
                $kind = ((int)$filter === KONVO_ACTION_WAS_LIKED) ? 'like' : 'reply';
                $key = $kind . ':' . $postId . ':' . strtolower($actor);
                if (in_array($key, $reportedKeys, true)) continue;
                if (isset($items[$key])) continue;

                $topicId = (int)($a['topic_id'] ?? 0);
                $postNumber = (int)($a['post_number'] ?? 0);
                $items[$key] = array(
                    'key' => $key,
                    'kind' => $kind,
                    'bot' => $bot,
                    'actor' => $actor,
                    'actor_name' => trim((string)($a['acting_name'] ?? '')),
                    'created_at' => $createdAt,
                    'ts' => $ts,
                    'topic_id' => $topicId,
                    'topic_title' => trim((string)($a['title'] ?? '')),
                    'post_number' => $postNumber,
                    'excerpt' => digest_clean_excerpt((string)($a['excerpt'] ?? '')),
                    'url' => rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . max(1, $postNumber),
                );
            }
        }
    }

    $items = array_values($items);
    usort($items, function ($a, $b) { return ((int)$b['ts']) <=> ((int)$a['ts']); });
    return $items;
}

/**
 * One model call for the whole digest: explain why each person engaged.
 * Returns [key => explanation]. Failure is non-fatal - the digest still sends.
 */
function digest_explain(array $items): array
{
    if ($items === array()) return array();

    $lines = array();
    foreach ($items as $i => $it) {
        $what = $it['kind'] === 'like'
            ? 'LIKED the bot post'
            : 'REPLIED in the thread';
        $lines[] = ($i + 1) . ". [{$what}] by @{$it['actor']}\n"
            . "   Thread: {$it['topic_title']}\n"
            . "   Bot: {$it['bot']}\n"
            . "   " . ($it['kind'] === 'like' ? 'Bot post' : 'Their reply') . ": " . mb_substr($it['excerpt'], 0, 320);
    }

    $system = "You analyse engagement on a small, friendly tech/design forum. "
        . "For each numbered item you are given, write one short sentence explaining why that person most likely liked or replied. "
        . "Be concrete and specific to the content: point at what in the post would have prompted it (it was useful, it was funny, it was wrong, it answered their question, it invited a correction, it was relatable). "
        . "Do not flatter, do not pad, do not repeat the post back. If the reason is genuinely unclear, say so plainly rather than inventing one. "
        . "Return ONLY JSON: {\"explanations\": [{\"n\": 1, \"why\": \"...\"}, ...]} with one entry per numbered item.";

    $user = "Items:\n\n" . implode("\n\n", $lines) . "\n\nExplain each one.";

    $res = konvo_anthropic_chat_json(array(
        'model' => konvo_model_for_task('quality_eval'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'max_tokens' => 2048,
        'temperature' => 0.4,
    ), 90);

    if (!($res['ok'] ?? false)) return array();
    $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') return array();

    $obj = json_decode($content, true);
    if (!is_array($obj)) {
        $s = strpos($content, '{');
        $e = strrpos($content, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $obj = json_decode(substr($content, (int)$s, (int)($e - $s + 1)), true);
        }
    }
    if (!is_array($obj) || !isset($obj['explanations']) || !is_array($obj['explanations'])) return array();

    $out = array();
    foreach ($obj['explanations'] as $row) {
        if (!is_array($row)) continue;
        $n = (int)($row['n'] ?? 0);
        $why = trim((string)($row['why'] ?? ''));
        if ($n >= 1 && $n <= count($items) && $why !== '') {
            $out[$items[$n - 1]['key']] = $why;
        }
    }
    return $out;
}

function digest_md_escape(string $s): string
{
    // Keep usernames and quoted post text from being read as markdown.
    return str_replace(array('[', ']', '*', '_', '`'), array('\\[', '\\]', '\\*', '\\_', '\\`'), $s);
}

function digest_build_digest(array $items, array $why, int $sinceTs): array
{
    $likeCount = 0;
    $replyCount = 0;
    foreach ($items as $it) {
        if ($it['kind'] === 'like') $likeCount++; else $replyCount++;
    }

    $windowLabel = date('M j, g:ia', $sinceTs) . ' to ' . date('M j, g:ia', time());
    $title = 'Forum engagement: ' . $replyCount . ' ' . ($replyCount === 1 ? 'reply' : 'replies')
        . ', ' . $likeCount . ' ' . ($likeCount === 1 ? 'like' : 'likes');

    // Group by topic so a thread with several interactions reads as one block.
    $byTopic = array();
    foreach ($items as $it) {
        $byTopic[(int)$it['topic_id']]['title'] = $it['topic_title'];
        $byTopic[(int)$it['topic_id']]['items'][] = $it;
    }

    $md = '*' . $windowLabel . "*\n\n";

    foreach ($byTopic as $topicId => $group) {
        $topicTitle = digest_md_escape((string)($group['title'] ?? 'Untitled'));
        $topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . (int)$topicId;
        $md .= '### [' . $topicTitle . '](' . $topicUrl . ")\n\n";

        foreach ($group['items'] as $it) {
            $verb = $it['kind'] === 'like' ? 'liked' : 'replied to';
            $badge = $it['kind'] === 'like' ? ':heart:' : ':speech_balloon:';
            $md .= '- ' . $badge . ' **@' . digest_md_escape($it['actor']) . ' ' . $verb . ' '
                . digest_md_escape($it['bot']) . '** ([view](' . $it['url'] . "))\n";

            if ($it['kind'] === 'reply' && $it['excerpt'] !== '') {
                $snippet = digest_md_escape(mb_substr($it['excerpt'], 0, 220));
                $md .= '  > ' . $snippet . "\n";
            }

            $explanation = trim((string)($why[$it['key']] ?? ''));
            if ($explanation !== '') {
                $md .= '  *Why: ' . digest_md_escape($explanation) . "*\n";
            }
            $md .= "\n";
        }
    }

    $md .= "\n---\n\nSent by `konvo_engagement_digest_worker.php`. Reply here if you want the format changed.";

    return array('title' => $title, 'markdown' => $md);
}

/**
 * Deliver as a Discourse private message. Discourse then emails the notification
 * through its own authenticated SMTP, so delivery does not depend on this host
 * being listed in kirupa.com's SPF record.
 */
function digest_send_pm(string $toUsername, string $title, string $markdown): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }

    // Newer Discourse expects target_recipients; older builds used target_usernames.
    foreach (array('target_recipients', 'target_usernames') as $recipientField) {
        $payload = array(
            'title' => $title,
            'raw' => $markdown,
            'archetype' => 'private_message',
            $recipientField => $toUsername,
        );

        $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Api-Key: ' . KONVO_DISCOURSE_API_KEY,
                'Api-Username: ' . KONVO_DIGEST_PM_FROM,
            ),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string)$body, true);
        if ($err === '' && $status >= 200 && $status < 300 && is_array($decoded)) {
            $topicId = (int)($decoded['topic_id'] ?? 0);
            return array(
                'ok' => true,
                'error' => '',
                'topic_id' => $topicId,
                'url' => $topicId > 0 ? (rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId) : '',
                'recipient_field' => $recipientField,
            );
        }
        $lastError = $err !== '' ? $err : ('Discourse returned ' . $status . ' ' . substr((string)$body, 0, 200));
    }

    return array('ok' => false, 'error' => $lastError ?? 'PM send failed');
}

// ---------------------------------------------------------------- entry point

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    digest_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !digest_safe_hash_equals(KONVO_SECRET, $providedKey)) {
    digest_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_DISCOURSE_API_KEY === '') {
    digest_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$hours = isset($_GET['hours']) ? max(1, min(168, (int)$_GET['hours'])) : 0;

$state = digest_load_state();
$lastRun = (int)($state['last_run_ts'] ?? 0);
$reported = isset($state['reported']) && is_array($state['reported']) ? $state['reported'] : array();

if ($hours > 0) {
    $sinceTs = time() - ($hours * 3600);
} elseif ($lastRun > 0) {
    // Never look back further than a week, so a long outage cannot produce a huge digest.
    $sinceTs = max($lastRun, time() - (7 * 24 * 3600));
} else {
    $sinceTs = time() - (24 * 3600);
}

$items = digest_collect($sinceTs, $reported);

if ($items === array()) {
    if (!$dryRun) {
        $state['last_run_ts'] = time();
        $state['reported'] = $reported;
        digest_save_state($state);
    }
    digest_out(200, array(
        'ok' => true,
        'sent' => false,
        'reason' => 'No human engagement on bot posts in this window.',
        'window_since' => date('c', $sinceTs),
        'dry_run' => $dryRun,
    ));
}

$why = digest_explain($items);
$digest = digest_build_digest($items, $why, $sinceTs);

if ($dryRun) {
    digest_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'sent' => false,
        'would_pm' => KONVO_DIGEST_PM_TO,
        'from' => KONVO_DIGEST_PM_FROM,
        'title' => $digest['title'],
        'window_since' => date('c', $sinceTs),
        'item_count' => count($items),
        'explained' => count($why),
        'markdown_preview' => $digest['markdown'],
        'items' => $items,
    ));
}

$send = digest_send_pm(KONVO_DIGEST_PM_TO, $digest['title'], $digest['markdown']);

if ($send['ok']) {
    foreach ($items as $it) $reported[] = $it['key'];
    $state['last_run_ts'] = time();
    $state['reported'] = $reported;
    digest_save_state($state);
}

digest_out($send['ok'] ? 200 : 500, array(
    'ok' => (bool)$send['ok'],
    'sent' => (bool)$send['ok'],
    'error' => (string)($send['error'] ?? ''),
    'pm_to' => KONVO_DIGEST_PM_TO,
    'pm_url' => (string)($send['url'] ?? ''),
    'title' => $digest['title'],
    'item_count' => count($items),
    'explained' => count($why),
    'window_since' => date('c', $sinceTs),
));
