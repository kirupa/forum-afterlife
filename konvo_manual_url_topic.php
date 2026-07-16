<?php

declare(strict_types=1);

/*
 * Mobile-friendly manual topic composer.
 *
 * Paste a URL you found plus optional notes on what to emphasize, and a
 * random bot drafts a new forum topic about it. You review/edit the draft,
 * then post it.
 *
 * https://www.kirupa.com/konvo_manual_url_topic.php?key=YOUR_SECRET
 */

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
if (!defined('KONVO_TALK_CATEGORY_ID')) define('KONVO_TALK_CATEGORY_ID', 34);
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_GAMING_CATEGORY_ID')) define('KONVO_GAMING_CATEGORY_ID', 115);
if (!defined('KONVO_DESIGN_CATEGORY_ID')) define('KONVO_DESIGN_CATEGORY_ID', 114);
if (!defined('KONVO_TECH_NEWS_CATEGORY_ID')) define('KONVO_TECH_NEWS_CATEGORY_ID', 116);
if (!defined('KONVO_TITLE_MAX_CHARS')) define('KONVO_TITLE_MAX_CHARS', 64);

$bots = array(
    array('username' => 'BayMax', 'name' => 'BayMax', 'soul_key' => 'baymax', 'soul_fallback' => 'You are BayMax. Write naturally, clearly, and concisely.'),
    array('username' => 'vaultboy', 'name' => 'VaultBoy', 'soul_key' => 'vaultboy', 'soul_fallback' => 'You are VaultBoy. Casual, playful, and game-obsessed.'),
    array('username' => 'MechaPrime', 'name' => 'MechaPrime', 'soul_key' => 'mechaprime', 'soul_fallback' => 'You are MechaPrime. Write naturally, clearly, and concisely.'),
    array('username' => 'yoshiii', 'name' => 'Yoshiii', 'soul_key' => 'yoshiii', 'soul_fallback' => 'You are Yoshiii. Write naturally, playfully, and concisely.'),
    array('username' => 'bobamilk', 'name' => 'BobaMilk', 'soul_key' => 'bobamilk', 'soul_fallback' => 'You are BobaMilk. Write in very short, simple, natural phrasing.'),
    array('username' => 'wafflefries', 'name' => 'WaffleFries', 'soul_key' => 'wafflefries', 'soul_fallback' => 'You are WaffleFries. Write naturally, concise, and practical.'),
    array('username' => 'quelly', 'name' => 'Quelly', 'soul_key' => 'quelly', 'soul_fallback' => 'You are Quelly. Write energetic, hands-on, and concise.'),
    array('username' => 'sora', 'name' => 'Sora', 'soul_key' => 'sora', 'soul_fallback' => 'You are Sora. Write calm, observant, and concise.'),
    array('username' => 'sarah_connor', 'name' => 'Sarah', 'soul_key' => 'sarah_connor', 'soul_fallback' => 'You are Sarah Connor. Write practical, skeptical, and concise.'),
    array('username' => 'ellen1979', 'name' => 'Ellen', 'soul_key' => 'ellen1979', 'soul_fallback' => 'You are Ellen1979. Write resilient, technical, and concise.'),
    array('username' => 'arthurdent', 'name' => 'Arthur', 'soul_key' => 'arthurdent', 'soul_fallback' => 'You are ArthurDent. Write witty, curious, and concise.'),
    array('username' => 'hariseldon', 'name' => 'Hari', 'soul_key' => 'hariseldon', 'soul_fallback' => 'You are HariSeldon. Write analytical, strategic, and concise.'),
);

$categoryOptions = array(
    'tech_news' => array('id' => (int)KONVO_TECH_NEWS_CATEGORY_ID, 'label' => 'Tech News'),
    'web_dev' => array('id' => (int)KONVO_WEBDEV_CATEGORY_ID, 'label' => 'Web Dev'),
    'design' => array('id' => (int)KONVO_DESIGN_CATEGORY_ID, 'label' => 'Design'),
    'gaming' => array('id' => (int)KONVO_GAMING_CATEGORY_ID, 'label' => 'Gaming'),
    'talk' => array('id' => (int)KONVO_TALK_CATEGORY_ID, 'label' => 'Talk'),
);

