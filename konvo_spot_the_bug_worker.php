<?php

/*
 * Browser-callable spot-the-bug topic poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_spot_the_bug_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_spot_the_bug_worker.php?key=YOUR_SECRET&dry_run=1
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$signatureHelper = __DIR__ . '/konvo_signature_helper.php';
if (is_file($signatureHelper)) {
    require_once $signatureHelper;
}
$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'gpt-5.2';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_TZ')) define('KONVO_TZ', trim((string)(getenv('KONVO_TIMEZONE') ?: 'America/Los_Angeles')));

@date_default_timezone_set(KONVO_TZ);

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

function spot_out(int $code, array $data): void
{
    if (function_exists('http_response_code')) {
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function spot_safe_hash_equals(string $a, string $b): bool
{
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

function spot_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/spot_the_bug_state.json';
}

function spot_load_state(): array
{
    $path = spot_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function spot_save_state(array $state): void
{
    @file_put_contents(spot_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function spot_pick_bot(array $bots): array
{
    if ($bots === array()) {
        return array('username' => 'BayMax', 'name' => 'BayMax');
    }
    shuffle($bots);
    return $bots[0];
}

function spot_norm_lang(string $lang): string
{
    $v = strtolower(trim($lang));
    if ($v === 'js' || $v === 'javascript' || $v === 'node') return 'js';
    if ($v === 'ts' || $v === 'typescript') return 'ts';
    if ($v === 'html') return 'html';
    if ($v === 'css') return 'css';
    if ($v === 'php') return 'php';
    if ($v === 'python' || $v === 'py') return 'python';
    return 'js';
}

function spot_recent_vals(array $state, string $key, int $max = 12): array
{
    $out = array();
    if (isset($state[$key]) && is_array($state[$key])) {
        foreach ($state[$key] as $v) {
            $s = strtolower(trim((string)$v));
            if ($s === '') continue;
            if (!in_array($s, $out, true)) $out[] = $s;
            if (count($out) >= $max) break;
        }
    }
    return $out;
}

function spot_source_seeds(): array
{
    return array(
        array('theme' => 'animation', 'source_name' => 'kirupa Web Animation', 'source_url' => 'https://www.kirupa.com/html5/learn_animation.htm'),
        array('theme' => 'animation', 'source_name' => 'kirupa Animation in JavaScript', 'source_url' => 'https://www.kirupa.com/html5/animating_in_code_using_javascript.htm'),
        array('theme' => 'dom-events', 'source_name' => 'kirupa JavaScript Events', 'source_url' => 'https://www.kirupa.com/html5/introduction_to_javascript_events.htm'),
        array('theme' => 'text-manipulation', 'source_name' => 'MDN String docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String'),
        array('theme' => 'logic', 'source_name' => 'MDN Control flow', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Control_flow_and_error_handling'),
        array('theme' => 'forms', 'source_name' => 'MDN FormData', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/FormData'),
        array('theme' => 'arrays', 'source_name' => 'MDN Array docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array'),
        array('theme' => 'objects', 'source_name' => 'MDN Object docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object'),
        array('theme' => 'dom', 'source_name' => 'MDN DOM API', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Document_Object_Model'),
        array('theme' => 'css-layout', 'source_name' => 'MDN CSS guides', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/CSS'),
        array('theme' => 'canvas', 'source_name' => 'kirupa Canvas Intro', 'source_url' => 'https://www.kirupa.com/html5/getting_started_with_canvas.htm'),
        array('theme' => 'regex', 'source_name' => 'MDN Regular expressions', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions'),
        array('theme' => 'coding-exercises', 'source_name' => 'kirupa Coding Exercises', 'source_url' => 'https://www.kirupa.com/codingexercises/index.htm'),
    );
}

function spot_pick_seed(array $state): array
{
    $seeds = spot_source_seeds();
    $recentThemes = spot_recent_vals($state, 'recent_themes', 8);
    $pool = array();
    foreach ($seeds as $s) {
        $k = strtolower(trim((string)($s['theme'] ?? '')));
        if ($k !== '' && !in_array($k, $recentThemes, true)) $pool[] = $s;
    }
    if ($pool === array()) $pool = $seeds;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function spot_pick_difficulty(array $state): string
{
    $choices = array('Extremely Easy', 'Easy', 'Medium', 'Hard', 'Extremely Hard');
    $recent = spot_recent_vals($state, 'recent_difficulties', 5);
    $pool = array();
    foreach ($choices as $c) {
        if (!in_array(strtolower($c), $recent, true)) $pool[] = $c;
    }
    if ($pool === array()) $pool = $choices;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function spot_pick_size(array $state): string
{
    $choices = array('tiny', 'short', 'medium', 'long', 'xlong');
    $recent = spot_recent_vals($state, 'recent_sizes', 5);
    $pool = array();
    foreach ($choices as $c) {
        if (!in_array(strtolower($c), $recent, true)) $pool[] = $c;
    }
    if ($pool === array()) $pool = $choices;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function spot_fallback_cases(): array
{
    return array(
        array(
            'language' => 'js',
            'difficulty' => 'Easy',
            'theme' => 'animation',
            'snippet_size' => 'tiny',
            'source_name' => 'kirupa Animating with requestAnimationFrame',
            'source_url' => 'https://www.kirupa.com/animations/ensuring_consistent_animation_speeds.htm',
            'lead' => 'Find the bug in this animation loop.',
            'code' => "let x = 0;\nfunction tick() {\n  x + 2;\n  requestAnimationFrame(tick);\n}\ntick();",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Hard',
            'theme' => 'logic',
            'snippet_size' => 'long',
            'source_name' => 'MDN for...of',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Statements/for...of',
            'lead' => 'There is one subtle logic bug.',
            'code' => "function hasDuplicate(nums) {\n  const seen = new Set();\n  for (const n of nums) {\n    if (seen.has(n)) {\n      return false;\n    }\n    seen.add(n);\n  }\n  return true;\n}\n\nconsole.log(hasDuplicate([2, 7, 4, 7]));",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Medium',
            'theme' => 'text-manipulation',
            'snippet_size' => 'short',
            'source_name' => 'MDN String.trim',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/trim',
            'lead' => 'This text cleanup has one bug.',
            'code' => "function normalizeTag(tag) {\n  return tag.trim().toLowercase();\n}\n\nconsole.log(normalizeTag('  JavaScript  '));",
        ),
        array(
            'language' => 'js',
            'difficulty' => 'Easy',
            'theme' => 'dom-events',
            'snippet_size' => 'short',
            'source_name' => 'MDN addEventListener',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/EventTarget/addEventListener',
            'lead' => 'Quick one: event handling bug here.',
            'code' => "const button = document.querySelector('#save');\nbutton.addEventListener('click', saveForm());\n\nfunction saveForm() {\n  console.log('saved');\n}",
        ),
        array(
            'language' => 'html',
            'difficulty' => 'Extremely Easy',
            'theme' => 'forms',
            'snippet_size' => 'tiny',
            'source_name' => 'MDN Forms',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Forms',
            'lead' => 'Can you spot the form bug?',
            'code' => "<form>\n  <label>Email</label>\n  <input type=\"email\" id=\"email\">\n  <button type=\"submit\">Join</button>\n</form>\n<script>\n  document.querySelector('form').addEventListener('submit', (e) => {\n    if (!email.value.includes('@')) alert('invalid');\n  });\n</script>",
        ),
        array(
            'language' => 'css',
            'difficulty' => 'Extremely Hard',
            'theme' => 'css-layout',
            'snippet_size' => 'xlong',
            'source_name' => 'MDN Grid Layout',
            'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_grid_layout',
            'lead' => 'Layout bug is hidden in plain sight.',
            'code' => ".dashboard {\n  display: grid;\n  grid-template-columns: repeat(3, minmax(120px, 1fr));\n  gap: 12px;\n}\n.card {\n  grid-column: span 4;\n  padding: 12px;\n  border: 1px solid #ddd;\n}\n@media (max-width: 700px) {\n  .dashboard {\n    grid-template-columns: 1fr;\n  }\n}",
        ),
    );
}

function spot_is_overused_case(array $case): bool
{
    $theme = strtolower(trim((string)($case['theme'] ?? '')));
    $lead = strtolower(trim((string)($case['lead'] ?? '')));
    $code = strtolower((string)($case['code'] ?? ''));
    $blob = $theme . "\n" . $lead . "\n" . $code;
    return (bool)preg_match('/\b(lru|cache|memoiz|new\s+map\s*\(|map\s*\(\)|map\s*\[|hashmap)\b/i', $blob);
}

function spot_extract_json_object(string $content): ?array
{
    $content = trim($content);
    if ($content === '') return null;

    $decoded = json_decode($content, true);
    if (is_array($decoded)) return $decoded;

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode((string)$slice, true);
    return is_array($decoded) ? $decoded : null;
}

function spot_openai_json(array $payload): array
{
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY missing.');
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable.');
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 70,
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
        return array('ok' => false, 'error' => ($err !== '' ? $err : 'OpenAI request failed.'));
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'error' => 'OpenAI JSON decode failed.', 'raw' => (string)$raw, 'status' => $status);
    }
    if ($status < 200 || $status >= 300) {
        $msg = '';
        if (isset($decoded['error']['message'])) {
            $msg = (string)$decoded['error']['message'];
        }
        return array('ok' => false, 'error' => ($msg !== '' ? $msg : 'OpenAI returned status ' . $status), 'body' => $decoded, 'status' => $status);
    }

    return array('ok' => true, 'body' => $decoded, 'status' => $status);
}

function spot_generate_case_llm(array $state): array
{
    $recentLeads = array();
    if (isset($state['recent_leads']) && is_array($state['recent_leads'])) {
        foreach ($state['recent_leads'] as $v) {
            $s = trim((string)$v);
            if ($s !== '') $recentLeads[] = $s;
            if (count($recentLeads) >= 10) break;
        }
    }
    $avoid = $recentLeads === array() ? '(none)' : implode('; ', $recentLeads);
    $targetDifficulty = spot_pick_difficulty($state);
    $targetSize = spot_pick_size($state);
    $recentThemes = spot_recent_vals($state, 'recent_themes', 10);
    $themeAvoid = $recentThemes === array() ? '(none)' : implode(', ', $recentThemes);
    $attemptErrors = array();
    $triedThemes = array();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $seed = spot_pick_seed($state);
        $sourceName = (string)($seed['source_name'] ?? 'MDN');
        $sourceUrl = (string)($seed['source_url'] ?? 'https://developer.mozilla.org/');
        $theme = strtolower(trim((string)($seed['theme'] ?? 'javascript')));
        if (in_array($theme, $triedThemes, true) && $attempt < 4) {
            continue;
        }
        $triedThemes[] = $theme;

        $system = 'You generate short forum coding challenges. Return strict JSON only.';
        $user = "Give me a coding example that has a bug in it, and the expectation is for the reader to detect the bug. "
            . "The coding example has to be HTML/CSS/JS based, and it should be a small self contained example or code snippet. "
            . "Keep the example itself quirky. The target difficulty should be for a beginner or intermediate.\n\n"
            . "Now adapt this into one web-dev forum \"Spot the Bug\" post.\n"
            . "Use this source as conceptual inspiration: {$sourceName} ({$sourceUrl}). Do not copy text verbatim.\n"
            . "Primary theme: {$theme}\n"
            . "Target difficulty: {$targetDifficulty}\n"
            . "Target snippet size: {$targetSize}\n"
            . "Recent themes to avoid repeating: {$themeAvoid}\n"
            . "Do not use cache/Map/LRU/memoization examples unless the theme is explicitly collections.\n"
            . "Prefer variety across animation, logic, text manipulation, DOM events, forms, CSS layout, and array/object basics.\n"
            . "Return JSON with keys: language, lead, code, difficulty, theme, source_name, source_url, snippet_size.\n"
            . "Rules:\n"
            . "- language must be one of: js, ts, html, css.\n"
            . "- lead must be one short sentence (4-12 words), casual, no emoji.\n"
            . "- difficulty must be one of: Extremely Easy, Easy, Medium, Hard, Extremely Hard.\n"
            . "- snippet_size must be one of: tiny, short, medium, long, xlong.\n"
            . "- code length target by snippet_size: tiny=3-5 lines, short=6-9 lines, medium=10-15 lines, long=16-24 lines, xlong=25-40 lines.\n"
            . "- code must contain exactly one deliberate bug.\n"
            . "- no answer, no explanation, no comments revealing the bug.\n"
            . "- avoid repeating recent leads: {$avoid}\n"
            . "- output JSON only, no markdown.";

        $payload = array(
            'model' => konvo_model_for_task('deep_question', array('technical' => true)),
            'temperature' => 0.9,
            'max_tokens' => 500,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
        );

        $res = spot_openai_json($payload);
        if (!$res['ok']) {
            $attemptErrors[] = (string)($res['error'] ?? 'OpenAI request failed');
            continue;
        }

        $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
        $obj = spot_extract_json_object($content);
        if (!is_array($obj)) {
            $attemptErrors[] = 'OpenAI response format error';
            continue;
        }

        $lang = spot_norm_lang((string)($obj['language'] ?? ''));
        $lead = trim((string)($obj['lead'] ?? ''));
        $code = rtrim((string)($obj['code'] ?? ''));
        $difficulty = trim((string)($obj['difficulty'] ?? $targetDifficulty));
        $pickedTheme = trim((string)($obj['theme'] ?? $theme));
        $pickedSourceName = trim((string)($obj['source_name'] ?? $sourceName));
        $pickedSourceUrl = trim((string)($obj['source_url'] ?? $sourceUrl));
        $pickedSize = strtolower(trim((string)($obj['snippet_size'] ?? $targetSize)));
        if ($lead === '' || $code === '') {
            $attemptErrors[] = 'OpenAI response missing fields';
            continue;
        }
        if (substr_count($code, "\n") < 2) {
            $attemptErrors[] = 'OpenAI code snippet too short';
            continue;
        }

        $candidate = array(
            'language' => $lang,
            'lead' => $lead,
            'code' => $code,
            'difficulty' => $difficulty,
            'theme' => $pickedTheme,
            'source_name' => $pickedSourceName,
            'source_url' => $pickedSourceUrl,
            'snippet_size' => $pickedSize,
        );
        if (spot_is_overused_case($candidate) && $attempt < 4) {
            $attemptErrors[] = 'Rejected repetitive cache/map style case';
            continue;
        }

        return array('ok' => true, 'case' => $candidate);
    }

    return array(
        'ok' => false,
        'error' => 'LLM generation failed after retries',
        'attempt_errors' => $attemptErrors,
    );
}

function spot_pick_case(array $state): array
{
    $gen = spot_generate_case_llm($state);
    $targetDifficulty = spot_pick_difficulty($state);
    $targetSize = spot_pick_size($state);
    if (!empty($gen['ok']) && isset($gen['case']) && is_array($gen['case'])) {
        $case = $gen['case'];
        $case['_origin'] = 'llm';
        if (!isset($case['theme']) || trim((string)$case['theme']) === '') $case['theme'] = 'javascript';
        if (!isset($case['difficulty']) || trim((string)$case['difficulty']) === '') $case['difficulty'] = 'Medium';
        if (!isset($case['snippet_size']) || trim((string)$case['snippet_size']) === '') $case['snippet_size'] = 'medium';
        if (!isset($case['source_name'])) $case['source_name'] = '';
        if (!isset($case['source_url'])) $case['source_url'] = '';
        $case['_target_difficulty'] = $targetDifficulty;
        $case['_target_size'] = $targetSize;
        return $case;
    }

    $fallback = spot_fallback_cases();
    if ($fallback === array()) {
        return array(
            'language' => 'js',
            'lead' => 'Spot the bug in this snippet.',
            'code' => "console.log('hello');",
        );
    }
    $pool = array();
    foreach ($fallback as $c) {
        $cd = strtolower(trim((string)($c['difficulty'] ?? '')));
        $cs = strtolower(trim((string)($c['snippet_size'] ?? '')));
        if ($cd === strtolower($targetDifficulty) && $cs === strtolower($targetSize)) {
            $pool[] = $c;
        }
    }
    if ($pool === array()) {
        foreach ($fallback as $c) {
            $cd = strtolower(trim((string)($c['difficulty'] ?? '')));
            if ($cd === strtolower($targetDifficulty)) $pool[] = $c;
        }
    }
    if ($pool === array()) {
        foreach ($fallback as $c) {
            $cs = strtolower(trim((string)($c['snippet_size'] ?? '')));
            if ($cs === strtolower($targetSize)) $pool[] = $c;
        }
    }
    if ($pool === array()) $pool = $fallback;

    $idx = mt_rand(0, count($pool) - 1);
    $picked = $pool[$idx];
    $picked['language'] = spot_norm_lang((string)($picked['language'] ?? 'js'));
    if (!isset($picked['theme'])) $picked['theme'] = 'javascript';
    if (!isset($picked['difficulty'])) $picked['difficulty'] = 'Medium';
    if (!isset($picked['snippet_size'])) $picked['snippet_size'] = 'medium';
    if (!isset($picked['source_name'])) $picked['source_name'] = '';
    if (!isset($picked['source_url'])) $picked['source_url'] = '';
    $picked['_origin'] = 'fallback';
    $picked['_target_difficulty'] = $targetDifficulty;
    $picked['_target_size'] = $targetSize;
    return $picked;
}

function spot_build_raw(array $case, string $signature): string
{
    $lead = trim((string)($case['lead'] ?? 'Spot the bug in this snippet.'));
    if ($lead === '') $lead = 'Spot the bug in this snippet.';
    $lang = spot_norm_lang((string)($case['language'] ?? 'js'));
    $code = rtrim((string)($case['code'] ?? "console.log('hello');"));
    if ($code === '') $code = "console.log('hello');";

    $lines = array();
    $lines[] = $lead;
    $lines[] = '';
    $lines[] = '```' . $lang;
    $lines[] = $code;
    $lines[] = '```';
    $lines[] = '';
    $lines[] = 'Reply with what is broken and how you would fix it.';
    $body = trim(implode("\n", $lines));

    return $body;
}

function spot_post_topic(string $botUsername, string $title, string $raw): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => null, 'raw' => '');
    }

    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => (int)KONVO_WEBDEV_CATEGORY_ID,
    );

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
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => (string)$body,
    );
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    spot_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !spot_safe_hash_equals(KONVO_SECRET, $providedKey)) {
    spot_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    spot_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$allowNewTopicsEnv = strtolower(trim((string)getenv('KONVO_ALLOW_NEW_TOPICS')));
$allowNewTopics = in_array($allowNewTopicsEnv, array('1', 'true', 'yes', 'on'), true);

if (!$dryRun && !$allowNewTopics && !$force) {
    spot_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'new_topic_creation_disabled',
        'hint' => 'Set KONVO_ALLOW_NEW_TOPICS=1 or pass force=1 to override.',
    ));
}

$state = spot_load_state();
$lastNumber = (int)($state['last_number'] ?? 0);
$nextNumber = max(1, $lastNumber + 1);
$title = 'Spot the bug - #' . $nextNumber;

$bot = spot_pick_bot($bots);
$signatureSeed = strtolower((string)($bot['username'] ?? '') . '|' . $title . '|spot-the-bug');
$botSignature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BayMax'), $signatureSeed)
    : (function_exists('konvo_signature_base_name')
        ? konvo_signature_base_name((string)($bot['name'] ?? 'BayMax'))
        : (string)($bot['name'] ?? 'BayMax'));
$case = spot_pick_case($state);
$raw = spot_build_raw($case, $botSignature);

if ($dryRun) {
    spot_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_spot_the_bug',
        'bot' => $bot,
        'topic' => array(
            'title' => $title,
            'category_id' => (int)KONVO_WEBDEV_CATEGORY_ID,
            'difficulty' => (string)($case['difficulty'] ?? ''),
            'theme' => (string)($case['theme'] ?? ''),
            'snippet_size' => (string)($case['snippet_size'] ?? ''),
            'source_name' => (string)($case['source_name'] ?? ''),
            'source_url' => (string)($case['source_url'] ?? ''),
            'origin' => (string)($case['_origin'] ?? ''),
            'target_difficulty' => (string)($case['_target_difficulty'] ?? ''),
            'target_size' => (string)($case['_target_size'] ?? ''),
            'raw_preview' => $raw,
        ),
    ));
}

$postRes = spot_post_topic((string)$bot['username'], $title, $raw);
if (!$postRes['ok']) {
    spot_out(500, array(
        'ok' => false,
        'error' => 'Failed to post Spot the bug topic.',
        'status' => (int)($postRes['status'] ?? 0),
        'curl_error' => (string)($postRes['error'] ?? ''),
        'response' => $postRes['body'],
        'raw' => (string)($postRes['raw'] ?? ''),
    ));
}

$topicId = (int)($postRes['body']['topic_id'] ?? 0);
$postNumber = (int)($postRes['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

$recentLeads = isset($state['recent_leads']) && is_array($state['recent_leads']) ? $state['recent_leads'] : array();
array_unshift($recentLeads, (string)($case['lead'] ?? ''));
$cleanLeads = array();
foreach ($recentLeads as $v) {
    $s = trim((string)$v);
    if ($s === '') continue;
    if (!in_array($s, $cleanLeads, true)) $cleanLeads[] = $s;
}
$cleanLeads = array_slice($cleanLeads, 0, 20);

$recentThemes = isset($state['recent_themes']) && is_array($state['recent_themes']) ? $state['recent_themes'] : array();
array_unshift($recentThemes, strtolower(trim((string)($case['theme'] ?? ''))));
$recentThemes = array_values(array_filter(array_unique(array_map('strval', $recentThemes))));
$recentThemes = array_slice($recentThemes, 0, 20);

$recentDiff = isset($state['recent_difficulties']) && is_array($state['recent_difficulties']) ? $state['recent_difficulties'] : array();
array_unshift($recentDiff, strtolower(trim((string)($case['difficulty'] ?? ''))));
$recentDiff = array_values(array_filter(array_unique(array_map('strval', $recentDiff))));
$recentDiff = array_slice($recentDiff, 0, 20);

$recentSizes = isset($state['recent_sizes']) && is_array($state['recent_sizes']) ? $state['recent_sizes'] : array();
array_unshift($recentSizes, strtolower(trim((string)($case['snippet_size'] ?? ''))));
$recentSizes = array_values(array_filter(array_unique(array_map('strval', $recentSizes))));
$recentSizes = array_slice($recentSizes, 0, 20);

$recentSources = isset($state['recent_sources']) && is_array($state['recent_sources']) ? $state['recent_sources'] : array();
array_unshift($recentSources, strtolower(trim((string)($case['source_name'] ?? ''))));
$recentSources = array_values(array_filter(array_unique(array_map('strval', $recentSources))));
$recentSources = array_slice($recentSources, 0, 20);

$state['last_number'] = $nextNumber;
$state['last_posted_at'] = time();
$state['last_topic_id'] = $topicId;
$state['last_post_number'] = $postNumber;
$state['last_title'] = $title;
$state['recent_leads'] = $cleanLeads;
$state['recent_themes'] = $recentThemes;
$state['recent_difficulties'] = $recentDiff;
$state['recent_sizes'] = $recentSizes;
$state['recent_sources'] = $recentSources;
spot_save_state($state);

spot_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_spot_the_bug',
    'bot' => $bot,
    'topic' => array(
        'id' => $topicId,
        'post_number' => $postNumber,
        'title' => $title,
        'category_id' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'url' => $topicUrl,
        'difficulty' => (string)($case['difficulty'] ?? ''),
        'theme' => (string)($case['theme'] ?? ''),
        'snippet_size' => (string)($case['snippet_size'] ?? ''),
        'source_name' => (string)($case['source_name'] ?? ''),
        'source_url' => (string)($case['source_url'] ?? ''),
        'origin' => (string)($case['_origin'] ?? ''),
        'target_difficulty' => (string)($case['_target_difficulty'] ?? ''),
        'target_size' => (string)($case['_target_size'] ?? ''),
    ),
));
