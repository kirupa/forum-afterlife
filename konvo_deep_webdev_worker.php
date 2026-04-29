<?php

/*
 * Browser-callable deep webdev question poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_deep_webdev_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_deep_webdev_worker.php?key=YOUR_SECRET&dry_run=1
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/kirupa_article_helper.php';
require_once __DIR__ . '/konvo_signature_helper.php';
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
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
if (!defined('KONVO_CATEGORY_ID')) define('KONVO_CATEGORY_ID', 42);
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);

$bots = array(
    array('username' => 'BayMax', 'name' => 'BayMax'),
    array('username' => 'vaultboy', 'name' => 'VaultBoy'),
    array('username' => 'MechaPrime', 'name' => 'MechaPrime'),
    array('username' => 'yoshiii', 'name' => 'Yoshiii'),
    array('username' => 'bobamilk', 'name' => 'BobaMilk'),
    array('username' => 'wafflefries', 'name' => 'WaffleFries'),
    array('username' => 'quelly', 'name' => 'Quelly'),
    array('username' => 'sora', 'name' => 'Sora'),
    array('username' => 'sarah_connor', 'name' => 'Sarah'),
    array('username' => 'ellen1979', 'name' => 'Ellen'),
    array('username' => 'arthurdent', 'name' => 'Arthur'),
    array('username' => 'hariseldon', 'name' => 'Hari'),
);


function deep_llm_keyword_map()
{
    return array(
        'coding_tracks' => array(
            'javascript runtime behavior',
            'asynchronous control flow',
            'event handling patterns',
            'state management in UI',
            'render performance profiling',
            'memory leak diagnosis',
            'network retry and caching logic',
            'test reliability and flaky test reduction',
            'algorithms in real products',
            'data structures in frontend architecture',
            'system design for web apps',
            'product tradeoffs in developer tooling',
        ),
        'coding_problem_shapes' => array(
            'stale closure updates',
            'inconsistent async ordering',
            'incorrect memoization boundaries',
            'mutable shared reference bugs',
            'pagination and cursor edge cases',
            'cache invalidation race conditions',
            'queue backpressure handling',
            'debounce and throttle correctness',
            'render-loop drift and timing skew',
            'data dedupe and normalization',
            'error boundary and fallback behavior',
            'bundle splitting and dependency bloat',
            'DOM measurement write-read interleaving',
            'optimistic UI reconciliation',
        ),
        'animation_pixelart' => array(
            'sprite sheet frame indexing',
            'delta-time game loop stability',
            'subpixel camera jitter',
            'nearest-neighbor scaling',
            'pixel density and crisp rendering',
            'canvas batching strategy',
            'css steps timing alignment',
            'tilemap scrolling precision',
            'shader vs canvas tradeoffs',
            'frame pacing on 120hz displays',
            'palette swaps and dithering tradeoffs',
            'asset pipeline for pixel art',
        ),
        'concept_tracks' => array(
            'frontend system boundaries',
            'state locality versus global models',
            'API contract design',
            'observability and debugging strategy',
            'performance budgets and guardrails',
            'a11y and usability tradeoffs',
            'architecture decisions under team constraints',
            'product quality versus delivery speed',
            'technical debt prioritization',
            'reliability and incident readiness',
            'algorithmic complexity in production',
            'platform and framework migration decisions',
        ),
        'avoid_motifs' => array(
            'setTimeout vs requestAnimationFrame basics',
            'optional chaining basics',
            'temporal dead zone basics',
            'promise chain undefined basics',
            'var let timer closure basics',
        ),
    );
}

function deep_keywords_take($items, $maxItems = 10)
{
    if (!is_array($items) || $items === array()) return array();
    $copy = array_values(array_unique(array_map('strval', $items)));
    shuffle($copy);
    return array_slice($copy, 0, max(1, (int)$maxItems));
}

function deep_keywords_for_prompt($preferCoding, $animationPixelFocus)
{
    $m = deep_llm_keyword_map();
    $out = array();

    if ($preferCoding) {
        $out = array_merge(
            deep_keywords_take($m['coding_tracks'] ?? array(), 6),
            deep_keywords_take($m['coding_problem_shapes'] ?? array(), 8)
        );
    } else {
        $out = array_merge(
            deep_keywords_take($m['concept_tracks'] ?? array(), 10),
            deep_keywords_take($m['coding_tracks'] ?? array(), 3)
        );
    }

    if ($animationPixelFocus) {
        $out = array_merge($out, deep_keywords_take($m['animation_pixelart'] ?? array(), 6));
    } else {
        $out = array_merge($out, deep_keywords_take($m['animation_pixelart'] ?? array(), 3));
    }

    $out = array_values(array_unique(array_filter(array_map('trim', $out), function ($v) {
        return $v !== '';
    })));
    return $out;
}

function deep_keywords_block_for_prompt($preferCoding, $animationPixelFocus)
{
    $keywords = deep_keywords_for_prompt($preferCoding, $animationPixelFocus);
    if ($keywords === array()) return '(none)';
    return implode("\n- ", array_merge(array(''), $keywords));
}

function deep_avoid_motifs_block()
{
    $m = deep_llm_keyword_map();
    $avoid = isset($m['avoid_motifs']) && is_array($m['avoid_motifs']) ? $m['avoid_motifs'] : array();
    $avoid = array_values(array_filter(array_map('trim', $avoid), function ($v) {
        return $v !== '';
    }));
    if ($avoid === array()) return '(none)';
    return implode(', ', $avoid);
}
function recent_questions_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/deep_question_recent.json';
}

function load_recent_questions()
{
    $path = recent_questions_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function save_recent_questions($items)
{
    if (!is_array($items)) return;
    $clean = array();
    foreach ($items as $v) {
        $s = strtolower(trim((string)$v));
        if ($s === '') continue;
        if (!in_array($s, $clean, true)) $clean[] = $s;
    }
    $clean = array_slice($clean, 0, 48);
    @file_put_contents(recent_questions_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function remember_question_title($title)
{
    $recent = load_recent_questions();
    $t = strtolower(trim((string)$title));
    if ($t === '') return;
    array_unshift($recent, $t);
    save_recent_questions($recent);
}

function recent_families_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/deep_question_recent_families.json';
}

function load_recent_families()
{
    $path = recent_families_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function save_recent_families($items)
{
    if (!is_array($items)) return;
    $clean = array();
    foreach ($items as $v) {
        $s = strtolower(trim((string)$v));
        if ($s === '') continue;
        if (!in_array($s, $clean, true)) $clean[] = $s;
    }
    $clean = array_slice($clean, 0, 24);
    @file_put_contents(recent_families_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function question_family_key($title, $raw, $language = '')
{
    $blob = strtolower(trim((string)$title . "\n" . (string)$raw . "\n" . (string)$language));
    if ($blob === '') return 'general';

    if (preg_match('/\b(pixel art|pixelart|sprite|spritesheet|tilemap|parallax|easing|tween|game loop|delta time|nearest neighbor|dither|dithering|palette quantization|canvas 2d|webgl|shader)\b/i', $blob)) {
        return 'animation_pixelart';
    }
    if (preg_match('/\b(event loop|microtask|macrotask|settimeout|queueMicrotask|promise\.then)\b/i', $blob)) {
        return 'js_event_loop';
    }
    if (preg_match('/\b(async|await|promise|debounce|throttle|race condition|cancellation)\b/i', $blob)) {
        return 'js_async_flow';
    }
    if (preg_match('/\b(closure|scope|hoist|var|let|const|this binding)\b/i', $blob)) {
        return 'js_scope_runtime';
    }
    if (preg_match('/\b(array|map|filter|reduce|sort|destructuring|spread|object)\b/i', $blob)) {
        return 'js_data_ops';
    }
    if (preg_match('/\b(addEventListener|click|form|button|input|label|aria|accessibility|screen reader|dom)\b/i', $blob)) {
        return 'dom_accessibility';
    }
    if (preg_match('/\b(css|specificity|flex|grid|container quer(?:y|ies)|media quer(?:y|ies)|animation|layout)\b/i', $blob)) {
        return 'css_layout';
    }
    if (preg_match('/\b(hydration|ssr|ssg|bundle|cache|caching|cls|performance|latency|worker|web worker)\b/i', $blob)) {
        return 'rendering_performance';
    }
    if (preg_match('/\b(api|state|architecture|testing|playwright|microfrontend)\b/i', $blob)) {
        return 'architecture_process';
    }
    if (preg_match('/\b(algorithm|big o|complexity|dynamic programming|greedy|backtracking|graph|dijkstra|bfs|dfs|shortest path|topological)\b/i', $blob)) {
        return 'algorithms';
    }
    if (preg_match('/\b(data structure|hash map|hash table|heap|priority queue|linked list|tree|trie|queue|stack|union find|disjoint set)\b/i', $blob)) {
        return 'data_structures';
    }
    if (preg_match('/\b(system design|distributed|latency|throughput|consistency|availability|partition|replication|sharding|eventual consistency|idempotency|rate limit)\b/i', $blob)) {
        return 'system_design';
    }
    if (preg_match('/\b(product|roadmap|stakeholder|prioritization|north star|retention|activation|onboarding|funnel|experimentation|ab test|kpi|metrics)\b/i', $blob)) {
        return 'product_management';
    }

    return 'general';
}

function remember_question_family($title, $raw, $language = '')
{
    $family = question_family_key($title, $raw, $language);
    if ($family === '') return;
    $recent = load_recent_families();
    array_unshift($recent, $family);
    save_recent_families($recent);
}

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

function pick_bot($bots)
{
    if (!is_array($bots) || count($bots) === 0) {
        return array('username' => 'BayMax', 'name' => 'BayMax');
    }
    shuffle($bots);
    return $bots[0];
}

function fetch_json_url($url, $headers)
{
    $url = (string)$url;
    if ($url === '') {
        return array('ok' => false, 'status' => 0, 'error' => 'empty url', 'json' => array());
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $httpHeaders = array('User-Agent: konvo-deep-worker/1.0');
        if (is_array($headers)) {
            foreach ($headers as $h) {
                $h = trim((string)$h);
                if ($h !== '') $httpHeaders[] = $h;
            }
        }
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $httpHeaders,
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $err !== '' || $status < 200 || $status >= 300) {
            return array('ok' => false, 'status' => $status, 'error' => $err, 'json' => array());
        }
        $decoded = json_decode((string)$body, true);
        return array('ok' => is_array($decoded), 'status' => $status, 'error' => '', 'json' => is_array($decoded) ? $decoded : array());
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: konvo-deep-worker/1.0\r\n",
        ),
    ));
    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body) || trim($body) === '') {
        return array('ok' => false, 'status' => 0, 'error' => 'fetch failed', 'json' => array());
    }
    $decoded = json_decode($body, true);
    return array('ok' => is_array($decoded), 'status' => 200, 'error' => '', 'json' => is_array($decoded) ? $decoded : array());
}

function deep_openai_chat_json($messages, $temperature = 0.8, $maxTokens = 1200, $task = 'deep_question')
{
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY missing.');
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable.');
    }

    $payload = array(
        'model' => konvo_model_for_task((string)$task, array('technical' => true)),
        'messages' => is_array($messages) ? $messages : array(),
        'temperature' => (float)$temperature,
        'max_completion_tokens' => max(300, (int)$maxTokens),
    );

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    $opts = array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . KONVO_OPENAI_API_KEY,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    );
    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        $meta = array(
            'curl_errno' => (int)$errno,
            'http_status' => $status,
            'primary_ip' => isset($info['primary_ip']) ? (string)$info['primary_ip'] : '',
            'namelookup_time' => isset($info['namelookup_time']) ? (float)$info['namelookup_time'] : 0.0,
            'connect_time' => isset($info['connect_time']) ? (float)$info['connect_time'] : 0.0,
            'appconnect_time' => isset($info['appconnect_time']) ? (float)$info['appconnect_time'] : 0.0,
            'total_time' => isset($info['total_time']) ? (float)$info['total_time'] : 0.0,
        );
        return array('ok' => false, 'error' => 'OpenAI request failed: ' . $err, 'status' => $status, 'meta' => $meta);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return array(
            'ok' => false,
            'error' => 'OpenAI response was not valid JSON',
            'status' => $status,
            'meta' => array(
                'curl_errno' => (int)$errno,
                'http_status' => $status,
                'raw_head' => substr((string)$raw, 0, 260),
            ),
        );
    }
    if (isset($decoded['error']) && is_array($decoded['error'])) {
        $apiMsg = trim((string)($decoded['error']['message'] ?? 'OpenAI API returned an error.'));
        return array(
            'ok' => false,
            'error' => 'OpenAI API error: ' . $apiMsg,
            'status' => $status,
            'meta' => array(
                'curl_errno' => (int)$errno,
                'http_status' => $status,
                'error_type' => (string)($decoded['error']['type'] ?? ''),
                'error_code' => (string)($decoded['error']['code'] ?? ''),
            ),
        );
    }

    $content = '';
    if (isset($decoded['choices'][0]['message']['content'])) {
        $msgContent = $decoded['choices'][0]['message']['content'];
        if (is_string($msgContent)) {
            $content = trim($msgContent);
        } elseif (is_array($msgContent)) {
            $parts = array();
            foreach ($msgContent as $part) {
                if (is_string($part)) {
                    $s = trim($part);
                    if ($s !== '') $parts[] = $s;
                    continue;
                }
                if (!is_array($part)) continue;
                if (isset($part['text']) && is_string($part['text'])) {
                    $s = trim((string)$part['text']);
                    if ($s !== '') $parts[] = $s;
                    continue;
                }
                if (isset($part['text']['value']) && is_string($part['text']['value'])) {
                    $s = trim((string)$part['text']['value']);
                    if ($s !== '') $parts[] = $s;
                    continue;
                }
            }
            $content = trim(implode("\n", $parts));
        }
    }
    if ($content === '' && isset($decoded['output_text']) && is_string($decoded['output_text'])) {
        $content = trim((string)$decoded['output_text']);
    }
    if ($content === '' && isset($decoded['choices'][0]['text']) && is_string($decoded['choices'][0]['text'])) {
        $content = trim((string)$decoded['choices'][0]['text']);
    }
    if ($content === '') {
        $refusal = '';
        if (isset($decoded['choices'][0]['message']['refusal']) && is_string($decoded['choices'][0]['message']['refusal'])) {
            $refusal = trim((string)$decoded['choices'][0]['message']['refusal']);
        }
        return array(
            'ok' => false,
            'error' => $refusal !== '' ? ('OpenAI refusal: ' . $refusal) : 'OpenAI response format error',
            'status' => $status,
            'meta' => array(
                'curl_errno' => (int)$errno,
                'http_status' => $status,
                'raw_keys' => implode(',', array_keys($decoded)),
            ),
        );
    }

    if ($content === '') {
        return array('ok' => false, 'error' => 'OpenAI returned empty content', 'status' => $status, 'meta' => array('curl_errno' => (int)$errno));
    }

    $json = json_decode($content, true);
    if (!is_array($json)) {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $snippet = substr($content, (int)$start, (int)($end - $start + 1));
            $json = json_decode((string)$snippet, true);
        }
    }
    if (!is_array($json)) {
        return array('ok' => false, 'error' => 'OpenAI content JSON parse failed', 'status' => $status, 'raw_content' => $content, 'meta' => array('curl_errno' => (int)$errno));
    }

    return array('ok' => true, 'json' => $json, 'status' => $status, 'meta' => array(
        'curl_errno' => (int)$errno,
        'http_status' => $status,
        'total_time' => isset($info['total_time']) ? (float)$info['total_time'] : 0.0,
    ));
}

function deep_recent_titles_for_prompt($forumRecentTitles, $recentQuestionTitles, $maxItems = 28)
{
    $recent = array();
    if (is_array($forumRecentTitles)) {
        foreach ($forumRecentTitles as $t) {
            $s = collapse_spaces((string)$t);
            if ($s === '') continue;
            $recent[] = (strlen($s) > 100) ? substr($s, 0, 100) : $s;
            if (count($recent) >= (int)$maxItems) break;
        }
    }
    if (is_array($recentQuestionTitles) && count($recent) < (int)$maxItems) {
        foreach ($recentQuestionTitles as $t) {
            $s = collapse_spaces((string)$t);
            if ($s === '') continue;
            $recent[] = (strlen($s) > 100) ? substr($s, 0, 100) : $s;
            if (count($recent) >= (int)$maxItems) break;
        }
    }
    $recent = array_values(array_unique($recent));
    return $recent;
}

function deep_bot_question_persona($bot)
{
    $u = strtolower(trim((string)($bot['username'] ?? '')));
    if ($u === '') $u = 'baymax';
    $map = array(
        'baymax' => 'Warm, practical, grounded, calm clarity. Sounds like a helpful teammate, not a lecturer.',
        'kirupabot' => 'Forum helper energy: concise, practical, and link-aware when a kirupa.com deep dive fits.',
        'vaultboy' => 'Playful gamer voice with design curiosity; casual tone, punchy examples, and lively wording.',
        'mechaprime' => 'Precise, compact, analytical. Disciplined tone with subtle musician-like structure and timing.',
        'yoshiii' => 'Playful but useful, creative framing, still concise and concrete.',
        'bobamilk' => 'Very concise, ESL-natural phrasing, simple sentence structure, human and direct.',
        'wafflefries' => 'Casual and punchy, internet-native tone, no fluff.',
        'quelly' => 'Systems-minded, crisp tradeoff language, clean framing.',
        'sora' => 'Minimalist and thoughtful, low-word calm voice.',
        'sarah_connor' => 'Skeptical and risk-aware, pragmatic edge, concrete failure modes.',
        'ellen1979' => 'Experienced and practical, no-nonsense product realism.',
        'arthurdent' => 'Dry wit, lightly contrarian, still constructive and concise.',
        'hariseldon' => 'Strategic lens with long-term implications, concise and practical.',
    );
    return isset($map[$u]) ? $map[$u] : $map['baymax'];
}

function deep_generate_single_live_question($forumRecentTitles, $recentQuestionTitles, $preferCoding = true, $bot = array())
{
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY missing', 'meta' => array());
    }

    $recent = deep_recent_titles_for_prompt($forumRecentTitles, $recentQuestionTitles, 28);
    $recentBlock = $recent === array() ? '(none)' : implode("\n- ", array_merge(array(''), $recent));
    $mode = $preferCoding ? 'coding' : 'conceptual';
    $wantedType = $preferCoding ? 'easy_code' : 'deep';
    $botUsername = trim((string)($bot['username'] ?? 'BayMax'));
    $botPersona = deep_bot_question_persona($bot);
    $animationPixelFocus = (mt_rand(1, 100) <= 35);
    $keywordBlock = deep_keywords_block_for_prompt($preferCoding, $animationPixelFocus);
    $avoidMotifs = deep_avoid_motifs_block();

    $system = "You are writing a NEW forum topic question for Kirupa Forum.\n\n"
        . "Goal:\n"
        . "Create a human-sounding, discussion-worthy technical question that feels like a real person posting quickly, not a formal article title.\n\n"
        . "Return ONLY valid JSON:\n"
        . "{\n"
        . "  \"title\": \"...\",\n"
        . "  \"raw\": \"...\",\n"
        . "  \"language\": \"javascript|typescript|html|css|mixed|system_design|algorithms|data_structures|product\",\n"
        . "  \"question_type\": \"{$wantedType}\",\n"
        . "  \"category_hint\": \"web_dev\"\n"
        . "}\n\n"
        . "Hard requirements:\n"
        . "1) Use generous whitespace in raw.\n"
        . "2) Start paragraph 1 with a greeting integrated into the same sentence as personal context (example shape: \"What's up everyone? I'm...\").\n"
        . "3) Paragraph 1 must include first-person context (\"I'm trying to...\", \"I'm working on...\").\n"
        . "4) For conceptual mode: write exactly 2 short paragraphs with one blank line between them.\n"
        . "5) For coding mode: write 3 blocks in this order with blank lines between each block: (a) personal context paragraph, (b) one fenced code block using js/ts/html/css, (c) one direct question paragraph.\n"
        . "6) The final paragraph must be one direct question ending with \"?\".\n"
        . "7) No signature line. No mentions. No headings. No bullet lists.\n"
        . "8) Avoid formulaic closers like \"Curious to hear your thoughts.\".\n"
        . "9) Keep total length around 45-110 words.\n"
        . "10) No mention of being a bot.\n\n"
        . "Title rules:\n"
        . "- Must be a concise, complete thought ending with \"?\".\n"
        . "- Natural forum style; not an essay title.\n"
        . "- No \"Something: something\" colon pattern.\n"
        . "- Must align with the raw question.\n\n"
        . "Human voice rules:\n"
        . "- Sound like a person in the middle of real work.\n"
        . "- Mention one concrete tradeoff or failure mode.\n"
        . "- Use plain language; avoid academic phrasing and buzzword stacking.\n"
        . "- Ask one strong question only.\n\n"
        . "Bot personality layer (required):\n"
        . "BOT_USERNAME: {$botUsername}\n"
        . "BOT_PERSONA: {$botPersona}\n"
        . "Adapt diction and rhythm to this persona while staying concise and human.";

    $themeFocusRule = $animationPixelFocus
        ? "- Theme focus for this run is animation + pixel art. The question must be about animation, canvas/WebGL motion, sprite workflows, or pixel-art rendering/asset issues.\n"
        : "- Theme mix for this run is broad technical. Still include animation/pixel-art inspiration periodically when it fits naturally.\n";
    $user = "Mode: {$mode}\n\n"
        . "DIFFICULTY: " . ($preferCoding ? 'medium' : 'deep') . "\n\n"
        . "Additional constraints:\n"
        . "- Hard avoid repeated motifs: {$avoidMotifs}.\n"
        . "- Use the keyword guidance below as inspiration. Do not copy a full phrase verbatim as the title.\n"
        . "- Use diverse domains across programming, algorithms, data structures, system design, and product management.\n"
        . "- Keep question natural and answerable from real experience.\n\n"
        . "Keyword guidance for this run:\n"
        . $keywordBlock . "\n\n"
        . "Theme requirements:\n"
        . $themeFocusRule . "\n"
        . "Recent titles to avoid:\n"
        . $recentBlock;

    $res = deep_openai_chat_json(
        array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        0.75,
        850
    );
    if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
        return array(
            'ok' => false,
            'error' => (string)($res['error'] ?? 'llm_generation_failed'),
            'meta' => isset($res['meta']) && is_array($res['meta']) ? $res['meta'] : array(),
        );
    }

    $decoded = $res['json'];
    if (isset($decoded['question']) && is_array($decoded['question'])) {
        $decoded = $decoded['question'];
    }

    $san = deep_sanitize_generated_question($decoded, $preferCoding);
    if (!is_array($san)) {
        return array(
            'ok' => false,
            'error' => 'Generated question failed validation.',
            'meta' => isset($res['meta']) && is_array($res['meta']) ? $res['meta'] : array(),
        );
    }
    if (!isset($san['question_type']) || trim((string)$san['question_type']) === '') {
        $san['question_type'] = $wantedType;
    }

    return array(
        'ok' => true,
        'question' => $san,
        'mode' => $mode,
        'animation_pixelart_focus' => $animationPixelFocus,
        'meta' => isset($res['meta']) && is_array($res['meta']) ? $res['meta'] : array(),
    );
}

function deep_sanitize_generated_question($item, $isCoding)
{
    if (!is_array($item)) return null;
    $title = ensure_question_mark_title(collapse_spaces((string)($item['title'] ?? '')));
    $raw = trim((string)($item['raw'] ?? ''));
    $language = strtolower(trim((string)($item['language'] ?? 'mixed')));
    if ($language === '') $language = 'mixed';
    $allowedLangs = array('javascript', 'typescript', 'html', 'css', 'mixed', 'system_design', 'algorithms', 'data_structures', 'product');
    if (!in_array($language, $allowedLangs, true)) {
        $language = 'mixed';
    }

    if ($title === '' || strlen($title) < 14) return null;
    if ($raw === '' || strlen($raw) < 40) return null;
    if ($isCoding && !preg_match('/```(js|javascript|ts|typescript|html|css)\b/i', $raw)) {
        return null;
    }

    return array(
        'title' => $title,
        'raw' => $raw,
        'question_type' => $isCoding ? 'easy_code' : 'deep',
        'language' => $language,
        'family' => question_family_key($title, $raw, $language),
        'seed_source' => 'llm_generated',
        'seed_url' => '',
        'seed_title' => '',
    );
}

function deep_generate_llm_question_pool($forumRecentTitles, $recentQuestionTitles, $codingCount = 18, $conceptCount = 5)
{
    if (KONVO_OPENAI_API_KEY === '') {
        return array('coding' => array(), 'concept' => array(), 'error' => 'OPENAI_API_KEY missing', 'error_meta' => array());
    }

    $recentTitles = array();
    if (is_array($forumRecentTitles)) {
        foreach ($forumRecentTitles as $t) {
            $s = collapse_spaces((string)$t);
            if ($s !== '') $recentTitles[] = $s;
            if (count($recentTitles) >= 70) break;
        }
    }
    if (is_array($recentQuestionTitles)) {
        foreach ($recentQuestionTitles as $t) {
            $s = collapse_spaces((string)$t);
            if ($s !== '') $recentTitles[] = $s;
            if (count($recentTitles) >= 90) break;
        }
    }
    $recentTitles = array_values(array_unique($recentTitles));
    $recentTitlesBlock = $recentTitles === array() ? '(none)' : implode("\n- ", array_merge(array(''), $recentTitles));
    $keywordBlock = deep_keywords_block_for_prompt(true, true);
    $avoidMotifs = deep_avoid_motifs_block();

    $system = 'Generate varied technical forum questions in JSON only. '
        . 'Focus on breadth: programming, algorithms, data structures, system design, and product management. '
        . 'Reserve a meaningful slice for animation/pixel-art themes (canvas/WebGL motion, sprite workflows, tilemaps, and rendering crispness). '
        . 'Keep titles concise, natural, and complete thoughts ending with a question mark. '
        . 'For coding questions, include a runnable code block and one specific ask. '
        . 'Do not repeat or paraphrase recent topics. '
        . 'Hard avoid overused motifs this run: ' . $avoidMotifs . '.';

    $user = "Return strict JSON with this schema:\n"
        . "{\n"
        . "  \"coding\": [{\"title\":\"...\",\"raw\":\"...\",\"language\":\"javascript|typescript|html|css|mixed\"}],\n"
        . "  \"concept\": [{\"title\":\"...\",\"raw\":\"...\",\"language\":\"mixed|system_design|algorithms|data_structures|product\"}]\n"
        . "}\n\n"
        . "Counts:\n"
        . "- coding: " . (int)$codingCount . "\n"
        . "- concept: " . (int)$conceptCount . "\n\n"
        . "Rules:\n"
        . "- 80% coding, 20% conceptual.\n"
        . "- At least 30% of all generated items should be animation/pixel-art inspired.\n"
        . "- Use the keyword guidance below as inspiration; do not copy phrases verbatim into titles.\n"
        . "- No poll syntax.\n"
        . "- No mention of being a bot.\n"
        . "- No duplicate ideas.\n"
        . "- Each coding raw must include a fenced block with js/ts/html/css.\n"
        . "- Keep each prompt practical and discussion-worthy.\n\n"
        . "Keyword guidance:\n"
        . $keywordBlock . "\n\n"
        . "Recent titles to avoid:\n"
        . $recentTitlesBlock;

    $res = deep_openai_chat_json(
        array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        0.9
    );
    if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
        return array(
            'coding' => array(),
            'concept' => array(),
            'error' => (string)($res['error'] ?? 'llm_generation_failed'),
            'error_meta' => isset($res['meta']) && is_array($res['meta']) ? $res['meta'] : array(),
        );
    }

    $decoded = $res['json'];
    $codingRaw = isset($decoded['coding']) && is_array($decoded['coding']) ? $decoded['coding'] : array();
    $conceptRaw = isset($decoded['concept']) && is_array($decoded['concept']) ? $decoded['concept'] : array();

    $coding = array();
    $concept = array();
    $seen = array();

    foreach ($codingRaw as $item) {
        $q = deep_sanitize_generated_question($item, true);
        if (!is_array($q)) continue;
        $k = normalized_title_key((string)$q['title']);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $coding[] = $q;
        if (count($coding) >= (int)$codingCount) break;
    }

    foreach ($conceptRaw as $item) {
        $q = deep_sanitize_generated_question($item, false);
        if (!is_array($q)) continue;
        $k = normalized_title_key((string)$q['title']);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $concept[] = $q;
        if (count($concept) >= (int)$conceptCount) break;
    }

    return array(
        'coding' => $coding,
        'concept' => $concept,
        'error' => '',
        'error_meta' => isset($res['meta']) && is_array($res['meta']) ? $res['meta'] : array(),
    );
}

function pick_live_llm_question($codingPool, $conceptPool)
{
    $recent = load_recent_questions();
    $recentSet = array();
    foreach ($recent as $r) {
        $rk = normalized_title_key($r);
        if ($rk !== '') $recentSet[$rk] = true;
    }
    $forumRecentTitles = recent_forum_titles(180);
    $forumRecentSet = array();
    foreach ($forumRecentTitles as $t) {
        $k = normalized_title_key((string)$t);
        if ($k !== '') $forumRecentSet[$k] = true;
    }
    $recentFamilies = load_recent_families();
    $recentFamiliesSet = array();
    $familyWindow = 22;
    $familyCount = 0;
    foreach ($recentFamilies as $f) {
        if ($familyCount >= $familyWindow) break;
        $fk = strtolower(trim((string)$f));
        if ($fk === '') continue;
        $recentFamiliesSet[$fk] = true;
        $familyCount++;
    }

    $codingFiltered = filter_question_pool(
        is_array($codingPool) ? $codingPool : array(),
        $recentSet,
        $forumRecentSet,
        $recentFamiliesSet,
        $forumRecentTitles,
        true,
        true
    );
    if ($codingFiltered === array()) {
        $codingFiltered = filter_question_pool(
            is_array($codingPool) ? $codingPool : array(),
            $recentSet,
            $forumRecentSet,
            $recentFamiliesSet,
            $forumRecentTitles,
            false,
            true
        );
    }

    $conceptFiltered = filter_question_pool(
        is_array($conceptPool) ? $conceptPool : array(),
        $recentSet,
        $forumRecentSet,
        $recentFamiliesSet,
        $forumRecentTitles,
        true,
        true
    );
    if ($conceptFiltered === array()) {
        $conceptFiltered = filter_question_pool(
            is_array($conceptPool) ? $conceptPool : array(),
            $recentSet,
            $forumRecentSet,
            $recentFamiliesSet,
            $forumRecentTitles,
            false,
            true
        );
    }

    $preferCoding = (mt_rand(1, 100) <= 80);
    $pickPool = $preferCoding ? $codingFiltered : $conceptFiltered;
    if ($pickPool === array()) {
        $pickPool = $preferCoding ? $conceptFiltered : $codingFiltered;
    }
    if ($pickPool === array()) {
        return array(
            'ok' => false,
            'error' => 'No eligible live LLM question after filtering.',
            'stats' => array(
                'coding_input' => is_array($codingPool) ? count($codingPool) : 0,
                'concept_input' => is_array($conceptPool) ? count($conceptPool) : 0,
                'coding_filtered' => count($codingFiltered),
                'concept_filtered' => count($conceptFiltered),
            ),
        );
    }
    $picked = $pickPool[mt_rand(0, count($pickPool) - 1)];
    return array(
        'ok' => true,
        'question' => $picked,
        'stats' => array(
            'coding_input' => is_array($codingPool) ? count($codingPool) : 0,
            'concept_input' => is_array($conceptPool) ? count($conceptPool) : 0,
            'coding_filtered' => count($codingFiltered),
            'concept_filtered' => count($conceptFiltered),
            'prefer_coding' => $preferCoding,
        ),
    );
}

function collapse_spaces($text)
{
    $s = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', (string)$s);
    return trim((string)$s);
}

function clip_words($text, $maxWords = 12)
{
    $s = collapse_spaces((string)$text);
    if ($s === '') return '';
    $parts = preg_split('/\s+/', $s);
    if (!is_array($parts) || count($parts) <= (int)$maxWords) return $s;
    $parts = array_slice($parts, 0, (int)$maxWords);
    return trim(implode(' ', $parts));
}

function infer_question_track($title, $raw = '', $tags = array())
{
    $blob = strtolower(trim((string)$title . "\n" . (string)$raw . "\n" . implode(' ', is_array($tags) ? $tags : array())));
    if ($blob === '') return 'programming';
    if (preg_match('/\b(animation|animated|pixel art|pixelart|sprite|spritesheet|tilemap|game loop|delta time|easing|tween|canvas|webgl|shader|dither|dithering|palette)\b/i', $blob)) return 'animation_pixelart';
    if (preg_match('/\b(algorithm|big o|complexity|dynamic programming|greedy|backtracking|graph|dijkstra|bfs|dfs|shortest path|topological)\b/i', $blob)) return 'algorithms';
    if (preg_match('/\b(data structure|hash map|hash table|heap|priority queue|linked list|tree|trie|queue|stack|union find|disjoint set)\b/i', $blob)) return 'data_structures';
    if (preg_match('/\b(system design|distributed|latency|throughput|consistency|availability|partition|replication|sharding|cache invalidation|idempotency|rate limit)\b/i', $blob)) return 'system_design';
    if (preg_match('/\b(product|roadmap|stakeholder|prioritization|north star|retention|activation|onboarding|funnel|experimentation|ab test|kpi|metrics)\b/i', $blob)) return 'product_management';
    if (preg_match('/\b(css|html|javascript|typescript|react|vue|angular|svelte|frontend|web)\b/i', $blob)) return 'webdev';
    return 'programming';
}

function build_curated_cross_domain_questions()
{
    return array();
}

function build_llms_seed_questions($maxItems = 36)
{
    if (!function_exists('kirupa_fetch_llms_links')) return array();
    $links = kirupa_fetch_llms_links();
    if (!is_array($links) || $links === array()) return array();
    shuffle($links);
    $out = array();
    $seen = array();

    foreach ($links as $link) {
        if (count($out) >= (int)$maxItems) break;
        if (!is_array($link)) continue;
        $srcTitle = collapse_spaces((string)($link['title'] ?? ''));
        $srcUrl = trim((string)($link['url'] ?? ''));
        if ($srcTitle === '' || $srcUrl === '') continue;

        $track = infer_question_track($srcTitle, $srcUrl);
        $seed = abs((int)crc32(strtolower($srcTitle . '|' . $srcUrl)));
        $short = clip_words($srcTitle, 10);
        $safeShort = rtrim($short, " .,:;!?-");
        if ($safeShort === '') continue;

        $titleTemplates = array(
            'What tradeoff stands out in ' . $safeShort,
            'How would you apply ' . $safeShort . ' in production',
            'What would you challenge first in ' . $safeShort,
        );
        if ($track === 'animation_pixelart') {
            $titleTemplates = array(
                'How would you animate ' . $safeShort . ' without frame jitter',
                'What rendering tradeoff matters most in ' . $safeShort,
                'Where would pixel fidelity break first in ' . $safeShort,
            );
        } elseif ($track === 'algorithms') {
            $titleTemplates = array(
                'Which algorithmic tradeoff matters most in ' . $safeShort,
                'How would you optimize ' . $safeShort . ' without overfitting',
                'What complexity pitfall appears first in ' . $safeShort,
            );
        } elseif ($track === 'data_structures') {
            $titleTemplates = array(
                'Which data structure choice is critical in ' . $safeShort,
                'How would you model ' . $safeShort . ' for scale',
                'Where does structure choice break first in ' . $safeShort,
            );
        } elseif ($track === 'system_design') {
            $titleTemplates = array(
                'How would you design ' . $safeShort . ' for reliability',
                'What system design tradeoff defines ' . $safeShort,
                'Where would ' . $safeShort . ' fail first under load',
            );
        } elseif ($track === 'product_management') {
            $titleTemplates = array(
                'How would you prioritize around ' . $safeShort,
                'What product metric best captures success in ' . $safeShort,
                'Which product tradeoff is hardest in ' . $safeShort,
            );
        }

        $t = ensure_question_mark_title($titleTemplates[$seed % count($titleTemplates)]);
        $k = normalized_title_key($t);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;

        $rawTemplates = array(
            "This kirupa.com topic caught my eye:\n\n{$srcUrl}\n\nWhat is the biggest implementation tradeoff here, and how would you handle it in a real project?",
            "Using this kirupa.com article as a starting point:\n\n{$srcUrl}\n\nWhat part would you keep exactly as-is, and what would you redesign for long-term maintainability?",
            "I want practical takes on this kirupa.com topic:\n\n{$srcUrl}\n\nWhat is the first decision you would make differently after seeing this in production?",
        );
        if ($track === 'animation_pixelart') {
            $rawTemplates = array(
                "This kirupa.com topic looks relevant to animation/pixel-art work:\n\n{$srcUrl}\n\nWhat implementation choice would matter most for smooth motion and crisp rendering in production?",
                "Using this kirupa.com topic as context:\n\n{$srcUrl}\n\nHow would you balance animation smoothness, draw-call cost, and pixel fidelity in a real app?",
                "Pulled from kirupa.com:\n\n{$srcUrl}\n\nWhat is the first rendering or timing bug you would expect, and how would you prevent it?",
            );
        }

        $out[] = array(
            'title' => $t,
            'raw' => $rawTemplates[$seed % count($rawTemplates)],
            'question_type' => 'llms_seed',
            'language' => ($track === 'webdev' ? 'mixed' : $track),
            'family' => question_family_key($t, $rawTemplates[$seed % count($rawTemplates)], ($track === 'webdev' ? 'mixed' : $track)),
            'seed_source' => 'kirupa_llms',
            'seed_url' => $srcUrl,
            'seed_title' => $srcTitle,
        );
    }

    return $out;
}

function fetch_stackoverflow_common_questions($maxItems = 90)
{
    $urls = array(
        'https://api.stackexchange.com/2.3/questions?order=desc&sort=votes&site=stackoverflow&pagesize=50',
        'https://api.stackexchange.com/2.3/questions?order=desc&sort=frequent&site=stackoverflow&pagesize=50',
    );
    $all = array();
    $seen = array();
    foreach ($urls as $url) {
        $res = fetch_json_url($url, array());
        if (!$res['ok']) continue;
        $items = isset($res['json']['items']) && is_array($res['json']['items']) ? $res['json']['items'] : array();
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $title = collapse_spaces((string)($item['title'] ?? ''));
            $link = trim((string)($item['link'] ?? ''));
            if ($title === '' || $link === '') continue;
            $k = normalized_title_key($title) . '|' . strtolower($link);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $all[] = array(
                'title' => $title,
                'url' => $link,
                'tags' => isset($item['tags']) && is_array($item['tags']) ? $item['tags'] : array(),
                'score' => (int)($item['score'] ?? 0),
                'view_count' => (int)($item['view_count'] ?? 0),
            );
            if (count($all) >= (int)$maxItems) break 2;
        }
    }
    if ($all !== array()) return $all;

    $m = deep_llm_keyword_map();
    $fallbackTitles = array_values(array_unique(array_merge(
        isset($m['coding_tracks']) && is_array($m['coding_tracks']) ? $m['coding_tracks'] : array(),
        isset($m['coding_problem_shapes']) && is_array($m['coding_problem_shapes']) ? $m['coding_problem_shapes'] : array(),
        isset($m['concept_tracks']) && is_array($m['concept_tracks']) ? $m['concept_tracks'] : array(),
        isset($m['animation_pixelart']) && is_array($m['animation_pixelart']) ? $m['animation_pixelart'] : array()
    )));
    $fallback = array();
    foreach ($fallbackTitles as $it) {
        $title = collapse_spaces((string)$it);
        if ($title === '') continue;
        $fallback[] = array(
            'title' => $title,
            'url' => 'https://stackoverflow.com/search?q=' . rawurlencode($title),
            'tags' => array(),
            'score' => 0,
            'view_count' => 0,
        );
    }
    return array_slice($fallback, 0, (int)$maxItems);
}

function build_stackoverflow_seed_questions($maxItems = 30)
{
    $items = fetch_stackoverflow_common_questions(max(40, (int)$maxItems * 2));
    if (!is_array($items) || $items === array()) return array();
    shuffle($items);
    $out = array();
    $seen = array();
    foreach ($items as $item) {
        if (count($out) >= (int)$maxItems) break;
        if (!is_array($item)) continue;
        $srcTitle = collapse_spaces((string)($item['title'] ?? ''));
        $srcUrl = trim((string)($item['url'] ?? ''));
        $tags = isset($item['tags']) && is_array($item['tags']) ? $item['tags'] : array();
        if ($srcTitle === '' || $srcUrl === '') continue;
        $track = infer_question_track($srcTitle, $srcTitle, $tags);
        $seed = abs((int)crc32(strtolower($srcTitle . '|' . $srcUrl)));
        $core = clip_words($srcTitle, 9);
        $core = rtrim($core, " .,:;!?-");
        if ($core === '') continue;

        $titleTemplates = array(
            'How would you answer this Stack Overflow classic today',
            'What is your production take on ' . $core,
            'Where does this common pattern fail in real code',
        );
        if ($track === 'animation_pixelart') {
            $titleTemplates = array(
                'How would you solve this animation bug in production',
                'What is your modern pixel-art rendering fix for this case',
                'Where does this sprite or canvas pattern break first',
            );
        } elseif ($track === 'algorithms') {
            $titleTemplates = array(
                'How do you choose the right algorithmic approach here',
                'What is the practical complexity tradeoff in ' . $core,
                'Which edge case breaks this algorithm first',
            );
        } elseif ($track === 'data_structures') {
            $titleTemplates = array(
                'Which data structure would you choose for this case',
                'What structure tradeoff is hidden in ' . $core,
                'How would you model this without overengineering',
            );
        } elseif ($track === 'system_design') {
            $titleTemplates = array(
                'What is your system design answer to this classic problem',
                'How would you scale this design without losing correctness',
                'Which reliability tradeoff is most important here',
            );
        } elseif ($track === 'product_management') {
            $titleTemplates = array(
                'How would you prioritize this product problem in practice',
                'What KPI would you use for this kind of decision',
                'Which product tradeoff matters most in this scenario',
            );
        }

        $t = ensure_question_mark_title($titleTemplates[$seed % count($titleTemplates)]);
        $k = normalized_title_key($t);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;

        $rawTemplates = array(
            "This Stack Overflow question shows up constantly:\n\n{$srcUrl}\n\nHow would you answer it for production code in 2026, including the caveat most people miss?",
            "Pulled from common Stack Overflow threads:\n\n{$srcUrl}\n\nWhat is your modern answer, and where does the usual advice break down at scale?",
            "I want a practical take on this Stack Overflow classic:\n\n{$srcUrl}\n\nWhat would your team standard be for this issue and why?",
        );
        if ($track === 'animation_pixelart') {
            $rawTemplates = array(
                "This animation/pixel-art Stack Overflow question appears often:\n\n{$srcUrl}\n\nWhat is your production answer now, and which rendering/timing caveat is usually missed?",
                "Pulled from common sprite/canvas questions:\n\n{$srcUrl}\n\nWhat implementation standard would your team adopt to avoid jitter, blur, or frame drift?",
                "I want a practical animation-focused take on this classic:\n\n{$srcUrl}\n\nWhat would you change first for smooth motion and crisp pixels in real browsers?",
            );
        }
        $raw = $rawTemplates[$seed % count($rawTemplates)];
        $out[] = array(
            'title' => $t,
            'raw' => $raw,
            'question_type' => 'stackoverflow_seed',
            'language' => ($track === 'webdev' ? 'mixed' : $track),
            'family' => question_family_key($t, $raw, ($track === 'webdev' ? 'mixed' : $track)),
            'seed_source' => 'stackoverflow_common',
            'seed_url' => $srcUrl,
            'seed_title' => $srcTitle,
        );
    }
    return $out;
}

function normalized_title_key($title)
{
    $s = strtolower(trim((string)$title));
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/[^a-z0-9\s]/', ' ', (string)$s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    return trim((string)$s);
}

function title_terms($text)
{
    $s = normalized_title_key((string)$text);
    if ($s === '') return array();
    $parts = preg_split('/\s+/', $s);
    if (!is_array($parts)) return array();
    $stop = array(
        'what', 'why', 'how', 'when', 'where', 'which', 'does', 'this', 'that', 'is', 'the', 'a', 'an',
        'of', 'to', 'in', 'for', 'and', 'or', 'with', 'on', 'as', 'do', 'you', 'your', 'it', 'vs',
    );
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || strlen($p) < 3 || in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function title_similarity_score($a, $b)
{
    $ta = title_terms((string)$a);
    $tb = title_terms((string)$b);
    if ($ta === array() || $tb === array()) return 0.0;
    $sa = array_fill_keys($ta, true);
    $sb = array_fill_keys($tb, true);
    $intersection = 0;
    foreach ($sa as $k => $_) {
        if (isset($sb[$k])) $intersection++;
    }
    $union = count($sa) + count($sb) - $intersection;
    if ($union <= 0) return 0.0;
    return (float)$intersection / (float)$union;
}

function title_too_similar_to_recent($candidateTitle, $recentTitles)
{
    $ct = trim((string)$candidateTitle);
    if ($ct === '' || !is_array($recentTitles) || count($recentTitles) === 0) return false;

    $ck = normalized_title_key($ct);
    $cIsEventLoop = (bool)preg_match('/\b(event loop|microtask|macrotask|settimeout|queueMicrotask|promise)\b/i', $ct);
    $cHasOutputWord = (bool)preg_match('/\boutput\b/i', $ct);

    foreach ($recentTitles as $rt) {
        $rt = trim((string)$rt);
        if ($rt === '') continue;

        if (normalized_title_key($rt) === $ck) {
            return true;
        }

        $score = title_similarity_score($ct, $rt);
        if ($score >= 0.72) {
            return true;
        }

        $rIsEventLoop = (bool)preg_match('/\b(event loop|microtask|macrotask|settimeout|queueMicrotask|promise)\b/i', $rt);
        if ($cIsEventLoop && $rIsEventLoop && $score >= 0.40) {
            return true;
        }

        $rHasOutputWord = (bool)preg_match('/\boutput\b/i', $rt);
        if ($cHasOutputWord && $rHasOutputWord && $score >= 0.46) {
            return true;
        }
    }
    return false;
}

function repetitive_hotspot_key($text)
{
    $t = strtolower((string)$text);
    if ($t === '') return '';
    if (preg_match('/\b(sprite|spritesheet|tilemap|pixel art|pixelart|game loop|delta time|dither|palette)\b/i', $t)) return 'animation_pixelart';
    if (preg_match('/\b(settimeout|requestanimationframe|microtask|macrotask|event loop)\b/i', $t)) return 'js_timing';
    if (preg_match('/\b(optional chaining|nullish|destructuring default)\b/i', $t)) return 'js_optional_default';
    if (preg_match('/\b(temporal dead zone|\btdz\b|let before)\b/i', $t)) return 'js_tdz';
    if (preg_match('/\b(promise chain|async return in finally|then undefined)\b/i', $t)) return 'js_promise_chain';
    if (preg_match('/\b(var vs let|closure log|timer loops)\b/i', $t)) return 'js_scope_loop';
    return '';
}

function title_hits_recent_hotspot($candidateTitle, $recentTitles)
{
    if (!is_array($recentTitles) || $recentTitles === array()) return false;
    $candidateKey = repetitive_hotspot_key((string)$candidateTitle);
    if ($candidateKey === '') return false;
    foreach ($recentTitles as $rt) {
        if (repetitive_hotspot_key((string)$rt) === $candidateKey) {
            return true;
        }
    }
    return false;
}

function title_looks_question_like($title)
{
    $t = strtolower(trim((string)$title));
    if ($t === '') return false;
    if (str_contains($t, '?')) return true;
    if (preg_match('/^(what|why|how|when|where|who|which)\b/i', $t)) return true;
    if (preg_match('/^(is|are|can|could|should|would|do|does|did|will|have|has|had)\b/i', $t)) return true;
    return false;
}

function ensure_question_mark_title($title)
{
    $title = trim((string)$title);
    if ($title === '') return $title;
    if (!title_looks_question_like($title)) return $title;
    $title = preg_replace('/[.!:;,\-]+$/', '', $title) ?? $title;
    $title = rtrim((string)$title);
    if (!str_ends_with($title, '?')) {
        $title .= '?';
    }
    return $title;
}

function text_looks_webdev_related($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(web|webdev|frontend|front-end|browser|html|css|javascript|typescript|react|vue|angular|svelte|dom|a11y|accessibility|webpack|vite|next\.?js|nuxt|ssr|ssg|hydration|requestanimationframe|settimeout|service worker|web worker|cdn|cache|caching|graphql|rest|api|canvas|webgl|shader|sprite|spritesheet|tilemap|pixel art|pixelart|easing|tween)\b/i', $t);
}

function deep_question_category_id($title, $raw)
{
    $title = (string)$title;
    $raw = (string)$raw;
    if (title_looks_question_like($title) && text_looks_webdev_related($title . "\n" . $raw)) {
        return (int)KONVO_WEBDEV_CATEGORY_ID;
    }
    return (int)KONVO_CATEGORY_ID;
}

function recent_forum_titles($max)
{
    $headers = array();
    if (KONVO_API_KEY !== '') {
        $headers[] = 'Api-Key: ' . KONVO_API_KEY;
        $headers[] = 'Api-Username: BayMax';
    }

    $res = fetch_json_url(rtrim(KONVO_BASE_URL, '/') . '/latest.json', $headers);
    if (!$res['ok']) return array();

    $json = $res['json'];
    $topics = isset($json['topic_list']['topics']) && is_array($json['topic_list']['topics'])
        ? $json['topic_list']['topics']
        : array();

    $titles = array();
    $count = 0;
    foreach ($topics as $topic) {
        if ($count >= (int)$max) break;
        $title = trim((string)($topic['title'] ?? ''));
        if ($title === '') continue;
        $titles[] = $title;
        $count++;
    }
    return $titles;
}

function recent_forum_title_keys($max)
{
    $titles = recent_forum_titles($max);
    $keys = array();
    foreach ($titles as $title) {
        $key = normalized_title_key((string)$title);
        if ($key === '') continue;
        $keys[$key] = true;
    }
    return $keys;
}

function filter_question_pool($questions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, $enforceFamily, $enforceSimilarity)
{
    $pool = array();
    if (!is_array($questions)) return $pool;

    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $title = trim((string)($q['title'] ?? ''));
        $raw = trim((string)($q['raw'] ?? ''));
        $lang = trim((string)($q['language'] ?? 'mixed'));
        $key = normalized_title_key($title);
        if ($key === '' || isset($recentSet[$key]) || isset($forumRecentSet[$key])) {
            continue;
        }

        if ($enforceSimilarity && title_too_similar_to_recent($title, $forumRecentTitles)) {
            continue;
        }
        if (title_hits_recent_hotspot($title, $forumRecentTitles)) {
            continue;
        }

        $family = question_family_key($title, $raw, $lang);
        if ($enforceFamily && isset($recentFamiliesSet[$family])) {
            continue;
        }

        $qq = $q;
        $qq['family'] = $family;
        if (!isset($qq['language']) || trim((string)$qq['language']) === '') $qq['language'] = $lang !== '' ? $lang : 'mixed';
        if (!isset($qq['question_type']) || trim((string)$qq['question_type']) === '') $qq['question_type'] = 'deep';
        $pool[] = $qq;
    }

    return $pool;
}

function pick_question($deepQuestions, $easyCodeQuestions)
{
    $hasDeep = is_array($deepQuestions) && count($deepQuestions) > 0;
    $hasEasy = is_array($easyCodeQuestions) && count($easyCodeQuestions) > 0;
    if (!$hasDeep && !$hasEasy) {
        $seedKeywords = deep_keywords_for_prompt(true, false);
        $seedKeyword = isset($seedKeywords[0]) ? trim((string)$seedKeywords[0]) : 'frontend architecture';
        return array(
            'title' => ensure_question_mark_title('How are you handling ' . $seedKeyword . ' in production'),
            'raw' => "What's up everyone? I'm trying to improve " . $seedKeyword . " in a real app and keep running into tradeoffs between speed, maintainability, and correctness.\n\nHow are you deciding what to optimize first?",
            'question_type' => 'deep',
            'language' => 'mixed',
        );
    }

    $recent = load_recent_questions();
    $recentSet = array();
    foreach ($recent as $r) {
        $rk = normalized_title_key($r);
        if ($rk !== '') $recentSet[$rk] = true;
    }
    $forumRecentTitles = recent_forum_titles(140);
    $forumRecentSet = array();
    foreach ($forumRecentTitles as $t) {
        $k = normalized_title_key((string)$t);
        if ($k !== '') $forumRecentSet[$k] = true;
    }

    $recentFamilies = load_recent_families();
    $recentFamiliesSet = array();
    $familyWindow = 22;
    $familyCount = 0;
    foreach ($recentFamilies as $f) {
        if ($familyCount >= $familyWindow) break;
        $fk = strtolower(trim((string)$f));
        if ($fk === '') continue;
        $recentFamiliesSet[$fk] = true;
        $familyCount++;
    }

    $deepPool = array();
    if ($hasDeep) {
        $deepPool = filter_question_pool($deepQuestions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, true, true);
        if (count($deepPool) === 0) {
            $deepPool = filter_question_pool($deepQuestions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, false, true);
        }
        if (count($deepPool) === 0) {
            $deepPool = filter_question_pool($deepQuestions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, false, false);
        }
    }

    $easyPool = array();
    if ($hasEasy) {
        $easyPool = filter_question_pool($easyCodeQuestions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, true, true);
        if (count($easyPool) === 0) {
            $easyPool = filter_question_pool($easyCodeQuestions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, false, true);
        }
        if (count($easyPool) === 0) {
            $easyPool = filter_question_pool($easyCodeQuestions, $recentSet, $forumRecentSet, $recentFamiliesSet, $forumRecentTitles, false, false);
        }
    }

    // Bias toward coding questions (about 80%) while still rotating conceptual topics.
    $preferEasyChance = 80;
    if (count($easyPool) < 10) $preferEasyChance = 60;
    if (count($deepPool) < 10) $preferEasyChance = 90;
    if (count($easyPool) > (count($deepPool) + 24)) $preferEasyChance = 70;
    if (count($easyPool) <= 3) $preferEasyChance = 10;
    if (count($easyPool) <= 1) $preferEasyChance = 0;
    $preferEasyCode = (mt_rand(1, 100) <= $preferEasyChance);
    $pool = $preferEasyCode ? $easyPool : $deepPool;
    if (count($pool) === 0) {
        $pool = $preferEasyCode ? $deepPool : $easyPool;
    }

    // kirupa.com fallback/variation only for deep question mode when pool is constrained.
    if (!$preferEasyCode && count($pool) <= 2 && function_exists('kirupa_fetch_llms_links')) {
        $links = kirupa_fetch_llms_links();
        if (is_array($links) && count($links) > 0) {
            $link = $links[mt_rand(0, count($links) - 1)];
            $lt = trim((string)($link['title'] ?? ''));
            $lu = trim((string)($link['url'] ?? ''));
            if ($lt !== '' && $lu !== '') {
                $templates = array(
                    'How would you solve this architecture tradeoff',
                    'What is the practical frontend strategy here',
                    'How would you ship this without long term debt',
                    'What implementation pitfalls would you watch first',
                );
                $title = $templates[mt_rand(0, count($templates) - 1)];
                return array(
                    'title' => $title,
                    'raw' => 'Use this kirupa.com topic as a jumping-off point and discuss real implementation tradeoffs: ' . $lu . '. Focus on architecture, performance, and maintainability in production code.',
                    'question_type' => 'deep',
                    'language' => 'mixed',
                    'family' => question_family_key($title, $lu, 'mixed'),
                );
            }
        }
    }

    if (count($pool) === 0) {
        $fallback = $hasDeep ? $deepQuestions[0] : $easyCodeQuestions[0];
        if (!isset($fallback['question_type'])) $fallback['question_type'] = $hasDeep ? 'deep' : 'easy_code';
        if (!isset($fallback['language'])) $fallback['language'] = 'mixed';
        return $fallback;
    }

    $picked = $pool[mt_rand(0, count($pool) - 1)];
    if (!isset($picked['question_type'])) $picked['question_type'] = 'deep';
    if (!isset($picked['language'])) $picked['language'] = 'mixed';
    if (!isset($picked['family']) || trim((string)$picked['family']) === '') {
        $picked['family'] = question_family_key((string)($picked['title'] ?? ''), (string)($picked['raw'] ?? ''), (string)($picked['language'] ?? 'mixed'));
    }
    return $picked;
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

function post_topic($botUsername, $title, $raw, $categoryId)
{
    $title = ensure_question_mark_title((string)$title);
    $category = is_numeric($categoryId) ? (int)$categoryId : (int)KONVO_CATEGORY_ID;
    $payload = array(
        'title' => $title,
        'raw' => (string)$raw,
        'category' => $category,
    );

    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }

    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
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
        'ok' => ($err === '') && $status >= 200 && $status < 300 && is_array($decoded),
        'status' => $status,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
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
$bot = pick_bot($bots);
$keywordMap = deep_llm_keyword_map();
$keywordStats = array(
    'coding_tracks' => count(isset($keywordMap['coding_tracks']) && is_array($keywordMap['coding_tracks']) ? $keywordMap['coding_tracks'] : array()),
    'coding_problem_shapes' => count(isset($keywordMap['coding_problem_shapes']) && is_array($keywordMap['coding_problem_shapes']) ? $keywordMap['coding_problem_shapes'] : array()),
    'concept_tracks' => count(isset($keywordMap['concept_tracks']) && is_array($keywordMap['concept_tracks']) ? $keywordMap['concept_tracks'] : array()),
    'animation_pixelart' => count(isset($keywordMap['animation_pixelart']) && is_array($keywordMap['animation_pixelart']) ? $keywordMap['animation_pixelart'] : array()),
    'avoid_motifs' => count(isset($keywordMap['avoid_motifs']) && is_array($keywordMap['avoid_motifs']) ? $keywordMap['avoid_motifs'] : array()),
);
$recentForumTitlesForLlm = recent_forum_titles(180);
$recentQuestionTitlesForLlm = load_recent_questions();
$preferCoding = (mt_rand(1, 100) <= 80);
$liveGen = deep_generate_single_live_question($recentForumTitlesForLlm, $recentQuestionTitlesForLlm, $preferCoding, $bot);
$retryAttempted = false;
if (empty($liveGen['ok']) && $preferCoding) {
    $retryAttempted = true;
    $liveGen = deep_generate_single_live_question($recentForumTitlesForLlm, $recentQuestionTitlesForLlm, false, $bot);
}
if (empty($liveGen['ok'])) {
    out_json(503, array(
        'ok' => false,
        'posted' => false,
        'error' => 'Live OpenAI question generation unavailable; skipping post.',
        'llm_generation_error' => (string)($liveGen['error'] ?? ''),
        'llm_generation_error_meta' => isset($liveGen['meta']) && is_array($liveGen['meta']) ? $liveGen['meta'] : array(),
        'live_mode_preferred' => $preferCoding ? 'coding' : 'conceptual',
        'live_mode_retry_attempted' => $retryAttempted,
        'pool_stats' => array(
            'keyword_sets' => $keywordStats,
            'llm_generated_coding' => ($preferCoding ? 1 : 0),
            'llm_generated_concept' => ($preferCoding ? 0 : 1),
        ),
    ));
}
$q = $liveGen['question'];
$topicTitle = ensure_question_mark_title(trim((string)$q['title']));
$signatureSeed = strtolower((string)($bot['username'] ?? '') . '|' . $topicTitle . '|deep-question');
$botSignature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BayMax'), $signatureSeed)
    : (function_exists('konvo_signature_base_name')
        ? konvo_signature_base_name((string)($bot['name'] ?? 'BayMax'))
        : (string)($bot['name'] ?? 'BayMax'));
$topicRaw = normalize_signature_once((string)$q['raw'], $botSignature);
$categoryId = deep_question_category_id($topicTitle, $topicRaw);

if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_deep_question_topic',
        'bot' => $bot,
        'topic' => array(
            'title' => $topicTitle,
            'category_id' => $categoryId,
            'question_type' => (string)($q['question_type'] ?? 'deep'),
            'language' => (string)($q['language'] ?? 'mixed'),
            'family' => (string)($q['family'] ?? question_family_key((string)($q['title'] ?? ''), (string)($q['raw'] ?? ''), (string)($q['language'] ?? 'mixed'))),
            'seed_source' => (string)($q['seed_source'] ?? 'llm_generated'),
            'seed_url' => (string)($q['seed_url'] ?? ''),
            'raw_preview' => $topicRaw,
        ),
        'live_generation' => array(
            'mode' => (string)($liveGen['mode'] ?? ($preferCoding ? 'coding' : 'conceptual')),
            'mode_preferred' => $preferCoding ? 'coding' : 'conceptual',
            'animation_pixelart_focus' => !empty($liveGen['animation_pixelart_focus']),
            'retry_attempted' => $retryAttempted,
            'meta' => isset($liveGen['meta']) && is_array($liveGen['meta']) ? $liveGen['meta'] : array(),
        ),
        'pool_stats' => array(
            'keyword_sets' => $keywordStats,
            'llm_generated_coding' => ($preferCoding ? 1 : 0),
            'llm_generated_concept' => ($preferCoding ? 0 : 1),
            'llm_generation_error' => '',
            'llm_generation_error_meta' => array(),
        ),
    ));
}

$res = post_topic((string)$bot['username'], $topicTitle, $topicRaw, $categoryId);
if (!$res['ok']) {
    out_json(500, array(
        'ok' => false,
        'error' => 'Failed to post deep-question topic.',
        'status' => $res['status'],
        'curl_error' => $res['error'],
        'response' => $res['body'],
        'raw' => $res['raw'],
    ));
}

$topicId = (int)($res['body']['topic_id'] ?? 0);
$postNumber = (int)($res['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
remember_question_title($topicTitle);
remember_question_family((string)($q['title'] ?? ''), (string)($q['raw'] ?? ''), (string)($q['language'] ?? 'mixed'));

out_json(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_deep_question_topic',
    'topic_url' => $topicUrl,
    'bot' => $bot,
    'topic' => array(
        'title' => $topicTitle,
        'category_id' => $categoryId,
        'question_type' => (string)($q['question_type'] ?? 'deep'),
        'language' => (string)($q['language'] ?? 'mixed'),
        'family' => (string)($q['family'] ?? question_family_key((string)($q['title'] ?? ''), (string)($q['raw'] ?? ''), (string)($q['language'] ?? 'mixed'))),
        'seed_source' => (string)($q['seed_source'] ?? 'llm_generated'),
        'seed_url' => (string)($q['seed_url'] ?? ''),
    ),
));