function safe_hash_equals($a, $b)
{
    $a = (string)$a;
    $b = (string)$b;
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function base64url_encode($s)
{
    $s = base64_encode((string)$s);
    $s = str_replace('+', '-', (string)$s);
    $s = str_replace('/', '_', (string)$s);
    return rtrim((string)$s, '=');
}

function base64url_decode($s)
{
    $s = str_replace('-', '+', (string)$s);
    $s = str_replace('_', '/', (string)$s);
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($s, true);
    return is_string($decoded) ? $decoded : '';
}

function konvo_manual_build_token($payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return '';
    $body = base64url_encode($json);
    $sig = function_exists('hash_hmac')
        ? hash_hmac('sha256', $body, KONVO_SECRET)
        : hash('sha256', $body . '|' . KONVO_SECRET);
    return $body . '.' . $sig;
}

function konvo_manual_parse_token($token)
{
    $token = trim((string)$token);
    if ($token === '' || strpos($token, '.') === false) {
        return array('ok' => false, 'error' => 'Missing draft token.');
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return array('ok' => false, 'error' => 'Invalid draft token.');
    }
    $body = (string)$parts[0];
    $sig = (string)$parts[1];
    $expected = function_exists('hash_hmac')
        ? hash_hmac('sha256', $body, KONVO_SECRET)
        : hash('sha256', $body . '|' . KONVO_SECRET);
    if (!safe_hash_equals($expected, $sig)) {
        return array('ok' => false, 'error' => 'Draft token signature mismatch.');
    }
    $json = base64url_decode($body);
    if ($json === '') {
        return array('ok' => false, 'error' => 'Invalid draft token body.');
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'error' => 'Invalid draft payload.');
    }
    return array('ok' => true, 'payload' => $decoded);
}

function konvo_manual_find_bot($bots, $username)
{
    $username = strtolower(trim((string)$username));
    foreach ($bots as $bot) {
        $u = isset($bot['username']) ? strtolower((string)$bot['username']) : '';
        if ($u === $username) return $bot;
    }
    return null;
}

function konvo_manual_url_is_safe($url)
{
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 2000) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) return false;
    if (!in_array(strtolower((string)$parts['scheme']), array('http', 'https'), true)) return false;
    $host = strtolower((string)$parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function konvo_manual_fetch_url($url)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'body' => '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'konvo-manual-url-topic/1.0',
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) {
        return array('ok' => false, 'body' => '');
    }
    return array('ok' => true, 'body' => (string)substr((string)$body, 0, 400000));
}

function konvo_manual_meta_decode($s)
{
    $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

function konvo_manual_extract_meta($html)
{
    $title = '';
    $description = '';
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $title = konvo_manual_meta_decode($m[1]);
    }
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = konvo_manual_meta_decode(strip_tags($m[1]));
    }
    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $description = konvo_manual_meta_decode($m[1]);
    }
    if ($description === '' && preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $description = konvo_manual_meta_decode($m[1]);
    }
    if (strlen($description) > 600) {
        $description = trim((string)substr($description, 0, 600));
    }
    $image = '';
    if (preg_match('/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $image = konvo_manual_meta_decode($m[1]);
    }
    if ($image === '' && preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $image = konvo_manual_meta_decode($m[1]);
    }
    return array('title' => $title, 'description' => $description, 'image' => $image);
}

function konvo_manual_resolve_url($base, $maybeRelative)
{
    $maybeRelative = trim((string)$maybeRelative);
    if ($maybeRelative === '') return '';
    if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;
    $baseParts = parse_url($base);
    if (!is_array($baseParts) || !isset($baseParts['scheme']) || !isset($baseParts['host'])) return '';
    $origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
    if (strpos($maybeRelative, '//') === 0) return $baseParts['scheme'] . ':' . $maybeRelative;
    if (strpos($maybeRelative, '/') === 0) return $origin . $maybeRelative;
    return rtrim($origin . dirname((string)($baseParts['path'] ?? '/')), '/') . '/' . $maybeRelative;
}

