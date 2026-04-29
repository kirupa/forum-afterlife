<?php

/*
 * Browser-callable casual topic poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_casual_topic_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_casual_topic_worker.php?key=YOUR_SECRET&dry_run=1
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/konvo_soul_helper.php';
require_once __DIR__ . '/konvo_signature_helper.php';
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
if (!defined('KONVO_TALK_CATEGORY_ID')) define('KONVO_TALK_CATEGORY_ID', 34);
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_GAMING_CATEGORY_ID')) define('KONVO_GAMING_CATEGORY_ID', 115);
if (!defined('KONVO_DESIGN_CATEGORY_ID')) define('KONVO_DESIGN_CATEGORY_ID', 114);

$bots = array(
    array('username' => 'BayMax', 'name' => 'BayMax', 'soul_key' => 'baymax', 'soul_fallback' => 'You are BayMax. Write naturally, concise, and human.'),
    array('username' => 'vaultboy', 'name' => 'VaultBoy', 'soul_key' => 'vaultboy', 'soul_fallback' => 'You are VaultBoy. Casual, playful, and game-obsessed.'),
    array('username' => 'MechaPrime', 'name' => 'MechaPrime', 'soul_key' => 'mechaprime', 'soul_fallback' => 'You are MechaPrime. Write naturally, concise, and human.'),
    array('username' => 'yoshiii', 'name' => 'Yoshiii', 'soul_key' => 'yoshiii', 'soul_fallback' => 'You are Yoshiii. Write naturally, concise, and human.'),
    array('username' => 'bobamilk', 'name' => 'BobaMilk', 'soul_key' => 'bobamilk', 'soul_fallback' => 'You are BobaMilk. Write naturally, concise, and human.'),
    array('username' => 'wafflefries', 'name' => 'WaffleFries', 'soul_key' => 'wafflefries', 'soul_fallback' => 'You are WaffleFries. Write naturally, concise, and human.'),
    array('username' => 'quelly', 'name' => 'Quelly', 'soul_key' => 'quelly', 'soul_fallback' => 'You are Quelly. Write naturally, concise, and human.'),
    array('username' => 'sora', 'name' => 'Sora', 'soul_key' => 'sora', 'soul_fallback' => 'You are Sora. Write naturally, concise, and human.'),
    array('username' => 'sarah_connor', 'name' => 'Sarah', 'soul_key' => 'sarah_connor', 'soul_fallback' => 'You are Sarah Connor. Write naturally, concise, and human.'),
    array('username' => 'ellen1979', 'name' => 'Ellen', 'soul_key' => 'ellen1979', 'soul_fallback' => 'You are Ellen1979. Write naturally, concise, and human.'),
    array('username' => 'arthurdent', 'name' => 'Arthur', 'soul_key' => 'arthurdent', 'soul_fallback' => 'You are ArthurDent. Write naturally, concise, and human.'),
    array('username' => 'hariseldon', 'name' => 'Hari', 'soul_key' => 'hariseldon', 'soul_fallback' => 'You are HariSeldon. Write naturally, concise, and human.'),
);

function casual_out(int $status, array $data): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $where = basename((string)$e->getFile()) . ':' . (int)$e->getLine();
    $msg = trim((string)$e->getMessage());
    if ($msg === '') $msg = 'Unhandled exception';
    casual_out(500, array('ok' => false, 'error' => 'Casual worker exception: ' . $msg . ' [' . $where . ']'));
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) return;
    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array((int)($err['type'] ?? 0), $fatal, true)) return;
    if (headers_sent()) return;

    $msg = trim((string)($err['message'] ?? 'Fatal error'));
    $file = basename((string)($err['file'] ?? 'unknown'));
    $line = (int)($err['line'] ?? 0);
    casual_out(500, array('ok' => false, 'error' => 'Casual worker fatal: ' . $msg . ' [' . $file . ':' . $line . ']'));
});

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

function casual_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_topic_recent.json';
}

function casual_load_recent_topics(): array
{
    $path = casual_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_save_recent_topics(array $items): void
{
    $clean = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        $angle = trim((string)($item['plan_angle'] ?? ''));
        $ts = (int)($item['ts'] ?? time());
        if ($title === '') continue;
        $clean[] = array(
            'title' => $title,
            'plan_angle' => $angle,
            'ts' => $ts,
        );
    }

    usort($clean, static function ($a, $b) {
        return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
    });

    $clean = array_slice($clean, 0, 24);
    @file_put_contents(casual_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_remember_topic(string $title, string $planAngle): void
{
    $items = casual_load_recent_topics();
    array_unshift($items, array(
        'title' => trim($title),
        'plan_angle' => trim($planAngle),
        'ts' => time(),
    ));
    casual_save_recent_topics($items);
}

function casual_recent_hint_lines(array $recent): string
{
    $lines = array();
    $max = min(12, count($recent));
    for ($i = 0; $i < $max; $i++) {
        $item = $recent[$i] ?? null;
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        $angle = trim((string)($item['plan_angle'] ?? ''));
        if ($title === '') continue;
        $line = '- ' . $title;
        if ($angle !== '') {
            $line .= ' (angle: ' . $angle . ')';
        }
        $lines[] = $line;
    }
    return $lines === array() ? '(none)' : implode("\n", $lines);
}

function casual_pick_bot(array $bots): array
{
    if ($bots === array()) {
        return array('username' => 'BayMax', 'name' => 'BayMax', 'soul_key' => 'baymax', 'soul_fallback' => 'Write naturally, concise, and human.');
    }
    shuffle($bots);
    return $bots[0];
}

function casual_find_bot(array $bots, string $username): ?array
{
    $u = strtolower(trim($username));
    foreach ($bots as $bot) {
        $bu = strtolower(trim((string)($bot['username'] ?? '')));
        if ($bu !== '' && $bu === $u) return $bot;
    }
    return null;
}

function casual_is_gaming_topic(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    if (!preg_match('/\b(video game|gaming|gameplay|trailer|clip|dlc|patch|xbox|playstation|ps5|ps4|nintendo|switch|steam|epic games|riot games|blizzard|ubisoft|capcom|fromsoftware|fortnite|minecraft|valorant|league of legends|rpg|fps|mmo|easter egg)\b/i', $t)) {
        return false;
    }
    // Keep obvious entertainment/movie chatter out of gaming category.
    if (preg_match('/\b(movie|film|tv show|television|box office|actor|actress|hollywood)\b/i', $t) && !preg_match('/\b(video game|gameplay|console|pc game)\b/i', $t)) {
        return false;
    }
    return true;
}

function casual_is_design_topic(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;

    $physical = (bool)preg_match('/\b(architecture|architect|building|house|home|interior|pavilion|tower|skyscraper|museum|gallery|facade|façade|renovation|landscape architecture|urban planning|studio|residence)\b/i', $t);
    $uiux = (bool)preg_match('/\b(ui|ux|user interface|user experience|interaction design|visual design|design system|wireframe|prototype|figma|typography|color palette)\b/i', $t);
    if (!$physical && !$uiux) return false;
    if (!$physical && preg_match('/\b(system design|api design|database design|software architecture|computer architecture|backend architecture|technical design)\b/i', $t)) {
        return false;
    }
    return true;
}

function casual_title_looks_question_like(string $title): bool
{
    $t = strtolower(trim($title));
    if ($t === '') return false;
    if (str_contains($t, '?')) return true;
    if (preg_match('/^(what|why|how|when|where|who|which|is|are|can|could|should|would|do|does|did|will|have|has|had)\b/i', $t)) return true;
    return false;
}

function casual_ensure_question_mark_title(string $title): string
{
    $title = trim($title);
    if ($title === '') return $title;
    if (!casual_title_looks_question_like($title)) return $title;
    $title = preg_replace('/[.!:;,\-]+$/', '', $title) ?? $title;
    $title = rtrim($title);
    if (!str_ends_with($title, '?')) $title .= '?';
    return $title;
}

function casual_normalize_title(string $title): string
{
    $title = trim(strip_tags($title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    $title = trim($title, " \t\n\r\0\x0B\"'`");
    if ($title === '') return '';
    if (strlen($title) > 88) {
        $short = trim((string)substr($title, 0, 88));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 28) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $title = $short;
    }
    $title = preg_replace('/[:;,\.\-]+$/', '', $title) ?? $title;
    $title = trim($title);
    return casual_ensure_question_mark_title($title);
}

function casual_normalize_signature(string $text, string $signature): string
{
    $candidates = function_exists('konvo_signature_name_candidates')
        ? konvo_signature_name_candidates($signature)
        : array($signature);
    if (!is_array($candidates) || count($candidates) === 0) $candidates = array($signature);

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
        $body = preg_replace('/\s+' . preg_quote((string)$candidate, '/') . '\\.?$/i', '', (string)$body) ?? $body;
    }
    $body = trim((string)$body);
    if ($body === '') return '';
    return $body;
}

function casual_quirky_media_urls(): array
{
    return array(
        'https://media.giphy.com/media/ICOgUNjpvO0PC/giphy.gif',
        'https://media.giphy.com/media/5VKbvrjxpVJCM/giphy.gif',
        'https://media.giphy.com/media/13CoXDiaCcCoyk/giphy.gif',
        'https://media.giphy.com/media/l0HlBO7eyXzSZkJri/giphy.gif',
        'https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif',
        'https://media.giphy.com/media/26ufdipQqU2lhNA4g/giphy.gif',
        'https://media.giphy.com/media/3o7aCTfyhYawdOXcFW/giphy.gif',
        'https://media.giphy.com/media/l3q2K5jinAlChoCLS/giphy.gif',
    );
}

function casual_pick_quirky_media_url(string $seed): string
{
    $urls = casual_quirky_media_urls();
    if ($urls === array()) return '';
    $hash = abs((int)crc32(strtolower(trim($seed))));
    return (string)$urls[$hash % count($urls)];
}

function casual_append_quirky_media_before_signature(string $raw, string $signature, string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('/^https?:\/\/\S+$/i', $url)) {
        return casual_normalize_signature($raw, $signature);
    }
    $norm = casual_normalize_signature($raw, $signature);
    if (!preg_match('/https?:\/\/\S+/i', $norm)) {
        $norm = trim($norm) . "\n\n" . $url;
    }
    return casual_normalize_signature($norm, $signature);
}

function casual_normalize_body(string $raw, string $signature): string
{
    $raw = str_replace(array("\r\n", "\r"), "\n", (string)$raw);
    $raw = trim($raw);
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;
    if ($raw === '') return '';
    return casual_normalize_signature($raw, $signature);
}

function casual_has_controversial_signals(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    $patterns = array(
        '/\b(politic|election|democrat|republican|senate|president|trump|biden|left wing|right wing)\b/i',
        '/\b(war|genocide|military conflict|terror|terrorism|weapon)\b/i',
        '/\b(religion|god|church|islam|christian|hindu|jewish|bible|quran)\b/i',
        '/\b(abortion|immigration|racism|sexism|sexual assault|violence|crime)\b/i',
        '/\b(vaccine|pandemic|covid|disease outbreak|public health emergency)\b/i',
        '/\b(stock pick|crypto pump|betting|gambling tip)\b/i',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

function casual_looks_too_technical(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(javascript|typescript|css|html|react|vue|angular|api|database|backend|frontend|docker|kubernetes|ci\/cd|compiler|runtime|machine learning|neural network|prompt engineering)\b/i', $t);
}

function casual_validate_generated_topic(string $title, string $raw): array
{
    $title = trim($title);
    $raw = trim($raw);

    if ($title === '' || strlen($title) < 8) {
        return array('ok' => false, 'error' => 'title too short');
    }
    if (strlen($title) > 88) {
        return array('ok' => false, 'error' => 'title too long');
    }
    if ($raw === '' || strlen($raw) < 40) {
        return array('ok' => false, 'error' => 'body too short');
    }
    if (strlen($raw) > 520) {
        return array('ok' => false, 'error' => 'body too long');
    }
    if (casual_has_controversial_signals($title . "\n" . $raw)) {
        return array('ok' => false, 'error' => 'topic looked controversial');
    }
    if (casual_looks_too_technical($title . "\n" . $raw)) {
        return array('ok' => false, 'error' => 'topic looked too technical');
    }
    if (strpos($raw, '```') !== false) {
        return array('ok' => false, 'error' => 'code block not expected for casual topic');
    }
    return array('ok' => true);
}

function casual_extract_json_object(string $content): array
{
    $content = trim($content);
    if ($content === '') return array();

    if ($content[0] === '{') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return array();

    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_openai_json(array $payload): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'json' => array(), 'raw' => '');
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . KONVO_OPENAI_API_KEY,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return array('ok' => false, 'status' => $status, 'error' => $err, 'json' => array(), 'raw' => '');
    }

    $decoded = json_decode((string)$body, true);
    return array(
        'ok' => ($status >= 200 && $status < 300 && is_array($decoded)),
        'status' => $status,
        'error' => '',
        'json' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

function casual_pick_category_with_llm(string $title, string $raw, array $bot = array(), array $plan = array()): array
{
    $fallback = array(
        'ok' => false,
        'category_key' => 'talk',
        'category_id' => (int)KONVO_TALK_CATEGORY_ID,
        'reason' => 'category_llm_unavailable_fallback_talk',
        'confidence' => 0.0,
    );
    if (KONVO_OPENAI_API_KEY === '') {
        return $fallback;
    }

    $botName = trim((string)($bot['name'] ?? 'BayMax'));
    $planMood = trim((string)($plan['mood'] ?? ''));
    $planAngle = trim((string)($plan['angle'] ?? ''));
    $planIntent = trim((string)($plan['posting_intent'] ?? ''));

    $system = 'Classify this forum topic into one category and return JSON only. '
        . 'Schema: {"category":"talk|web_dev|design|gaming","reason":"...","confidence":0.0}. '
        . 'Category rules: '
        . 'talk = general everyday conversation and broad-interest non-technical discussion. '
        . 'web_dev = programming/software engineering/web development/technical architecture in software. '
        . 'design = UI/UX/visual design OR physical architecture/interior design. '
        . 'gaming = video games/gameplay/trailers/game culture. '
        . 'Important: software contexts using words like build/building/design/architecture/system belong to web_dev, not design. '
        . 'Pick exactly one category.';
    $user = "Topic title:\n{$title}\n\n"
        . "Topic body:\n{$raw}\n\n"
        . "Bot: {$botName}\n"
        . "Plan mood: {$planMood}\n"
        . "Plan angle: {$planAngle}\n"
        . "Plan intent: {$planIntent}\n\n"
        . "Return JSON now.";

    $payload = array(
        'model' => konvo_model_for_task('topic_category'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
    );

    $res = casual_openai_json($payload);
    if (!$res['ok']) {
        return $fallback;
    }
    $json = $res['json'];
    $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return $fallback;
    }
    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return $fallback;
    }

    $key = strtolower(trim((string)($obj['category'] ?? '')));
    $map = array(
        'talk' => (int)KONVO_TALK_CATEGORY_ID,
        'general' => (int)KONVO_TALK_CATEGORY_ID,
        'web_dev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'webdev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'web-dev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'programming' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'technical' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'design' => (int)KONVO_DESIGN_CATEGORY_ID,
        'gaming' => (int)KONVO_GAMING_CATEGORY_ID,
        'games' => (int)KONVO_GAMING_CATEGORY_ID,
    );
    if (!isset($map[$key])) {
        return $fallback;
    }

    $categoryId = (int)$map[$key];
    $normalizedKey = 'talk';
    if ($categoryId === (int)KONVO_WEBDEV_CATEGORY_ID) $normalizedKey = 'web_dev';
    if ($categoryId === (int)KONVO_DESIGN_CATEGORY_ID) $normalizedKey = 'design';
    if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID) $normalizedKey = 'gaming';

    $confidence = (float)($obj['confidence'] ?? 0.0);
    if ($confidence < 0.0) $confidence = 0.0;
    if ($confidence > 1.0) $confidence = 1.0;
    $reason = trim((string)($obj['reason'] ?? ''));
    if ($reason === '') $reason = 'llm_category_decision';

    return array(
        'ok' => true,
        'category_key' => $normalizedKey,
        'category_id' => $categoryId,
        'reason' => $reason,
        'confidence' => $confidence,
    );
}

function casual_generate_with_llm(array $bot, string $signature, array $recent, bool $strict): array
{
    $botName = trim((string)($bot['name'] ?? 'BayMax'));
    $soulKey = trim((string)($bot['soul_key'] ?? strtolower($botName)));
    $soulFallback = trim((string)($bot['soul_fallback'] ?? 'Write naturally, concise, and human.'));
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, $soulFallback)
    );
    $recentHints = casual_recent_hint_lines($recent);

    $strictLine = $strict
        ? 'Your previous draft was too close to a recent topic, too technical, or not casual enough. Regenerate with a different angle and friendlier everyday vibe.'
        : 'Generate the best first draft now.';

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'You generate a single casual, friendly, non-controversial forum topic starter for humans. '
        . 'Return ONLY JSON with this schema: '
        . '{"plan_mood":"...","plan_angle":"...","plan_posting_intent":"...","title":"...","raw":"..."}. '
        . 'Rules: topic must be everyday and broad-interest, not technical/coding, not newsy, not political, not religious, not divisive, not tragic. '
        . 'Use natural human language, short and warm. '
        . 'Title: 5-12 words, complete thought, concise, no colon, no clickbait. '
        . 'Body: 2-4 short sentences max, conversational, optionally a light prompt. '
        . 'If asking a question, address the reader as "you". '
        . 'No links, no hashtags, no code blocks. '
        . 'Do not sign the post; Discourse already shows the author username.';

    $user = "Generate one new casual forum topic now.\n"
        . "Recent topics to avoid repeating:\n{$recentHints}\n\n"
        . $strictLine;

    $payload = array(
        'model' => konvo_model_for_task('casual_topic'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.9,
    );

    $res = casual_openai_json($payload);
    if (!$res['ok']) {
        return array('ok' => false, 'error' => 'OpenAI request failed', 'detail' => $res['error'], 'status' => $res['status']);
    }

    $json = $res['json'];
    $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return array('ok' => false, 'error' => 'Model returned empty content');
    }

    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return array('ok' => false, 'error' => 'Model returned non-JSON content', 'raw' => $content);
    }

    $title = casual_normalize_title((string)($obj['title'] ?? ''));
    $raw = casual_normalize_body((string)($obj['raw'] ?? ''), $signature);
    $planMood = trim((string)($obj['plan_mood'] ?? ''));
    $planAngle = trim((string)($obj['plan_angle'] ?? ''));
    $planIntent = trim((string)($obj['plan_posting_intent'] ?? ''));

    if ($title === '' || $raw === '') {
        return array('ok' => false, 'error' => 'Model JSON missing title/raw', 'parsed' => $obj);
    }

    $valid = casual_validate_generated_topic($title, $raw);
    if (!$valid['ok']) {
        return array('ok' => false, 'error' => (string)($valid['error'] ?? 'validation failed'), 'title' => $title, 'raw' => $raw);
    }

    return array(
        'ok' => true,
        'title' => $title,
        'raw' => $raw,
        'plan' => array(
            'mood' => $planMood,
            'angle' => $planAngle,
            'posting_intent' => $planIntent,
        ),
    );
}

function casual_post_topic(string $botUsername, string $title, string $raw, int $categoryId): array
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
    casual_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !safe_hash_equals(KONVO_SECRET, $providedKey)) {
    casual_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    casual_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_OPENAI_API_KEY === '') {
    casual_out(500, array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$bot = casual_pick_bot($bots);
$signatureSeed = strtolower((string)($bot['username'] ?? 'baymax') . '|casual-topic|' . date('Y-m-d-H'));
$signature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BayMax'), $signatureSeed)
    : (string)($bot['name'] ?? 'BayMax');
$recent = casual_load_recent_topics();

$attempts = array();
$generated = null;
for ($i = 0; $i < 3; $i++) {
    $strict = $i > 0;
    $res = casual_generate_with_llm($bot, $signature, $recent, $strict);
    $attempts[] = $res;
    if (!empty($res['ok'])) {
        $generated = $res;
        break;
    }
}

if (!is_array($generated) || empty($generated['ok'])) {
    $errors = array();
    foreach ($attempts as $a) {
        $errors[] = isset($a['error']) ? (string)$a['error'] : 'unknown generation failure';
    }
    casual_out(502, array(
        'ok' => false,
        'error' => 'Failed to generate casual topic with model.',
        'attempt_errors' => $errors,
    ));
}

$title = (string)$generated['title'];
$raw = (string)$generated['raw'];
$plan = isset($generated['plan']) && is_array($generated['plan']) ? $generated['plan'] : array();
$categoryDecision = casual_pick_category_with_llm($title, $raw, $bot, $plan);
$categoryId = (int)($categoryDecision['category_id'] ?? (int)KONVO_TALK_CATEGORY_ID);
$gamingDetected = ($categoryId === (int)KONVO_GAMING_CATEGORY_ID);
if ($gamingDetected) {
    if (strtolower((string)($bot['username'] ?? '')) !== 'vaultboy') {
        $vaultboyBot = casual_find_bot($bots, 'vaultboy');
        if (is_array($vaultboyBot)) {
            $bot = $vaultboyBot;
            $signatureSeed = strtolower((string)($bot['username'] ?? 'vaultboy') . '|casual-topic|' . date('Y-m-d-H'));
            $signature = function_exists('konvo_signature_with_optional_emoji')
                ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'VaultBoy'), $signatureSeed)
                : (string)($bot['name'] ?? 'VaultBoy');
            $vgRes = casual_generate_with_llm($bot, $signature, $recent, true);
            if (!empty($vgRes['ok'])) {
                $title = (string)$vgRes['title'];
                $raw = (string)$vgRes['raw'];
                $plan = isset($vgRes['plan']) && is_array($vgRes['plan']) ? $vgRes['plan'] : $plan;
                $categoryDecision = casual_pick_category_with_llm($title, $raw, $bot, $plan);
                $categoryId = (int)($categoryDecision['category_id'] ?? (int)KONVO_TALK_CATEGORY_ID);
                $gamingDetected = ($categoryId === (int)KONVO_GAMING_CATEGORY_ID);
            } else {
                $raw = casual_normalize_signature($raw, $signature);
            }
        }
    }
}
$quirkySeed = abs((int)crc32(strtolower((string)($bot['username'] ?? 'baymax') . '|casual-quirky|' . $title . '|' . substr($raw, 0, 180))));
$quirkyMode = (($quirkySeed % 100) < 14);
$quirkyMediaUrl = $quirkyMode ? casual_pick_quirky_media_url((string)($bot['username'] ?? 'baymax') . '|' . $title . '|' . $raw) : '';
if ($quirkyMediaUrl !== '') {
    $raw = casual_append_quirky_media_before_signature($raw, $signature, $quirkyMediaUrl);
}

if ($dryRun) {
    casual_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_casual_topic',
        'bot' => $bot,
        'plan' => $plan,
        'topic' => array(
            'title' => $title,
            'category_id' => $categoryId,
            'raw_preview' => $raw,
            'gaming_detected' => $gamingDetected,
            'category_decision' => $categoryDecision,
        ),
        'quirky_media' => array(
            'enabled' => $quirkyMode,
            'url' => $quirkyMediaUrl,
        ),
        'recent_count' => count($recent),
    ));
}

$post = casual_post_topic((string)($bot['username'] ?? 'BayMax'), $title, $raw, $categoryId);
if (!$post['ok']) {
    casual_out(500, array(
        'ok' => false,
        'error' => 'Failed to post casual topic.',
        'status' => $post['status'],
        'curl_error' => $post['error'],
        'response' => $post['body'],
        'raw' => $post['raw'],
    ));
}

$topicId = (int)($post['body']['topic_id'] ?? 0);
$postNumber = (int)($post['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
casual_remember_topic($title, (string)($plan['angle'] ?? ''));

casual_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_casual_topic',
    'topic_url' => $topicUrl,
    'bot' => $bot,
    'plan' => $plan,
    'topic' => array(
        'title' => $title,
        'category_id' => $categoryId,
        'gaming_detected' => $gamingDetected,
        'category_decision' => $categoryDecision,
    ),
    'quirky_media' => array(
        'enabled' => $quirkyMode,
        'url' => $quirkyMediaUrl,
    ),
));