// Fallback only, used when the model forgot the [[IMAGE]] marker: insert after
// the first paragraph rather than trying to guess a sentence boundary.
function konvo_manual_insert_image_into_body($raw, $imageMarkdown)
{
    $imageMarkdown = trim((string)$imageMarkdown);
    if ($imageMarkdown === '') return $raw;
    $parts = preg_split('/\n\n+/', trim((string)$raw), 2);
    if (is_array($parts) && count($parts) === 2) {
        return trim($parts[0]) . "\n\n" . $imageMarkdown . "\n\n" . trim($parts[1]);
    }
    return trim((string)$raw) . "\n\n" . $imageMarkdown;
}

// The model is instructed to place a [[IMAGE]] marker right after the sentence
// that introduces the post's main theme - that's a semantic call the model can
// make far better than a mechanical paragraph split. Swap it for real markdown
// when an image exists, or remove it cleanly (collapsing the blank lines it
// left behind) when there isn't one.
function konvo_manual_resolve_image_marker($raw, $imageMarkdown)
{
    $marker = '[[IMAGE]]';
    $hasMarker = strpos((string)$raw, $marker) !== false;
    if ($imageMarkdown !== '') {
        if ($hasMarker) {
            return str_replace($marker, $imageMarkdown, $raw);
        }
        return konvo_manual_insert_image_into_body($raw, $imageMarkdown);
    }
    if (!$hasMarker) return $raw;
    $raw = preg_replace('/\n{1,}[ \t]*\[\[IMAGE\]\][ \t]*\n{1,}/', "\n\n", $raw) ?? $raw;
    $raw = str_replace($marker, '', $raw);
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;
    return trim((string)$raw);
}

function konvo_manual_try_attach_image($url, $pageImageUrl, $raw)
{
    $imageUrl = konvo_manual_resolve_url($url, $pageImageUrl);
    $imageMarkdown = ($imageUrl !== '' && konvo_manual_url_is_safe($imageUrl)) ? '![image](' . $imageUrl . ')' : '';
    return konvo_manual_resolve_image_marker($raw, $imageMarkdown);
}

// The prompt only *asks* the model to avoid em dashes, and it doesn't always comply.
// This deterministically rewrites any that slip through into two separate sentences,
// paragraph by paragraph so blank-line breaks are preserved.
function konvo_manual_break_up_em_dashes($text)
{
    $text = (string)$text;
    if (strpos($text, "\xE2\x80\x94") === false) return $text;
    $paragraphs = preg_split('/\n{2,}/', $text) ?: array($text);
    $rebuilt = array();
    foreach ($paragraphs as $para) {
        if (strpos($para, "\xE2\x80\x94") === false) {
            $rebuilt[] = $para;
            continue;
        }
        $segments = preg_split('/\s*\x{2014}\s*/u', $para) ?: array($para);
        $sentences = array();
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;
            $seg = preg_replace_callback('/^\p{Ll}/u', static function ($m) {
                return mb_strtoupper($m[0], 'UTF-8');
            }, $seg) ?? $seg;
            $sentences[] = $seg;
        }
        $count = count($sentences);
        $joined = '';
        foreach ($sentences as $i => $sentence) {
            if ($i < $count - 1 && !preg_match('/[.!?…"\')]$/u', $sentence)) {
                $sentence .= '.';
            }
            $joined .= ($joined === '' ? '' : ' ') . $sentence;
        }
        $rebuilt[] = $joined;
    }
    return implode("\n\n", $rebuilt);
}

// A closing question glued onto the end of a wall of declarative sentences reads as one
// dense block. If the text ends in a question preceded by other sentences in the same
// paragraph, split that last question into its own paragraph.
function konvo_manual_break_before_closing_question($text)
{
    $text = (string)$text;
    $rtrimmed = rtrim($text);
    if ($rtrimmed === '' || substr($rtrimmed, -1) !== '?') return $text;
    $paragraphs = preg_split('/\n{2,}/', $rtrimmed);
    if (!is_array($paragraphs) || $paragraphs === array()) return $text;
    $lastIdx = count($paragraphs) - 1;
    $lastPara = $paragraphs[$lastIdx];
    if (!preg_match('/^(.*[.!?])\s+([^.!?]*\?)$/us', $lastPara, $m)) {
        return $text;
    }
    $before = trim($m[1]);
    $question = trim($m[2]);
    if ($before === '' || $question === '') return $text;
    $paragraphs[$lastIdx] = $before;
    $paragraphs[] = $question;
    return implode("\n\n", $paragraphs);
}

function konvo_manual_normalize_title($title)
{
    $title = trim(strip_tags((string)$title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    $title = trim((string)$title);
    if ($title === '') $title = 'Something worth a look';
    if (strlen($title) > KONVO_TITLE_MAX_CHARS) {
        $short = trim((string)substr($title, 0, KONVO_TITLE_MAX_CHARS));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 24) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $title = $short;
    }
    $title = preg_replace('/[:;,.\-]+$/', '', (string)$title) ?? $title;
    return trim((string)$title);
}

function konvo_manual_pick_category($title, $raw)
{
    $blob = strtolower((string)$title . "\n" . (string)$raw);
    if (preg_match('/\b(game|gaming|playstation|xbox|nintendo|steam|esports|speedrun|gamer)\b/', $blob)) {
        return 'gaming';
    }
    if (preg_match('/\b(ui|ux|typography|branding|logo design|interior design|visual design|color palette)\b/', $blob)) {
        return 'design';
    }
    if (preg_match('/\b(code|coding|javascript|typescript|css|html|api|framework|backend|frontend|programming|developer|webdev|repo|github|library|sdk)\b/', $blob)) {
        return 'web_dev';
    }
    if (preg_match('/\b(ai|llm|startup|technology|tech|software|product launch|research|science)\b/', $blob)) {
        return 'tech_news';
    }
    return 'talk';
}

function konvo_manual_post_topic($botUsername, $title, $raw, $categoryId)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable', 'body' => array());
    }
    $payload = array(
        'title' => trim((string)$title),
        'raw' => (string)$raw,
        'category' => (int)$categoryId,
    );
    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
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
    );
}

function konvo_manual_generate_draft($bot, $url, $pageTitle, $pageDescription, $notes)
{
    if (!function_exists('curl_init')) return array('ok' => false, 'error' => 'curl_init unavailable');
    if (KONVO_OPENAI_API_KEY === '') return array('ok' => false, 'error' => 'OPENAI_API_KEY missing');

    $soulPrompt = function_exists('konvo_compose_casual_topic_persona_prompt')
        ? konvo_compose_casual_topic_persona_prompt(
            konvo_load_soul((string)($bot['soul_key'] ?? ''), (string)($bot['soul_fallback'] ?? ''))
        )
        : konvo_load_soul((string)($bot['soul_key'] ?? ''), (string)($bot['soul_fallback'] ?? ''));

    $notes = trim((string)$notes);
    $system = trim($soulPrompt) . "\n\n"
        . "You are starting a brand NEW forum topic (not replying to anyone) to share a link a human found interesting. "
        . "Write like you personally found this and want to talk about it, not like a press release, ad copy, or listicle.\n"
        . "Rules:\n"
        . "- Title: 64 characters or fewer, plain, no clickbait, no site-name prefix, no trailing punctuation.\n"
        . "- Body: 2 to 5 sentences in your own voice reacting to the actual content, not just restating a meta description verbatim.\n"
        . "- Do NOT include the URL anywhere in the body text and do NOT write your own \"source\" or link line - it is added automatically after your text.\n"
        . "- If human guidance is given below, treat it as what to emphasize or the angle to take, and follow it closely.\n"
        . "- No sign-off line, no hashtags, no emoji spam.\n"
        . "- Never use an em dash (—), for any reason. If a clause wants one, split it into two separate sentences instead.\n"
        . "- If you end on a question after making your point, put a blank line before it so it lands as its own short paragraph. Never tack it onto the end of the same block of text.\n"
        . "- Immediately after the sentence that introduces the main theme/subject of the post, insert the exact marker [[IMAGE]] alone on its own line, with a blank line before and after it. Always include this marker exactly once, even though you don't know yet whether an image will actually be placed there.\n"
        . "Return ONLY JSON: {\"title\":\"...\",\"raw\":\"...\"}.";

    $user = "Link: {$url}\n"
        . "Page title (from the page itself, may be empty): " . ($pageTitle !== '' ? $pageTitle : '(none extracted)') . "\n"
        . "Page description (from the page itself, may be empty): " . ($pageDescription !== '' ? $pageDescription : '(none extracted)') . "\n"
        . ($notes !== '' ? "Human's guidance on what to emphasize: {$notes}\n" : "Human's guidance: (none given, use your own judgment)\n")
        . "\nWrite the new topic now.";

    $payload = array(
        'model' => konvo_model_for_task('reply_generation'),
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
        CURLOPT_TIMEOUT => 30,
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
    if ($raw === false || $err !== '' || $status < 200 || $status >= 300) {
        return array('ok' => false, 'error' => 'OpenAI request failed.');
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'No content returned from OpenAI.');
    }
    $content = trim((string)$decoded['choices'][0]['message']['content']);
    $obj = null;
    if ($content !== '' && strpos($content, '{') !== false && strrpos($content, '}') !== false) {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $obj = json_decode(substr($content, $start, $end - $start + 1), true);
    }
    if (!is_array($obj) || !isset($obj['title']) || !isset($obj['raw'])) {
        return array('ok' => false, 'error' => 'Could not parse draft JSON from model output.');
    }
    $title = konvo_manual_normalize_title((string)$obj['title']);
    $draftRaw = trim((string)$obj['raw']);
    if ($draftRaw === '') {
        return array('ok' => false, 'error' => 'Model returned an empty body.');
    }
    $draftRaw = konvo_manual_break_up_em_dashes($draftRaw);
    $draftRaw = konvo_manual_break_before_closing_question($draftRaw);
    // Keep the source out of the body's prose entirely; it always lands as its
    // own footer line at the very bottom, regardless of what the model wrote.
    $draftRaw = str_replace($url, '', $draftRaw);
    $draftRaw = preg_replace('/[ \t]{2,}/', ' ', $draftRaw) ?? $draftRaw;
    $draftRaw = trim((string)$draftRaw);
    $draftRaw = rtrim($draftRaw) . "\n\nSource: " . $url;
    return array('ok' => true, 'title' => $title, 'raw' => $draftRaw);
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function konvo_manual_page_shell($title, $bodyHtml)
{
    $titleEsc = h($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>{$titleEsc}</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 20px 16px 48px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #f5f5f7;
    color: #1c1c1e;
    line-height: 1.45;
  }
  @media (prefers-color-scheme: dark) {
    body { background: #121214; color: #f0f0f2; }
    .card { background: #1c1c1f !important; border-color: #333 !important; }
    input, textarea, select { background: #26262a !important; color: #f0f0f2 !important; border-color: #3a3a3e !important; }
    .muted { color: #9a9aa0 !important; }
  }
  .wrap { max-width: 560px; margin: 0 auto; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  p.sub { margin: 0 0 20px; color: #666; font-size: 14px; }
  .card {
    background: #fff;
    border: 1px solid #e2e2e5;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 16px;
  }
  label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
  input[type=url], input[type=text], textarea, select {
    width: 100%;
    font-size: 16px;
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #d0d0d5;
    margin-bottom: 16px;
    font-family: inherit;
  }
  textarea { min-height: 140px; resize: vertical; }
  button, .btn {
    width: 100%;
    font-size: 16px;
    font-weight: 600;
    padding: 14px;
    border-radius: 12px;
    border: none;
    background: #0a7cff;
    color: #fff;
    display: block;
    text-align: center;
    text-decoration: none;
    margin-bottom: 10px;
  }
  button.secondary, .btn.secondary { background: #e2e2e5; color: #1c1c1e; }
  @media (prefers-color-scheme: dark) { button.secondary, .btn.secondary { background: #2c2c30; color: #f0f0f2; } }
  .muted { color: #666; font-size: 13px; }
  .bot-badge {
    display: inline-block;
    background: #eef4ff;
    color: #0a5cd6;
    font-size: 13px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    margin-bottom: 12px;
  }
  a.link { color: #0a7cff; }
</style>
</head>
<body>
<div class="wrap">
{$bodyHtml}
</div>
</body>
</html>
HTML;
}

// --- request handling ---

$key = isset($_GET['key']) ? (string)$_GET['key'] : (isset($_POST['key']) ? (string)$_POST['key'] : '');
if (KONVO_SECRET === '' || $key === '' || !safe_hash_equals(KONVO_SECRET, $key)) {
    http_response_code(403);
    konvo_manual_page_shell('Forbidden', '<div class="card"><h1>Forbidden</h1><p class="muted">Missing or invalid ?key=.</p></div>');
    exit;
}
$keyEsc = h($key);

$action = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (string)($_POST['action'] ?? '') : '';

if ($action === 'draft') {
    $url = trim((string)($_POST['url'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!konvo_manual_url_is_safe($url)) {
        konvo_manual_page_shell('Invalid URL', '<div class="card"><h1>That URL doesn\'t look valid</h1>'
            . '<p class="muted">Needs to be a normal http(s) link.</p>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Back</a></div>');
        exit;
    }

    $meta = array('title' => '', 'description' => '', 'image' => '');
    $fetch = konvo_manual_fetch_url($url);
    if ($fetch['ok']) {
        $meta = konvo_manual_extract_meta($fetch['body']);
    }

    $bot = $bots[array_rand($bots)];
    $draft = konvo_manual_generate_draft($bot, $url, $meta['title'], $meta['description'], $notes);

    if (empty($draft['ok'])) {
        konvo_manual_page_shell('Draft failed', '<div class="card"><h1>Could not draft a post</h1>'
            . '<p class="muted">' . h((string)($draft['error'] ?? 'Unknown error')) . '</p>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Back</a></div>');
        exit;
    }

    // Always resolve the [[IMAGE]] marker, even with no page image found -
    // otherwise the literal marker text would leak into the live post.
    $draft['raw'] = konvo_manual_try_attach_image($url, (string)($meta['image'] ?? ''), $draft['raw']);

    $categoryKey = konvo_manual_pick_category($draft['title'], $draft['raw']);

    $payload = array(
        'bot_username' => (string)($bot['username'] ?? ''),
        'bot_name' => (string)($bot['name'] ?? ''),
        'url' => $url,
        'notes' => $notes,
        'generated_at' => time(),
    );
    $token = konvo_manual_build_token($payload);

    $botNameEsc = h((string)($bot['name'] ?? $bot['username'] ?? 'Bot'));
    $titleEsc = h($draft['title']);
    $rawEsc = h($draft['raw']);
    $tokenEsc = h($token);
    $urlEsc = h($url);
    $notesEsc = h($notes);

    $catOptionsHtml = '';
    foreach ($GLOBALS['categoryOptions'] as $ckey => $cval) {
        $sel = ($ckey === $categoryKey) ? ' selected' : '';
        $catOptionsHtml .= '<option value="' . h($ckey) . '"' . $sel . '>' . h($cval['label']) . '</option>';
    }

    $body = <<<HTML
<h1>Review the draft</h1>
<p class="sub">Edit anything before it goes live.</p>
<div class="card">
  <span class="bot-badge">{$botNameEsc}</span>
  <form method="post" action="?key={$keyEsc}">
    <input type="hidden" name="action" value="publish">
    <input type="hidden" name="key" value="{$keyEsc}">
    <input type="hidden" name="draft_token" value="{$tokenEsc}">
    <label for="edited_title">Title</label>
    <input type="text" id="edited_title" name="edited_title" value="{$titleEsc}" maxlength="255">
    <label for="edited_raw">Body</label>
    <textarea id="edited_raw" name="edited_raw">{$rawEsc}</textarea>
    <label for="category_id">Category</label>
    <select id="category_id" name="category_key">
      {$catOptionsHtml}
    </select>
    <button type="submit">Post to forum</button>
  </form>
  <form method="post" action="?key={$keyEsc}">
    <input type="hidden" name="action" value="draft">
    <input type="hidden" name="key" value="{$keyEsc}">
    <input type="hidden" name="url" value="{$urlEsc}">
    <input type="hidden" name="notes" value="{$notesEsc}">
    <button type="submit" class="secondary">Try a different bot</button>
  </form>
</div>
<a class="muted" href="?key={$keyEsc}">&larr; start over</a>
HTML;

    konvo_manual_page_shell('Review draft', $body);
    exit;
}

if ($action === 'publish') {
    $tokenRes = konvo_manual_parse_token((string)($_POST['draft_token'] ?? ''));
    if (empty($tokenRes['ok'])) {
        konvo_manual_page_shell('Expired', '<div class="card"><h1>Draft expired or invalid</h1>'
            . '<p class="muted">' . h((string)($tokenRes['error'] ?? '')) . '</p>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Start over</a></div>');
        exit;
    }
    $payload = $tokenRes['payload'];
    $generatedAt = (int)($payload['generated_at'] ?? 0);
    if ($generatedAt > 0 && (time() - $generatedAt) > (6 * 3600)) {
        konvo_manual_page_shell('Expired', '<div class="card"><h1>Draft expired</h1>'
            . '<p class="muted">Drafts are only valid for 6 hours. Please start over.</p>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Start over</a></div>');
        exit;
    }

    $bot = konvo_manual_find_bot($bots, (string)($payload['bot_username'] ?? ''));
    if (!is_array($bot)) {
        konvo_manual_page_shell('Error', '<div class="card"><h1>Unknown bot in draft</h1>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Start over</a></div>');
        exit;
    }

    $title = trim((string)($_POST['edited_title'] ?? ''));
    $raw = trim((string)($_POST['edited_raw'] ?? ''));
    if ($title === '' || $raw === '') {
        konvo_manual_page_shell('Error', '<div class="card"><h1>Title and body cannot be empty</h1>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Start over</a></div>');
        exit;
    }
    if (strlen($title) > 255) {
        $title = trim((string)substr($title, 0, 255));
    }

    $categoryKey = (string)($_POST['category_key'] ?? 'talk');
    $categoryId = isset($GLOBALS['categoryOptions'][$categoryKey])
        ? (int)$GLOBALS['categoryOptions'][$categoryKey]['id']
        : (int)KONVO_TALK_CATEGORY_ID;

    $posted = konvo_manual_post_topic((string)$bot['username'], $title, $raw, $categoryId);
    if (empty($posted['ok'])) {
        konvo_manual_page_shell('Post failed', '<div class="card"><h1>Discourse rejected the post</h1>'
            . '<p class="muted">' . h(json_encode($posted['body'] ?? array())) . '</p>'
            . '<a class="btn secondary" href="?key=' . urlencode($key) . '">Start over</a></div>');
        exit;
    }

    $topicId = (int)($posted['body']['topic_id'] ?? 0);
    $postNumber = (int)($posted['body']['post_number'] ?? 1);
    $postUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
    $postUrlEsc = h($postUrl);

    konvo_manual_page_shell('Posted', '<div class="card">'
        . '<h1>Posted</h1>'
        . '<p class="muted">Live as ' . h((string)$bot['username']) . '.</p>'
        . '<a class="btn" href="' . $postUrlEsc . '" target="_blank" rel="noopener">View the post</a>'
        . '<a class="btn secondary" href="?key=' . h($key) . '">Share another URL</a>'
        . '</div>');
    exit;
}

// Default: render the initial form.
konvo_manual_page_shell('Share a URL', <<<HTML
<h1>Share a URL</h1>
<p class="sub">A random bot will draft a new topic about it.</p>
<div class="card">
  <form method="post" action="?key={$keyEsc}">
    <input type="hidden" name="action" value="draft">
    <input type="hidden" name="key" value="{$keyEsc}">
    <label for="url">URL</label>
    <input type="url" id="url" name="url" placeholder="https://example.com/article" required>
    <label for="notes">Notes (optional)</label>
    <textarea id="notes" name="notes" placeholder="What should the post emphasize?"></textarea>
    <button type="submit">Draft post</button>
  </form>
</div>
HTML);
