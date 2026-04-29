<?php

/*
 * Browser-callable random topic poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_random_topic_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_random_topic_worker.php?key=YOUR_SECRET&dry_run=1
 */

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
if (!defined('KONVO_CATEGORY_ID')) define('KONVO_CATEGORY_ID', 34);
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', 42);
if (!defined('KONVO_GAMING_CATEGORY_ID')) define('KONVO_GAMING_CATEGORY_ID', 115);
if (!defined('KONVO_DESIGN_CATEGORY_ID')) define('KONVO_DESIGN_CATEGORY_ID', 114);
if (!defined('KONVO_TECH_NEWS_CATEGORY_ID')) define('KONVO_TECH_NEWS_CATEGORY_ID', 116);

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

$feed_sources = array(
    array('site' => 'daily.dev', 'feed' => 'https://daily.dev/blog/rss.xml', 'kind' => 'technology'),
    array('site' => 'Hacker News', 'feed' => 'https://hnrss.org/frontpage', 'kind' => 'technology'),
    array('site' => 'TechCrunch', 'feed' => 'https://techcrunch.com/feed/', 'kind' => 'technology'),
    array('site' => 'The Verge', 'feed' => 'https://www.theverge.com/rss/index.xml', 'kind' => 'technology'),
    array('site' => 'Ars Technica', 'feed' => 'https://feeds.arstechnica.com/arstechnica/index', 'kind' => 'technology'),
    array('site' => 'WIRED', 'feed' => 'https://www.wired.com/feed/rss', 'kind' => 'technology'),
    array('site' => 'Smashing Magazine', 'feed' => 'https://www.smashingmagazine.com/feed/', 'kind' => 'design'),
    array('site' => 'CSS-Tricks', 'feed' => 'https://css-tricks.com/feed/', 'kind' => 'design'),
    array('site' => 'DEV Community', 'feed' => 'https://dev.to/feed', 'kind' => 'technology'),
    array('site' => 'GitHub Blog', 'feed' => 'https://github.blog/feed/', 'kind' => 'technology'),
    array('site' => 'InfoQ', 'feed' => 'https://www.infoq.com/feed/', 'kind' => 'technology'),
    array('site' => 'UX Collective', 'feed' => 'https://uxdesign.cc/feed', 'kind' => 'design'),
    array('site' => 'Creative Bloq', 'feed' => 'https://www.creativebloq.com/feed', 'kind' => 'design'),
    array('site' => 'designboom', 'feed' => 'https://www.designboom.com/feed/', 'kind' => 'design'),
    array('site' => 'Dezeen', 'feed' => 'https://www.dezeen.com/feed/', 'kind' => 'design'),
    array('site' => 'Webdesigner Depot', 'feed' => 'https://webdesignerdepot.com/feed/', 'kind' => 'design'),
    array('site' => 'The Next Web', 'feed' => 'https://thenextweb.com/feed/', 'kind' => 'technology'),
    array('site' => 'Fast Company Tech', 'feed' => 'https://www.fastcompany.com/technology/rss', 'kind' => 'technology'),
    array('site' => 'Google AI Blog', 'feed' => 'https://blog.google/technology/ai/rss/', 'kind' => 'ai'),
);

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

function state_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/random_topic_seen_urls.json';
}

function load_seen_urls()
{
    $path = state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function save_seen_urls($seen)
{
    if (!is_array($seen)) return;
    arsort($seen);
    $seen = array_slice($seen, 0, 600, true);
    @file_put_contents(state_path(), json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function fetch_url($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'konvo-random-topic-worker/2.0',
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $err !== '' || $status < 200 || $status >= 300) {
            return array('ok' => false, 'status' => $status, 'error' => $err, 'body' => '');
        }
        return array('ok' => true, 'status' => $status, 'error' => '', 'body' => (string)$body);
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: konvo-random-topic-worker/2.0\r\n",
        ),
    ));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return array('ok' => false, 'status' => 0, 'error' => 'fetch failed', 'body' => '');
    }
    return array('ok' => true, 'status' => 200, 'error' => '', 'body' => (string)$body);
}

function normalize_title($title)
{
    $title = trim(strip_tags((string)$title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title);
    if (!is_string($title)) $title = '';
    // Strip common Hacker News prefixes from topic titles.
    $title = preg_replace('/^(show|ask|tell|launch)\s+hn\s*[:\-]\s*/i', '', $title);
    $title = preg_replace('/^hn\s*[:\-]\s*/i', '', $title);
    $title = trim((string)$title);
    if ($title === '') $title = 'Interesting topic';
    if (strlen($title) > 90) {
        $short = trim((string)substr($title, 0, 90));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 24) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $title = $short;
    }
    $title = preg_replace('/[:;,.\-]+$/', '', (string)$title);
    $title = trim((string)$title);
    return $title;
}

function konvo_text_looks_shopping_deal($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;

    // High-confidence shopping/deal signals.
    if (preg_match('/\b(coupon|promo code|discount code|price drop|clearance|doorbuster|black friday|cyber monday|prime day|buy now|shop now|limited[- ]time offer|save\s*\$|%\s*off|for less)\b/i', $t)) {
        return true;
    }

    // Medium-confidence: "deal/sale/discount" plus shopping intent.
    $dealWord = (bool)preg_match('/\b(deal|deals|sale|on sale|discount|offer|offers)\b/i', $t);
    $commerceWord = (bool)preg_match('/\b(shop|shopping|buy|price|priced|pricing|checkout|cart|amazon|walmart|best buy|target|costco|ebay)\b/i', $t)
        || (bool)preg_match('/\$\s*\d+|\d+\s*usd/i', $t);
    if ($dealWord && $commerceWord) {
        return true;
    }

    return false;
}

function konvo_url_looks_shopping_deal($url)
{
    $u = strtolower(trim((string)$url));
    if ($u === '') return false;
    if (preg_match('/\/deals?\b|[?&](deal|deals|coupon|promo|discount)=|black-friday|cyber-monday|prime-day|\/shopping\//i', $u)) {
        return true;
    }
    return false;
}

function konvo_item_looks_shopping_deal($item)
{
    if (!is_array($item)) return false;
    $blob = trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '') . "\n"
        . (string)($item['kind'] ?? '')
    );
    if (konvo_text_looks_shopping_deal($blob)) return true;
    $url = (string)($item['url'] ?? '');
    $feed = (string)($item['source_feed'] ?? '');
    return konvo_url_looks_shopping_deal($url) || konvo_url_looks_shopping_deal($feed);
}

function konvo_text_looks_controversial_topic($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;

    $patterns = array(
        // Politics / geopolitical conflict.
        '/\b(politic|election|electoral|campaign|senate|congress|parliament|president|prime minister|white house|gop|democrat|republican|left wing|right wing|geopolitic|sanction|ceasefire|israel|palestine|gaza|ukraine|russia)\b/i',
        // Violence / crime / war.
        '/\b(kill|killed|killing|murder|homicide|shooting|mass shooting|stabbing|assault|abuse|rape|terror|terrorist|bomb|bombing|war|battle|conflict|genocide|hostage|kidnap|kidnapping|crime|violent|violence)\b/i',
        // Sexual / explicit.
        '/\b(sex|sexual|porn|pornography|nsfw|nude|nudity|adult content|onlyfans|xxx|erotic)\b/i',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

function konvo_url_looks_controversial_topic($url)
{
    $u = strtolower(trim((string)$url));
    if ($u === '') return false;
    return (bool)preg_match('/\b(politic|election|war|conflict|crime|murder|shooting|assault|sex|sexual|porn|nsfw|adult)\b/i', $u);
}

function konvo_item_looks_controversial_topic($item)
{
    if (!is_array($item)) return false;
    $blob = trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '') . "\n"
        . (string)($item['kind'] ?? '')
    );
    if (konvo_text_looks_controversial_topic($blob)) return true;
    $url = (string)($item['url'] ?? '');
    $feed = (string)($item['source_feed'] ?? '');
    return konvo_url_looks_controversial_topic($url) || konvo_url_looks_controversial_topic($feed);
}

function konvo_normalize_for_language_check($text)
{
    $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/https?:\/\/\S+/i', ' ', (string)$text);
    $text = preg_replace('/`[^`]*`/', ' ', (string)$text);
    $text = preg_replace('/[\[\]\(\)\{\}<>\*_#~|]/', ' ', (string)$text);
    $text = preg_replace('/\s+/u', ' ', (string)$text);
    return trim((string)$text);
}

function konvo_text_is_english_like($text)
{
    $text = konvo_normalize_for_language_check($text);
    if ($text === '') return true;

    // Fast-path reject for clearly non-Latin scripts.
    if (preg_match('/[\x{0400}-\x{052F}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0900}-\x{0D7F}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text)) {
        return false;
    }

    $lower = strtolower($text);
    $ok = preg_match_all('/\b[\p{L}][\p{L}\'’-]{1,}\b/u', $lower, $m);
    if (!is_int($ok) || $ok <= 0 || !isset($m[0]) || !is_array($m[0])) return true;
    $tokens = $m[0];
    $tokenCount = count($tokens);
    if ($tokenCount <= 2) return true;

    static $englishWords = null;
    static $foreignWords = null;
    if ($englishWords === null) {
        $englishWords = array_fill_keys(array(
            'the', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'with', 'from', 'by', 'at', 'as',
            'is', 'are', 'was', 'were', 'be', 'been', 'being', 'it', 'its', 'this', 'that', 'these', 'those',
            'a', 'an', 'you', 'your', 'we', 'our', 'they', 'their', 'can', 'could', 'should', 'would', 'will',
            'has', 'have', 'had', 'new', 'how', 'why', 'what', 'when', 'where', 'which', 'who',
            'about', 'into', 'over', 'under', 'after', 'before', 'without', 'using', 'build', 'built',
            'design', 'update', 'release', 'latest', 'guide', 'tips', 'best', 'more', 'less'
        ), true);
    }
    if ($foreignWords === null) {
        $foreignWords = array_fill_keys(array(
            // Portuguese
            'na', 'no', 'nas', 'nos', 'para', 'com', 'sem', 'sobre', 'entre', 'pra', 'de', 'do', 'da', 'dos', 'das',
            'e', 'em', 'um', 'uma', 'uns', 'umas', 'que', 'por', 'como', 'mais', 'menos', 'sistemas', 'pratica', 'prática',
            // Spanish
            'el', 'la', 'los', 'las', 'del', 'al', 'un', 'una', 'unos', 'unas', 'con', 'sin', 'porque',
            // French
            'le', 'les', 'des', 'pour', 'avec', 'sans', 'dans', 'sur', 'est',
            // Italian / German frequent forms
            'per', 'nel', 'nella', 'con', 'senza', 'der', 'die', 'das', 'und', 'mit', 'ist'
        ), true);
    }

    $englishHits = 0;
    $foreignHits = 0;
    $accentedWordCount = 0;
    foreach ($tokens as $tok) {
        $tok = trim((string)$tok, "'’");
        if ($tok === '') continue;
        if (isset($englishWords[$tok])) $englishHits++;
        if (isset($foreignWords[$tok])) $foreignHits++;
        if (preg_match('/[^\x00-\x7F]/u', $tok)) $accentedWordCount++;
    }

    if ($foreignHits >= 4 && $englishHits <= 1) return false;
    if ($foreignHits >= 3 && $englishHits === 0 && $tokenCount <= 14) return false;
    if ($accentedWordCount >= 2 && $englishHits === 0) return false;

    if ($tokenCount >= 6) {
        $foreignRatio = $foreignHits / $tokenCount;
        $englishRatio = $englishHits / $tokenCount;
        if ($foreignRatio >= 0.35 && $englishRatio < 0.08) return false;
    }
    return true;
}

function konvo_item_is_english_like($item)
{
    if (!is_array($item)) return true;
    $blob = trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '')
    );
    return konvo_text_is_english_like($blob);
}

function konvo_title_looks_question_like($title)
{
    $t = strtolower(trim((string)$title));
    if ($t === '') return false;
    if (str_contains($t, '?')) return true;
    if (preg_match('/^(what|why|how|when|where|who|which)\b/i', $t)) return true;
    if (preg_match('/^(is|are|can|could|should|would|do|does|did|will|have|has|had)\b/i', $t)) return true;
    return false;
}

function konvo_ensure_question_mark_title($title)
{
    $title = trim((string)$title);
    if ($title === '') return $title;
    if (!konvo_title_looks_question_like($title)) return $title;
    $title = preg_replace('/[.!:;,\-]+$/', '', $title) ?? $title;
    $title = rtrim((string)$title);
    if (!str_ends_with($title, '?')) {
        $title .= '?';
    }
    return $title;
}

function konvo_text_looks_webdev_related($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(web|webdev|frontend|front-end|browser|html|css|javascript|typescript|react|vue|angular|svelte|dom|a11y|accessibility|webpack|vite|next\.?js|nuxt|ssr|ssg|hydration|requestanimationframe|settimeout|service worker|web worker|cdn|cache|caching|graphql|rest|api|canvas|webgl|shader|sprite|spritesheet|tilemap|pixel art|pixelart|easing|tween)\b/i', $t);
}

function konvo_text_looks_gaming_related($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(video game|gaming|gameplay|trailer|teaser|clip|dlc|patch|hotfix|season pass|battle pass|speedrun|easter egg|xbox|playstation|ps5|ps4|nintendo|switch|steam|epic games|riot games|blizzard|ubisoft|capcom|fromsoftware|elden ring|fortnite|minecraft|valorant|league of legends|call of duty|rpg|fps|mmo|multiplayer|single-player|retro game|classic game|arcade|8-bit|16-bit|nes|snes|n64|nintendo 64|game boy|sega genesis|mega drive|dreamcast|ps1|playstation 1|ps2|playstation 2|dos game|ms-dos|super mario|legend of zelda|zelda|half[- ]life|mechwarrior)\b/i', $t);
}

function konvo_text_has_retro_gaming_signal($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(retro|classic|old school|old-school|arcade|8-bit|16-bit|80s|90s|dos|ms-dos|shareware|pixel art|super mario|mario kart|legend of zelda|zelda|ocarina of time|a link to the past|half[- ]life|mechwarrior|doom|quake|street fighter ii|metal slug|sonic|sega genesis|mega drive|snes|super nintendo|nes|n64|nintendo 64|game boy|dreamcast|ps1|playstation 1|ps2|playstation 2|arcade cabinet)\b/i', $t);
}

function konvo_build_gaming_youtube_queries($title, $summary, $url, $retroBias)
{
    $title = trim((string)$title);
    $summary = trim((string)$summary);
    $url = trim((string)$url);
    $retro = (bool)$retroBias;
    $blob = strtolower(trim($title . "\n" . $summary . "\n" . $url));

    $queries = array();
    $retroStreamers = array('World of Longplays', 'LongplayArchive', 'Summoning Salt');
    $generalStreamers = array('theRadBrad', 'FightinCowboy', 'Shirrako');

    if ($title !== '') {
        if ($retro) {
            $queries[] = $title . ' full walkthrough';
            $queries[] = $title . ' longplay no commentary';
            $queries[] = $title . ' retro gameplay';
            foreach ($retroStreamers as $s) {
                $queries[] = $title . ' ' . $s;
            }
        } else {
            $queries[] = $title . ' trailer gameplay';
            $queries[] = $title . ' gameplay walkthrough';
            foreach ($generalStreamers as $s) {
                $queries[] = $title . ' ' . $s;
            }
        }
    }

    if ($title !== '' && $summary !== '') {
        $queries[] = $title . ' ' . substr($summary, 0, 100);
    }
    if ($title !== '') {
        $queries[] = $title;
    }

    if ($retro) {
        if (strpos($blob, 'mario') !== false) {
            $queries[] = 'Super Mario World full walkthrough';
            $queries[] = 'Super Mario Bros arcade longplay';
        }
        if (strpos($blob, 'zelda') !== false) {
            $queries[] = 'Legend of Zelda Ocarina of Time full walkthrough';
            $queries[] = 'A Link to the Past longplay';
        }
        if (strpos($blob, 'half-life') !== false || strpos($blob, 'half life') !== false) {
            $queries[] = 'Half-Life full walkthrough';
            $queries[] = 'Half-Life retrospective';
        }
        if (strpos($blob, 'mechwarrior') !== false) {
            $queries[] = 'MechWarrior 2 gameplay walkthrough';
            $queries[] = 'MechWarrior classic PC gameplay';
        }
        if (strpos($blob, 'arcade') !== false) {
            $queries[] = 'arcade classics full game longplay';
        }
    }

    $seen = array();
    $out = array();
    foreach ($queries as $q) {
        $q = trim((string)$q);
        if ($q === '') continue;
        $k = strtolower($q);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $q;
    }
    return $out;
}

function konvo_item_looks_gaming_topic($item)
{
    if (!is_array($item)) return false;
    $blob = trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '') . "\n"
        . (string)($item['url'] ?? '') . "\n"
        . (string)($item['source_feed'] ?? '')
    );
    if ($blob === '') return false;
    if (!konvo_text_looks_gaming_related($blob)) return false;

    // Avoid movie/TV entertainment spillover unless clearly about games.
    $entertainment = (bool)preg_match('/\b(movie|film|tv show|television|hollywood|box office|actor|actress|soundtrack)\b/i', strtolower($blob));
    if ($entertainment && !preg_match('/\b(video game|gameplay|game update|game release|console|pc game)\b/i', strtolower($blob))) {
        return false;
    }
    return true;
}

function konvo_source_looks_webdev_related($picked)
{
    if (!is_array($picked)) return false;
    $source = strtolower(trim((string)($picked['source'] ?? '')));
    if ($source !== '') {
        $webSources = array('css-tricks', 'smashing magazine', 'webdesigner depot', 'dev community');
        if (in_array($source, $webSources, true)) return true;
    }
    $blob = strtolower(
        trim((string)($picked['url'] ?? '')) . "\n"
        . trim((string)($picked['source_feed'] ?? ''))
    );
    return (bool)preg_match('/css-tricks\.com|smashingmagazine\.com|webdesignerdepot\.com|web\.dev|developer\.mozilla\.org|dev\.to\//i', $blob);
}

function konvo_source_looks_design_related($picked)
{
    if (!is_array($picked)) return false;
    $kind = strtolower(trim((string)($picked['kind'] ?? '')));
    $source = strtolower(trim((string)($picked['source'] ?? '')));
    $blob = strtolower(
        trim((string)($picked['url'] ?? '')) . "\n"
        . trim((string)($picked['source_feed'] ?? '')) . "\n"
        . $source
    );

    $uiuxSources = array('ux collective', 'creative bloq', 'designboom', 'dezeen');
    if (in_array($source, $uiuxSources, true)) return true;
    if ($kind === 'design' && !preg_match('/css-tricks|smashing magazine|webdesigner depot/i', $source)) return true;
    return (bool)preg_match('/uxdesign\.cc|creativebloq\.com|designboom\.com|dezeen\.com|archdaily\.com|architecturaldigest\.com/i', $blob);
}

function konvo_text_looks_design_related($text)
{
    $t = strtolower(trim((string)$text));
    if ($t === '') return false;

    $physical = (bool)preg_match('/\b(architecture|architect|building|house|home|interior|pavilion|tower|skyscraper|museum|gallery|facade|façade|renovation|landscape architecture|urban planning|studio|residence)\b/i', $t);
    $uiux = (bool)preg_match('/\b(ui|ux|user interface|user experience|interaction design|visual design|design system|wireframe|prototype|figma|typography|color palette)\b/i', $t);
    if (!$physical && !$uiux) return false;

    // Keep "technical design/system design/software architecture" out of Design category.
    if (!$physical && preg_match('/\b(system design|api design|database design|software architecture|computer architecture|backend architecture|technical design)\b/i', $t)) {
        return false;
    }
    return true;
}

function konvo_is_design_topic($title, $raw, $picked = array())
{
    if (konvo_source_looks_design_related($picked)) return true;
    return konvo_text_looks_design_related((string)$title . "\n" . (string)$raw);
}

function konvo_is_webdev_question_topic($title, $raw, $picked = array())
{
    if (!konvo_title_looks_question_like((string)$title)) return false;
    if (konvo_text_looks_webdev_related((string)$title . "\n" . (string)$raw)) return true;
    return konvo_source_looks_webdev_related($picked);
}

function konvo_extract_json_object($content)
{
    $content = trim((string)$content);
    if ($content === '') return array();

    if ($content !== '' && $content[0] === '{') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return array();
    }
    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode((string)$slice, true);
    return is_array($decoded) ? $decoded : array();
}

function konvo_pick_topic_category_decision($title, $raw, $picked = array())
{
    $fallback = array(
        'ok' => false,
        'category_key' => 'tech_news',
        'category_id' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
        'reason' => 'category_llm_unavailable_fallback_tech_news',
        'confidence' => 0.0,
    );
    if (!function_exists('curl_init')) return $fallback;
    if (KONVO_OPENAI_API_KEY === '') return $fallback;

    $source = is_array($picked) ? trim((string)($picked['source'] ?? '')) : '';
    $sourceFeed = is_array($picked) ? trim((string)($picked['source_feed'] ?? '')) : '';
    $sourceKind = is_array($picked) ? trim((string)($picked['kind'] ?? '')) : '';
    $sourceUrl = is_array($picked) ? trim((string)($picked['url'] ?? '')) : '';

    $system = 'Classify a forum topic into one category and return JSON only. '
        . 'Schema: {"category":"tech_news|web_dev|design|gaming","reason":"...","confidence":0.0}. '
        . 'Category rules: '
        . 'tech_news = technology/AI/startup/product/news article topics that are not design or gaming. '
        . 'web_dev = programming, software engineering, web/frontend/backend, APIs, system/product design in software, AI implementation. '
        . 'design = UI/UX/visual design OR physical architecture/interior design. '
        . 'gaming = video games, gameplay, patches, trailers, studios, game communities. '
        . 'Important disambiguation: software contexts using words like build/building/design/architecture/agent/system belong to web_dev, not design. '
        . 'For news/article posts, default to tech_news unless clearly design or gaming. '
        . 'Pick exactly one category.';
    $user = "Topic title:\n" . trim((string)$title) . "\n\n"
        . "Topic body:\n" . trim((string)$raw) . "\n\n"
        . "Source site: {$source}\n"
        . "Source feed: {$sourceFeed}\n"
        . "Source kind hint: {$sourceKind}\n"
        . "Source URL: {$sourceUrl}\n\n"
        . "Return JSON now.";
    $payload = array(
        'model' => konvo_model_for_task('topic_category'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
    );

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . KONVO_OPENAI_API_KEY,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));
    $rawRes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($rawRes === false || $err !== '' || $status < 200 || $status >= 300) {
        return $fallback;
    }
    $decoded = json_decode((string)$rawRes, true);
    $content = is_array($decoded) ? trim((string)($decoded['choices'][0]['message']['content'] ?? '')) : '';
    if ($content === '') {
        return $fallback;
    }
    $obj = konvo_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return $fallback;
    }

    $key = strtolower(trim((string)($obj['category'] ?? '')));
    $map = array(
        'tech_news' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
        'tech-news' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
        'tech' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
        'news' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
        'talk' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
        'general' => (int)KONVO_TECH_NEWS_CATEGORY_ID,
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
    $normalizedKey = 'tech_news';
    if ($categoryId === (int)KONVO_TECH_NEWS_CATEGORY_ID) $normalizedKey = 'tech_news';
    if ($categoryId === (int)KONVO_WEBDEV_CATEGORY_ID) $normalizedKey = 'web_dev';
    if ($categoryId === (int)KONVO_DESIGN_CATEGORY_ID) $normalizedKey = 'design';
    if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID) $normalizedKey = 'gaming';

    $reason = trim((string)($obj['reason'] ?? ''));
    if ($reason === '') $reason = 'llm_category_decision';

    // News/article workers should send non-design, non-gaming posts to Tech News.
    $looksNewsItem = is_array($picked)
        && trim((string)($picked['url'] ?? '')) !== ''
        && (
            trim((string)($picked['source'] ?? '')) !== ''
            || trim((string)($picked['source_feed'] ?? '')) !== ''
            || trim((string)($picked['kind'] ?? '')) !== ''
        );
    if (
        $looksNewsItem
        && $categoryId !== (int)KONVO_DESIGN_CATEGORY_ID
        && $categoryId !== (int)KONVO_GAMING_CATEGORY_ID
        && $categoryId !== (int)KONVO_TECH_NEWS_CATEGORY_ID
    ) {
        $categoryId = (int)KONVO_TECH_NEWS_CATEGORY_ID;
        $normalizedKey = 'tech_news';
        $reason = 'news_article_forced_tech_news';
    }

    $confidence = (float)($obj['confidence'] ?? 0.0);
    if ($confidence < 0.0) $confidence = 0.0;
    if ($confidence > 1.0) $confidence = 1.0;

    return array(
        'ok' => true,
        'category_key' => $normalizedKey,
        'category_id' => $categoryId,
        'reason' => $reason,
        'confidence' => $confidence,
    );
}

function konvo_pick_topic_category_id($title, $raw, $picked = array())
{
    $decision = konvo_pick_topic_category_decision($title, $raw, $picked);
    return (int)($decision['category_id'] ?? (int)KONVO_TECH_NEWS_CATEGORY_ID);
}

function decode_xml_text($text)
{
    $text = (string)$text;
    $text = str_replace(array('<![CDATA[', ']]>'), ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

function konvo_normalize_feed_url($url)
{
    $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') return '';
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (!preg_match('/^https?:\/\//i', $url)) return '';
    return $url;
}

function konvo_extract_image_url_from_block($block)
{
    $block = (string)$block;
    if ($block === '') return '';

    $patterns = array(
        '/<media:content[^>]*url=["\']([^"\']+)["\'][^>]*>/i',
        '/<media:thumbnail[^>]*url=["\']([^"\']+)["\'][^>]*>/i',
        '/<enclosure[^>]*type=["\']image\/[^"\']+["\'][^>]*url=["\']([^"\']+)["\'][^>]*>/i',
        '/<enclosure[^>]*url=["\']([^"\']+)["\'][^>]*type=["\']image\/[^"\']+["\'][^>]*>/i',
        '/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i',
    );

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $block, $m) && isset($m[1])) {
            $url = konvo_normalize_feed_url((string)$m[1]);
            if ($url !== '') return $url;
        }
    }
    return '';
}

function parse_feed_items($xml, $max_items)
{
    $xml = (string)$xml;
    $items = array();
    if ($xml === '') {
        return $items;
    }

    $blocks = array();
    if (preg_match_all('/<item\b[\s\S]*?<\/item>/i', $xml, $m) && isset($m[0])) {
        $blocks = $m[0];
    } elseif (preg_match_all('/<entry\b[\s\S]*?<\/entry>/i', $xml, $m2) && isset($m2[0])) {
        $blocks = $m2[0];
    }

    foreach ($blocks as $block) {
        $title = '';
        $link = '';
        $summary = '';
        $descriptionRaw = '';
        $summaryRaw = '';
        $contentRaw = '';
        $imageUrl = '';

        if (preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $block, $t)) {
            $title = decode_xml_text($t[1]);
        }

        if (preg_match('/<link[^>]*>([\s\S]*?)<\/link>/i', $block, $l)) {
            $link = trim(decode_xml_text($l[1]));
        }
        if ($link === '' && preg_match('/<link[^>]*href=["\']([^"\']+)["\']/i', $block, $lh)) {
            $link = trim((string)$lh[1]);
        }
        if ($link === '' && preg_match('/<guid[^>]*>([\s\S]*?)<\/guid>/i', $block, $g)) {
            $guid = trim(decode_xml_text($g[1]));
            if (preg_match('/^https?:\/\//i', $guid)) {
                $link = $guid;
            }
        }

        if ($title === '' || $link === '' || stripos($link, 'http') !== 0) {
            continue;
        }

        if (preg_match('/<description[^>]*>([\s\S]*?)<\/description>/i', $block, $d)) {
            $descriptionRaw = (string)$d[1];
            $summary = decode_xml_text($d[1]);
        }
        if ($summary === '' && preg_match('/<summary[^>]*>([\s\S]*?)<\/summary>/i', $block, $s)) {
            $summaryRaw = (string)$s[1];
            $summary = decode_xml_text($s[1]);
        }
        if ($summary === '' && preg_match('/<content[^>]*>([\s\S]*?)<\/content>/i', $block, $c)) {
            $contentRaw = (string)$c[1];
            $summary = decode_xml_text($c[1]);
        }
        if (!konvo_text_is_english_like($title . "\n" . $summary)) {
            continue;
        }

        $imageSources = array($block, $descriptionRaw, $summaryRaw, $contentRaw);
        foreach ($imageSources as $blob) {
            $cand = konvo_extract_image_url_from_block((string)$blob);
            if ($cand !== '') {
                $imageUrl = $cand;
                break;
            }
        }

        $items[] = array(
            'title' => normalize_title($title),
            'url' => trim($link),
            'summary' => trim((string)$summary),
            'image_url' => $imageUrl,
        );
        if (count($items) >= $max_items) {
            break;
        }
    }
    return $items;
}

function fetch_topic_candidates($feed_sources)
{
    $all = array();
    foreach ($feed_sources as $source) {
        $feed = isset($source['feed']) ? (string)$source['feed'] : '';
        $kind = isset($source['kind']) ? (string)$source['kind'] : 'technology';
        $site = isset($source['site']) ? (string)$source['site'] : '';
        if ($feed === '') continue;

        $res = fetch_url($feed);
        if (!$res['ok']) continue;

        $items = parse_feed_items($res['body'], 8);
        if (!is_array($items) || count($items) === 0) continue;

        foreach ($items as $item) {
            $candidate = array(
                'title' => isset($item['title']) ? $item['title'] : 'Interesting topic',
                'url' => isset($item['url']) ? $item['url'] : '',
                'summary' => isset($item['summary']) ? $item['summary'] : '',
                'image_url' => isset($item['image_url']) ? $item['image_url'] : '',
                'kind' => $kind,
                'source' => $site,
                'source_feed' => $feed,
            );
            if (konvo_item_looks_shopping_deal($candidate)) {
                continue;
            }
            if (konvo_item_looks_controversial_topic($candidate)) {
                continue;
            }
            if (!konvo_item_is_english_like($candidate)) {
                continue;
            }
            $all[] = $candidate;
        }
    }
    return $all;
}

function pick_new_candidate($candidates, $seen)
{
    if (!is_array($candidates) || count($candidates) === 0) return null;
    shuffle($candidates);
    foreach ($candidates as $item) {
        if (konvo_item_looks_shopping_deal($item)) continue;
        if (konvo_item_looks_controversial_topic($item)) continue;
        if (!konvo_item_is_english_like($item)) continue;
        $url = isset($item['url']) ? $item['url'] : '';
        if ($url === '') continue;
        if (!isset($seen[$url])) return $item;
    }
    foreach ($candidates as $item) {
        if (!konvo_item_looks_shopping_deal($item) && !konvo_item_looks_controversial_topic($item) && konvo_item_is_english_like($item)) return $item;
    }
    return null;
}

function konvo_clean_generated_summary($text)
{
    $text = trim(strip_tags((string)$text));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text, " \t\n\r\0\x0B\"'`“”‘’");
    $text = str_replace('?', '.', (string)$text);
    $text = preg_replace('/\.{2,}/', '.', (string)$text);
    $text = preg_replace('/,\s*\./', '.', (string)$text) ?? $text;
    $text = preg_replace('/\s+,/', ',', (string)$text) ?? $text;
    $text = preg_replace('/([.!?])\s*([.!?])+/', '$1', (string)$text) ?? $text;
    $text = preg_replace('/\b(the interesting part is|the core point is|this piece explains|it works when|the contrarian take is)\b[:\s-]*/i', '', (string)$text) ?? $text;
    // Remove feed metadata tails like "Site - 3 Apr 26 ..."
    $text = preg_replace('/\b[A-Z][A-Za-z0-9&\'\-\s]{2,40}\s+[-–]\s+\d{1,2}\s+[A-Za-z]{3}\s+\d{2}\b.*$/u', '', (string)$text) ?? $text;
    $text = trim((string)$text);
    if ($text === '') return '';

    if (strlen($text) > 320) {
        $short = trim((string)substr($text, 0, 320));
        $lastPunct = max((int)strrpos($short, '. '), (int)strrpos($short, '! '));
        if ($lastPunct > 70) {
            $short = trim((string)substr($short, 0, $lastPunct + 1));
        } else {
            $lastSpace = strrpos($short, ' ');
            if ($lastSpace !== false && $lastSpace > 90) {
                $short = trim((string)substr($short, 0, (int)$lastSpace));
            }
            $short .= '.';
        }
        $text = $short;
    }

    $text = preg_replace('/\b(and|or|but|so|to|for|with|of|in|on|at|from|by|than|that|which|who|when|while|because|being|into|onto|the|a|an)\.?$/i', '', (string)$text) ?? $text;
    $text = trim((string)$text);
    if (preg_match('/\b(is|are|was|were|being)\s+the\.?$/i', $text)) {
        $text = preg_replace('/\b(is|are|was|were|being)\s+the\.?$/i', '', (string)$text) ?? $text;
        $text = trim((string)$text);
    }
    $text = trim((string)$text, " \t\n\r\0\x0B,;:-");
    if ($text !== '' && !preg_match('/[.!]$/', $text)) {
        $text .= '.';
    }

    return trim((string)$text);
}

function konvo_summary_looks_truncated($text)
{
    $t = trim((string)$text);
    if ($t === '') return true;
    if (preg_match('/(\.\.\.|…)\s*$/u', $t)) return true;
    if (preg_match('/[:;\-]\s*$/', $t)) return true;
    if (preg_match('/\b(and|or|to|for|with|of|in|on|at|from|by|than|that|which|who|when|while|because|being|into|onto|the|a|an)\.?$/i', $t)) return true;
    if (preg_match('/\b(is|are|was|were|being)\s+the\.?$/i', $t)) return true;
    if (preg_match('/\b(which|that)\s+(makes|helps|lets|means|gives)\s+[a-z0-9\-]+(?:\s+[a-z0-9\-]+){0,2}\.?$/i', $t)) return true;
    if (preg_match('/\b(artist|architect|designer|engineer)\s+[A-Z][a-z]+\.?$/', $t)) return true;
    return false;
}

function konvo_contextual_summary_fallback($item)
{
    $summary = isset($item['summary']) ? konvo_clean_generated_summary((string)$item['summary']) : '';
    if ($summary !== '' && !konvo_summary_looks_truncated($summary)) {
        return $summary;
    }

    $title = isset($item['title']) ? trim(strip_tags((string)$item['title'])) : '';
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', (string)$title);
    $title = trim((string)$title);
    if ($title === '') {
        return 'Sharing a quick read with practical takeaways.';
    }

    if (strlen($title) > 180) {
        $short = trim((string)substr($title, 0, 180));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 80) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $title = $short;
    }

    if (!preg_match('/[.!]$/', $title)) {
        $title .= '.';
    }
    return $title;
}

function konvo_is_visual_topic_item($item)
{
    $kind = strtolower(trim((string)($item['kind'] ?? '')));
    $blob = strtolower(trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['source'] ?? '')
    ));
    if ($blob === '') return false;
    if ($kind === 'design') return true;
    return (bool)preg_match('/\b(architecture|architect|building|house|home|interior|pavilion|tower|skyscraper|museum|art|artist|gallery|installation|sculpture|render|urban design|landscape)\b/i', $blob);
}

function konvo_compact_human_summary($text, $botName, $kind)
{
    $text = konvo_clean_generated_summary((string)$text);
    if ($text === '') return '';

    $text = str_replace(array('—', '–'), '-', $text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text);

    // Strip common robotic wrap-up fragments and meta framing.
    $text = preg_replace('/\b(Short version:|The core point is simple:?|The core point is:?|Good reality check(?: piece)?[,]?|Handy if you want[^.]*\.?|It is a clear case of[^.]*\.?|This piece explains:?|The interesting part is:?|The contrarian take is:?)\b/i', '', (string)$text);
    $text = trim((string)$text);

    $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: array();
    $kept = array();
    foreach ($sentences as $s) {
        $s = trim((string)$s);
        if ($s === '') continue;
        if (preg_match('/^(handy if|good reality check|short version|the core point is|it is a clear case of|nice little|feels obvious|this piece explains|the interesting part|the contrarian take)/i', $s)) {
            continue;
        }
        $kept[] = $s;
    }
    if ($kept === array()) {
        $kept = $sentences;
    }
    $text = trim(implode(' ', array_slice($kept, 0, 1)));
    if ($text === '') return '';

    $b = strtolower(trim((string)$botName));
    $maxChars = 230;
    if ($b === 'bobamilk') {
        $maxChars = 160;
    } elseif ($b === 'yoshiii' || $b === 'wafflefries' || $b === 'mechaprime') {
        $maxChars = 200;
    } elseif ($b === 'hariseldon' || $b === 'arthurdent') {
        $maxChars = 210;
    }

    // Design topics can stay a touch longer if needed for clarity.
    if (strtolower(trim((string)$kind)) === 'design') {
        $maxChars += 20;
    }

    if (strlen($text) > $maxChars) {
        $short = trim((string)substr($text, 0, $maxChars));
        $lastClause = max((int)strrpos($short, ', '), (int)strrpos($short, '; '));
        if ($lastClause > 90) {
            $short = trim((string)substr($short, 0, $lastClause));
        }
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 70) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $text = $short;
    }

    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text);
    if (konvo_summary_looks_truncated($text)) {
        $text = preg_replace('/\b(and|or|to|for|with|of|in|on|at|from|by|than|that|which|who|when|while|because|being|into|onto|the|a|an)\.?$/i', '', (string)$text) ?? $text;
        $text = trim((string)$text, " \t\n\r\0\x0B,;:-");
    }
    if ($text !== '' && !preg_match('/[.!]$/', $text)) {
        $text .= '.';
    }
    return $text;
}

function konvo_format_blurb_paragraphs($text)
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

    // One thought per paragraph with a blank line for readability.
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

function konvo_generate_body_summary_with_llm($bot, $item, $strict)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.');
    }

    $url = isset($item['url']) ? trim((string)$item['url']) : '';
    $sourceTitle = isset($item['title']) ? trim((string)$item['title']) : '';
    $summary = isset($item['summary']) ? trim((string)$item['summary']) : '';
    $kind = isset($item['kind']) ? trim((string)$item['kind']) : 'technology';
    $botName = isset($bot['name']) ? trim((string)$bot['name']) : 'BayMax';
    $soulKey = isset($bot['soul_key']) ? trim((string)$bot['soul_key']) : strtolower($botName);
    $soulFallback = isset($bot['soul_fallback']) ? trim((string)$bot['soul_fallback']) : 'Write concise, natural forum summaries.';
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, $soulFallback)
    );

    $strictLine = $strict
        ? 'Your previous draft was generic, too long, or robotic. Rewrite it to sound like a casual human forum post.'
        : 'Write the first good draft.';

    $freshnessRule = 'Treat soul/profile details as key context points only. Generate fresh wording that matches current forum mood. Avoid canned phrases, template openers, and copy-paste lines.';
    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'Write a short forum blurb that summarizes an article in natural language in this bot voice. '
        . 'Output only the blurb text (no JSON). '
        . 'Rules: write 1 sentence by default (max 2 only if absolutely needed), clear and specific, casual human tone, no fluff, no hype, no rhetorical questions, no closing flourish. '
        . 'English only. '
        . 'Do not generate content for politics, violence, or sexual/explicit topics; if encountered, return exactly: SKIP_TOPIC. '
        . 'Avoid robotic filler phrases like "Handy if you want", "Good reality check", "The core point is simple", or "Short version". '
        . 'Keep the bot personality subtle and concise. '
        . $freshnessRule;

    $user = "For this article URL, write a short summary blurb for a forum post.\n"
        . "Posting bot: {$botName}\n"
        . "Article URL: {$url}\n"
        . "Source title: {$sourceTitle}\n"
        . "Source summary: {$summary}\n"
        . "Topic kind: {$kind}\n"
        . $strictLine;

    $payload = array(
        'model' => konvo_model_for_task('article_summary'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.7,
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
        return array('ok' => false, 'error' => 'OpenAI body summary response format error');
    }

    $text = trim((string)$decoded['choices'][0]['message']['content']);
    if (strcasecmp($text, 'SKIP_TOPIC') === 0) {
        return array('ok' => false, 'error' => 'Model flagged controversial topic');
    }
    $text = preg_replace('/\b(this article highlights a useful approach|it is interesting because)\b/i', '', (string)$text);
    $text = konvo_clean_generated_summary($text);
    if ($text === '') {
        return array('ok' => false, 'error' => 'Generated body summary was empty');
    }
    if (!konvo_text_is_english_like($text)) {
        return array('ok' => false, 'error' => 'Generated body summary was not English');
    }
    if (konvo_summary_looks_truncated($text)) {
        return array('ok' => false, 'error' => 'Generated body summary looked truncated');
    }

    if (preg_match('/\b(this article|useful approach|interesting because|worth a look)\b/i', strtolower($text))) {
        return array('ok' => false, 'error' => 'Generated body summary looked generic');
    }

    return array('ok' => true, 'text' => $text, 'status' => $status);
}

function konvo_clean_image_lead($text)
{
    $text = trim(strip_tags((string)$text));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/https?:\/\/\S+/i', '', (string)$text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text, " \t\n\r\0\x0B\"'`“”‘’");
    if ($text === '') return '';

    if (strlen($text) > 180) {
        $short = trim((string)substr($text, 0, 180));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 70) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $text = $short;
    }

    if (!preg_match('/[.!]$/', $text)) {
        $text .= '.';
    }
    return trim((string)$text);
}

function konvo_generate_image_lead_with_llm($bot, $item, $strict)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.');
    }

    $url = isset($item['url']) ? trim((string)$item['url']) : '';
    $sourceTitle = isset($item['title']) ? trim((string)$item['title']) : '';
    $summary = isset($item['summary']) ? trim((string)$item['summary']) : '';
    $imageUrl = isset($item['image_url']) ? trim((string)$item['image_url']) : '';
    $kind = isset($item['kind']) ? trim((string)$item['kind']) : 'technology';
    $botName = isset($bot['name']) ? trim((string)$bot['name']) : 'BayMax';
    $soulKey = isset($bot['soul_key']) ? trim((string)$bot['soul_key']) : strtolower($botName);
    $soulFallback = isset($bot['soul_fallback']) ? trim((string)$bot['soul_fallback']) : 'Write concise, natural forum text.';
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, $soulFallback)
    );

    $strictLine = $strict
        ? 'Your prior draft was generic. Rewrite with a concrete visual detail in one sentence.'
        : 'Write the first concise draft.';

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'Write exactly one short sentence introducing an image from an article for a forum post. '
        . 'The sentence should read like a natural human setup line before the image URL. '
        . 'Output only the sentence text. No markdown, no bullet, no signature, no URL, no emoji. English only.';

    $user = "Create one image-intro sentence for this article context.\n"
        . "Posting bot: {$botName}\n"
        . "Article URL: {$url}\n"
        . "Source title: {$sourceTitle}\n"
        . "Source summary: {$summary}\n"
        . "Image URL: {$imageUrl}\n"
        . "Topic kind: {$kind}\n"
        . $strictLine;

    $payload = array(
        'model' => konvo_model_for_task('article_image_lead'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.7,
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
        return array('ok' => false, 'error' => 'OpenAI image lead response format error');
    }

    $text = konvo_clean_image_lead((string)$decoded['choices'][0]['message']['content']);
    if ($text === '') {
        return array('ok' => false, 'error' => 'Generated image lead was empty');
    }
    if (!konvo_text_is_english_like($text)) {
        return array('ok' => false, 'error' => 'Generated image lead was not English');
    }
    if (preg_match('/https?:\/\/\S+/i', $text)) {
        return array('ok' => false, 'error' => 'Generated image lead contained URL');
    }

    return array('ok' => true, 'text' => $text, 'status' => $status);
}

function konvo_clean_video_lead($text)
{
    $text = trim(strip_tags((string)$text));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/https?:\/\/\S+/i', '', (string)$text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text, " \t\n\r\0\x0B\"'`“”‘’");
    if ($text === '') return '';
    if (strlen($text) > 170) {
        $short = trim((string)substr($text, 0, 170));
        $lastSpace = strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 70) {
            $short = trim((string)substr($short, 0, (int)$lastSpace));
        }
        $text = $short;
    }
    if (!preg_match('/[.!]$/', $text)) $text .= '.';
    return trim((string)$text);
}

function konvo_video_lead_fallback($item)
{
    $title = trim((string)($item['title'] ?? ''));
    if ($title !== '') {
        return konvo_clean_video_lead('This trailer breaks down what changed in ' . $title);
    }
    return 'This video gives a quick look at the update.';
}

function konvo_generate_gaming_video_lead_with_llm($bot, $item, $youtubeUrl, $strict)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.');
    }

    $url = isset($item['url']) ? trim((string)$item['url']) : '';
    $sourceTitle = isset($item['title']) ? trim((string)$item['title']) : '';
    $summary = isset($item['summary']) ? trim((string)$item['summary']) : '';
    $retroHint = konvo_text_has_retro_gaming_signal($sourceTitle . "\n" . $summary . "\n" . $url);
    $botName = isset($bot['name']) ? trim((string)$bot['name']) : 'VaultBoy';
    $soulKey = isset($bot['soul_key']) ? trim((string)$bot['soul_key']) : strtolower($botName);
    $soulFallback = isset($bot['soul_fallback']) ? trim((string)$bot['soul_fallback']) : 'Write concise, natural forum text.';
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul($soulKey, $soulFallback)
    );
    $strictLine = $strict
        ? 'Your prior draft was vague. Rewrite with a specific detail from the context in one short sentence.'
        : 'Write the first concise draft.';

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'Write exactly one short sentence introducing a YouTube video for a gaming forum post. '
        . 'Explain what the video shows. Keep it natural and human. '
        . ($retroHint ? 'The context is retro/classic gaming, so briefly lean into that nostalgia. ' : '')
        . 'Output only sentence text. No URL, no markdown, no signature, no emoji. English only.';

    $user = "Create one video-intro sentence for this gaming post.\n"
        . "Posting bot: {$botName}\n"
        . "Article URL: {$url}\n"
        . "Article title: {$sourceTitle}\n"
        . "Article summary: {$summary}\n"
        . "YouTube URL: {$youtubeUrl}\n"
        . $strictLine;

    $payload = array(
        'model' => konvo_model_for_task('article_video_lead'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.7,
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
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        return array('ok' => false, 'error' => 'OpenAI network error: ' . $err);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
        return array('ok' => false, 'error' => 'OpenAI video lead response format error');
    }

    $text = konvo_clean_video_lead((string)$decoded['choices'][0]['message']['content']);
    if ($text === '') {
        return array('ok' => false, 'error' => 'Generated video lead was empty');
    }
    if (!konvo_text_is_english_like($text)) {
        return array('ok' => false, 'error' => 'Generated video lead was not English');
    }
    if (preg_match('/https?:\/\/\S+/i', $text)) {
        return array('ok' => false, 'error' => 'Generated video lead contained URL');
    }

    return array('ok' => true, 'text' => $text);
}

function make_body($bot, $item)
{
    $botNameRaw = isset($bot['name']) ? (string)$bot['name'] : 'BayMax';
    $botNameBase = function_exists('konvo_signature_base_name')
        ? konvo_signature_base_name($botNameRaw)
        : $botNameRaw;
    $url = isset($item['url']) ? $item['url'] : KONVO_BASE_URL;
    $summary = isset($item['summary']) ? trim((string)$item['summary']) : '';
    $kind = isset($item['kind']) ? trim((string)$item['kind']) : 'technology';
    $isGaming = konvo_item_looks_gaming_topic($item);
    $youtubeUrl = $isGaming ? konvo_pick_gaming_youtube_url($item) : '';
    $imageUrl = isset($item['image_url']) ? konvo_normalize_feed_url((string)$item['image_url']) : '';

    $blurbRes = konvo_generate_body_summary_with_llm($bot, $item, false);
    if (!is_array($blurbRes) || empty($blurbRes['ok']) || !isset($blurbRes['text'])) {
        $blurbRes = konvo_generate_body_summary_with_llm($bot, $item, true);
    }

    if (is_array($blurbRes) && !empty($blurbRes['ok']) && isset($blurbRes['text'])) {
        $blurb = trim((string)$blurbRes['text']);
    } else {
        $blurb = konvo_contextual_summary_fallback($item);
    }
    $blurb = konvo_compact_human_summary($blurb, $botNameBase, $kind);
    if (konvo_summary_looks_truncated($blurb)) {
        $fallbackItem = $item;
        $fallbackItem['summary'] = '';
        $blurb = konvo_compact_human_summary(konvo_contextual_summary_fallback($fallbackItem), $botNameBase, $kind);
    }
    if ($blurb === '') {
        $blurb = 'Useful read with practical takeaways.';
    }
    if (!konvo_text_is_english_like($blurb)) {
        $blurb = 'Useful read with practical takeaways.';
    }
    $blurb = konvo_format_blurb_paragraphs($blurb);

    $imageLead = '';
    if ($imageUrl !== '' && konvo_is_visual_topic_item($item) && stripos($imageUrl, $url) !== 0) {
        $leadRes = konvo_generate_image_lead_with_llm($bot, $item, false);
        if (!is_array($leadRes) || empty($leadRes['ok']) || !isset($leadRes['text'])) {
            $leadRes = konvo_generate_image_lead_with_llm($bot, $item, true);
        }
        if (is_array($leadRes) && !empty($leadRes['ok']) && isset($leadRes['text'])) {
            $imageLead = trim((string)$leadRes['text']);
        }
    }

    $videoLead = '';
    if ($isGaming && $youtubeUrl !== '' && $youtubeUrl !== $url) {
        $vLead = konvo_generate_gaming_video_lead_with_llm($bot, $item, $youtubeUrl, false);
        if (!is_array($vLead) || empty($vLead['ok']) || !isset($vLead['text'])) {
            $vLead = konvo_generate_gaming_video_lead_with_llm($bot, $item, $youtubeUrl, true);
        }
        if (is_array($vLead) && !empty($vLead['ok']) && isset($vLead['text'])) {
            $videoLead = trim((string)$vLead['text']);
        }
        if ($videoLead === '') {
            $videoLead = konvo_video_lead_fallback($item);
        }
    }

    $lines = array();
    $lines[] = $blurb;
    $lines[] = '';
    $lines[] = $url;
    if ($isGaming && $youtubeUrl !== '' && $youtubeUrl !== $url) {
        $lines[] = '';
        if ($videoLead !== '') {
            $lines[] = $videoLead;
            $lines[] = '';
        }
        $lines[] = $youtubeUrl;
    }
    if ($imageLead !== '' && $imageUrl !== '') {
        $lines[] = '';
        $lines[] = $imageLead;
        $lines[] = '';
        $lines[] = $imageUrl;
    }
    return implode("\n", $lines);
}

function konvo_title_key($s)
{
    $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', (string)$s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    return trim((string)$s);
}

function konvo_clean_generated_title($title)
{
    $title = trim(strip_tags((string)$title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = trim((string)$title, " \t\n\r\0\x0B\"'`“”‘’");
    $title = str_replace(':', ' ', (string)$title);
    $title = preg_replace('/\s+/', ' ', (string)$title);
    $title = trim((string)$title);
    if ($title === '') return '';

    $title = preg_replace('/[:;,\.\-]+$/', '', (string)$title);
    $title = trim((string)$title);
    if ($title !== '') {
        $title = strtoupper(substr($title, 0, 1)) . substr($title, 1);
    }
    $title = preg_replace('/\bai\b/', 'AI', (string)$title);
    $title = preg_replace('/\bui\b/', 'UI', (string)$title);
    $title = preg_replace('/\bux\b/', 'UX', (string)$title);
    $title = preg_replace('/\bapi\b/', 'API', (string)$title);
    return konvo_ensure_question_mark_title(trim((string)$title));
}

function konvo_title_looks_incomplete_phrase($title)
{
    $t = strtolower(trim((string)$title));
    if ($t === '') return true;
    if (preg_match('/\b(and|or|to|for|with|of|in|on|at|from|by|about|than|the|a|an)\s*$/i', $t)) return true;
    if (preg_match('/\b(with|for|about|around|across)\s+(the|a|an)\s*$/i', $t)) return true;
    if (preg_match('/\b(of|for|to)\s+\w+\s+(and|or)\s*$/i', $t)) return true;
    return false;
}

function konvo_generate_title_with_llm($item, $strict)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.');
    }

    $url = isset($item['url']) ? trim((string)$item['url']) : '';
    $sourceTitle = isset($item['title']) ? trim((string)$item['title']) : '';
    $summary = isset($item['summary']) ? trim((string)$item['summary']) : '';
    $kind = isset($item['kind']) ? trim((string)$item['kind']) : 'technology';

    $strictLine = $strict
        ? 'Your previous title was too close to the source title, awkward, or incomplete. Rewrite with cleaner, simpler wording and a complete thought.'
        : 'Generate the best concise title on the first try.';

    $freshnessRule = 'Treat source/soul context as guidance only. Produce fresh wording each run and avoid reusable title templates.';
    $system = 'You are writing one forum post title for a shared web article. Return ONLY JSON: {"title":"..."}. '
        . 'Requirements: headline-worthy, human language, concise, complete thought, sentence case, 5-11 words, <= 68 characters, no emoji, no quotes, no colon, no clickbait fluff. '
        . 'English only. '
        . 'Do not end with trailing prepositions/articles or unfinished phrasing. '
        . 'Never create titles about politics, violence, or sexual/explicit topics. '
        . 'Do not copy or lightly paraphrase the source title. '
        . 'Use a useful angle for practitioners. '
        . $freshnessRule;

    $user = "For this article URL, generate a short forum title.\n"
        . "Article URL: {$url}\n"
        . "Source title: {$sourceTitle}\n"
        . "Source summary: {$summary}\n"
        . "Topic kind: {$kind}\n"
        . $strictLine;

    $payload = array(
        'model' => konvo_model_for_task('article_title'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.6,
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
        return array('ok' => false, 'error' => 'OpenAI title response format error');
    }

    $content = trim((string)$decoded['choices'][0]['message']['content']);
    $title = '';
    if ($content !== '' && ($content[0] === '{' || strpos($content, '{') !== false)) {
        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $obj = json_decode(substr($content, (int)$jsonStart, (int)($jsonEnd - $jsonStart + 1)), true);
            if (is_array($obj) && isset($obj['title'])) {
                $title = trim((string)$obj['title']);
            }
        }
    }
    if ($title === '') {
        $line = trim((string)strtok($content, "\n"));
        $line = preg_replace('/^title\s*:\s*/i', '', (string)$line);
        $title = trim((string)$line, " \t\n\r\0\x0B\"'`");
    }

    $title = konvo_clean_generated_title($title);
    if ($title === '') {
        return array('ok' => false, 'error' => 'Generated title was empty');
    }
    if (!konvo_text_is_english_like($title)) {
        return array('ok' => false, 'error' => 'Generated title was not English');
    }

    $sourceKey = konvo_title_key($sourceTitle);
    $titleKey = konvo_title_key($title);
    if ($sourceKey !== '' && $titleKey !== '' && $titleKey === $sourceKey) {
        return array('ok' => false, 'error' => 'Generated title matched source title');
    }
    if (strlen($title) > 68) {
        return array('ok' => false, 'error' => 'Generated title exceeded length limit');
    }
    if (konvo_title_looks_incomplete_phrase($title)) {
        return array('ok' => false, 'error' => 'Generated title looked incomplete');
    }

    $wordCount = preg_match_all('/\b[\p{L}\p{N}]+\b/u', $title, $wm);
    if (!is_int($wordCount) || $wordCount < 4) {
        return array('ok' => false, 'error' => 'Generated title too short');
    }

    return array('ok' => true, 'title' => $title, 'status' => $status);
}

function build_short_forum_title($item)
{
    $first = konvo_generate_title_with_llm($item, false);
    if (is_array($first) && !empty($first['ok']) && isset($first['title'])) {
        return array('ok' => true, 'title' => (string)$first['title']);
    }

    $second = konvo_generate_title_with_llm($item, true);
    if (is_array($second) && !empty($second['ok']) && isset($second['title'])) {
        return array('ok' => true, 'title' => (string)$second['title']);
    }

    $err = is_array($second) && isset($second['error']) ? (string)$second['error'] : '';
    if ($err === '' && is_array($first) && isset($first['error'])) {
        $err = (string)$first['error'];
    }
    if ($err === '') $err = 'Could not generate a title with the model.';
    return array('ok' => false, 'error' => $err);
}

function konvo_extract_first_youtube_video_id($text)
{
    $text = (string)$text;
    if ($text === '') return '';
    if (preg_match('/"videoId":"([A-Za-z0-9_-]{11})"/', $text, $m)) return (string)$m[1];
    if (preg_match('/\/watch\?v=([A-Za-z0-9_-]{11})/', $text, $m2)) return (string)$m2[1];
    return '';
}

function konvo_search_youtube_video_url($query)
{
    $q = trim((string)$query);
    if ($q === '') return '';
    $searchUrl = 'https://www.youtube.com/results?search_query=' . rawurlencode($q);
    $res = fetch_url($searchUrl);
    if (!is_array($res) || empty($res['ok']) || !isset($res['body'])) return '';
    $videoId = konvo_extract_first_youtube_video_id((string)$res['body']);
    if ($videoId === '') return '';
    return 'https://www.youtube.com/watch?v=' . $videoId;
}

function konvo_pick_gaming_youtube_url($item)
{
    if (!is_array($item) || !konvo_item_looks_gaming_topic($item)) return '';
    $url = trim((string)($item['url'] ?? ''));
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)[A-Za-z0-9_-]{11}/i', $url)) {
        return $url;
    }
    $title = trim((string)($item['title'] ?? ''));
    $summary = trim((string)($item['summary'] ?? ''));
    $retroBias = konvo_text_has_retro_gaming_signal($title . "\n" . $summary . "\n" . (string)($item['source'] ?? '') . "\n" . (string)($item['source_feed'] ?? '') . "\n" . $url);
    $queries = konvo_build_gaming_youtube_queries($title, $summary, $url, $retroBias);
    foreach ($queries as $q) {
        $yt = konvo_search_youtube_video_url($q);
        if ($yt !== '') return $yt;
    }
    return '';
}

function konvo_has_youtube_video_url($text)
{
    return (bool)preg_match('/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)[A-Za-z0-9_-]{11}/i', (string)$text);
}

function post_topic($botUsername, $title, $raw, $categoryId = null)
{
    $title = konvo_ensure_question_mark_title((string)$title);
    $category = is_numeric($categoryId) ? (int)$categoryId : (int)KONVO_CATEGORY_ID;
    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => $category,
    );

    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }

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
        'raw' => (string)$body,
    );
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

function build_preview_token($payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return '';
    $body = base64url_encode($json);
    $sig = function_exists('hash_hmac')
        ? hash_hmac('sha256', $body, KONVO_SECRET)
        : hash('sha256', $body . '|' . KONVO_SECRET);
    return $body . '.' . $sig;
}

function parse_preview_token($token)
{
    $token = trim((string)$token);
    if ($token === '' || strpos($token, '.') === false) {
        return array('ok' => false, 'error' => 'Missing preview token');
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return array('ok' => false, 'error' => 'Invalid preview token format');
    }

    $body = (string)$parts[0];
    $sig = (string)$parts[1];
    $expected = function_exists('hash_hmac')
        ? hash_hmac('sha256', $body, KONVO_SECRET)
        : hash('sha256', $body . '|' . KONVO_SECRET);

    if (!safe_hash_equals($expected, $sig)) {
        return array('ok' => false, 'error' => 'Invalid preview token signature');
    }

    $json = base64url_decode($body);
    if ($json === '') {
        return array('ok' => false, 'error' => 'Invalid preview token body');
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'error' => 'Invalid preview payload');
    }

    return array('ok' => true, 'payload' => $decoded);
}

function find_bot_by_username($bots, $username)
{
    $username = strtolower(trim((string)$username));
    foreach ($bots as $bot) {
        $u = isset($bot['username']) ? strtolower((string)$bot['username']) : '';
        if ($u === $username) return $bot;
    }
    return null;
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : (isset($_POST['key']) ? (string)$_POST['key'] : '');
if (KONVO_SECRET === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !safe_hash_equals(KONVO_SECRET, $providedKey)) {
    out_json(403, array(
        'ok' => false,
        'error' => 'Forbidden',
        'hint' => 'Pass ?key=YOUR_SECRET',
    ));
}

if (KONVO_API_KEY === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_OPENAI_API_KEY === '') {
    out_json(500, array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$previewMode = (isset($_GET['preview']) && (string)$_GET['preview'] === '1')
    || (isset($_GET['preview_only']) && (string)$_GET['preview_only'] === '1');
$confirmPost = (isset($_POST['confirm_post']) && (string)$_POST['confirm_post'] === '1')
    || (isset($_GET['confirm_post']) && (string)$_GET['confirm_post'] === '1');

if ($confirmPost) {
    $token = isset($_POST['preview_token']) ? (string)$_POST['preview_token'] : (isset($_GET['preview_token']) ? (string)$_GET['preview_token'] : '');
    $parsed = parse_preview_token($token);
    if (!$parsed['ok']) {
        out_json(400, array('ok' => false, 'error' => $parsed['error']));
    }

    $payload = $parsed['payload'];
    $generatedAt = isset($payload['generated_at']) ? (int)$payload['generated_at'] : 0;
    if ($generatedAt > 0 && (time() - $generatedAt) > (2 * 24 * 60 * 60)) {
        out_json(400, array('ok' => false, 'error' => 'Preview expired. Generate a new preview.'));
    }

    $chosenUsername = isset($_POST['bot_username']) ? (string)$_POST['bot_username'] : (isset($payload['bot_username']) ? (string)$payload['bot_username'] : '');
    $bot = find_bot_by_username($bots, $chosenUsername);
    if (!is_array($bot)) {
        out_json(400, array('ok' => false, 'error' => 'Invalid bot username in preview post request'));
    }

    $title = isset($_POST['edited_title']) ? trim((string)$_POST['edited_title']) : '';
    if ($title === '') $title = isset($payload['topic_title']) ? trim((string)$payload['topic_title']) : '';
    $title = konvo_ensure_question_mark_title($title);

    $raw = isset($_POST['edited_raw']) ? trim((string)$_POST['edited_raw']) : '';
    if ($raw === '') $raw = isset($payload['topic_raw']) ? trim((string)$payload['topic_raw']) : '';
    $pickedContext = array(
        'url' => isset($payload['picked_url']) ? (string)$payload['picked_url'] : '',
        'source' => isset($payload['picked_source']) ? (string)$payload['picked_source'] : '',
        'source_feed' => isset($payload['picked_source_feed']) ? (string)$payload['picked_source_feed'] : '',
        'title' => isset($payload['picked_title']) ? (string)$payload['picked_title'] : '',
        'summary' => isset($payload['picked_summary']) ? (string)$payload['picked_summary'] : '',
        'kind' => isset($payload['picked_kind']) ? (string)$payload['picked_kind'] : '',
    );
    if (konvo_item_looks_shopping_deal($pickedContext) || konvo_text_looks_shopping_deal($title . "\n" . $raw)) {
        out_json(400, array(
            'ok' => false,
            'error' => 'Shopping deal/sales posts are blocked by policy.',
        ));
    }
    if (konvo_item_looks_controversial_topic($pickedContext) || konvo_text_looks_controversial_topic($title . "\n" . $raw)) {
        out_json(400, array(
            'ok' => false,
            'error' => 'Controversial topics (politics, violence, sex) are blocked by policy.',
        ));
    }
    if (!konvo_item_is_english_like($pickedContext) || !konvo_text_is_english_like($title) || !konvo_text_is_english_like($raw)) {
        out_json(400, array(
            'ok' => false,
            'error' => 'Only English content is allowed for this worker.',
        ));
    }
    $categoryDecision = konvo_pick_topic_category_decision($title, $raw, $pickedContext);
    $categoryId = (int)($categoryDecision['category_id'] ?? (int)KONVO_CATEGORY_ID);
    $forcedGamingAuthor = false;
    if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID) {
        $vaultboyBot = find_bot_by_username($bots, 'vaultboy');
        if (is_array($vaultboyBot)) {
            $bot = $vaultboyBot;
            $forcedGamingAuthor = true;
        }
        if (!konvo_has_youtube_video_url($raw)) {
            $yt = konvo_pick_gaming_youtube_url($pickedContext);
            if ($yt !== '') {
                $vLead = konvo_generate_gaming_video_lead_with_llm($bot, $pickedContext, $yt, false);
                if (!is_array($vLead) || empty($vLead['ok']) || !isset($vLead['text'])) {
                    $vLead = konvo_generate_gaming_video_lead_with_llm($bot, $pickedContext, $yt, true);
                }
                $leadText = (is_array($vLead) && !empty($vLead['ok']) && isset($vLead['text']))
                    ? trim((string)$vLead['text'])
                    : konvo_video_lead_fallback($pickedContext);
                $raw = rtrim($raw) . "\n\n" . $leadText . "\n\n" . $yt;
            } else {
                out_json(400, array(
                    'ok' => false,
                    'error' => 'Gaming posts require a direct YouTube video URL for embed.',
                ));
            }
        }
    }

    if ($title === '' || $raw === '') {
        out_json(400, array('ok' => false, 'error' => 'Edited title/body cannot be empty'));
    }

    if (strlen($title) > 255) {
        $title = trim((string)substr($title, 0, 255));
    }

    $posted = post_topic($bot['username'], $title, $raw, $categoryId);
    if (!$posted['ok']) {
        out_json(500, array(
            'ok' => false,
            'error' => 'Discourse post failed',
            'status' => $posted['status'],
            'curl_error' => $posted['error'],
            'response' => $posted['body'],
            'raw' => $posted['raw'],
        ));
    }

    $topicId = isset($posted['body']['topic_id']) ? (int)$posted['body']['topic_id'] : 0;
    $postNumber = isset($posted['body']['post_number']) ? (int)$posted['body']['post_number'] : 1;
    $postUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

    $url = isset($payload['picked_url']) ? (string)$payload['picked_url'] : '';
    if ($url !== '') {
        $seen = load_seen_urls();
        $seen[$url] = time();
        save_seen_urls($seen);
    }

    out_json(200, array(
        'ok' => true,
        'posted' => true,
        'post_url' => $postUrl,
        'bot' => $bot,
        'topic' => array(
            'title' => $title,
            'raw' => $raw,
            'category_id' => $categoryId,
            'url' => $url,
            'kind' => isset($payload['picked_kind']) ? (string)$payload['picked_kind'] : '',
            'source' => isset($payload['picked_source']) ? (string)$payload['picked_source'] : '',
            'source_feed' => isset($payload['picked_source_feed']) ? (string)$payload['picked_source_feed'] : '',
            'forced_gaming_author' => $forcedGamingAuthor,
            'category_decision' => $categoryDecision,
        ),
    ));
}

$seen = load_seen_urls();
$candidates = fetch_topic_candidates($feed_sources);

if (!is_array($candidates) || count($candidates) === 0) {
    out_json(500, array(
        'ok' => false,
        'error' => 'No candidates found from live sources',
        'checks' => array(
            'curl' => function_exists('curl_init'),
            'candidate_count' => is_array($candidates) ? count($candidates) : 0,
        ),
    ));
}

$picked = pick_new_candidate($candidates, $seen);
if (!is_array($picked)) {
    out_json(500, array('ok' => false, 'error' => 'Could not pick candidate'));
}
if (konvo_item_looks_controversial_topic($picked)) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped controversial topic by policy.',
        'picked' => $picked,
    ));
}
if (!konvo_item_is_english_like($picked)) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped non-English source content by policy.',
        'picked' => $picked,
    ));
}

$pickedLooksGaming = konvo_item_looks_gaming_topic($picked);
if ($pickedLooksGaming) {
    $vaultboyBot = find_bot_by_username($bots, 'vaultboy');
    $bot = is_array($vaultboyBot) ? $vaultboyBot : $bots[0];
} else {
    shuffle($bots);
    $bot = $bots[0];
}
$titleRes = build_short_forum_title($picked);
if (!is_array($titleRes) || empty($titleRes['ok']) || !isset($titleRes['title'])) {
    out_json(502, array(
        'ok' => false,
        'error' => is_array($titleRes) && isset($titleRes['error']) ? (string)$titleRes['error'] : 'Failed to generate title with model.',
        'picked' => $picked,
    ));
}
$topicTitle = konvo_ensure_question_mark_title(trim((string)$titleRes['title']));
$topicRaw = make_body($bot, $picked);
if (konvo_item_looks_shopping_deal($picked) || konvo_text_looks_shopping_deal($topicTitle . "\n" . $topicRaw)) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped shopping deal/sales topic by policy.',
        'picked' => $picked,
    ));
}
if (konvo_item_looks_controversial_topic($picked) || konvo_text_looks_controversial_topic($topicTitle . "\n" . $topicRaw)) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped controversial topic by policy.',
        'picked' => $picked,
    ));
}
if (!konvo_text_is_english_like($topicTitle) || !konvo_text_is_english_like($topicRaw)) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped non-English generated output by policy.',
        'picked' => $picked,
    ));
}
$categoryDecision = konvo_pick_topic_category_decision($topicTitle, $topicRaw, $picked);
$categoryId = (int)($categoryDecision['category_id'] ?? (int)KONVO_CATEGORY_ID);
if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID && strtolower((string)($bot['username'] ?? '')) !== 'vaultboy') {
    $vaultboyBot = find_bot_by_username($bots, 'vaultboy');
    if (is_array($vaultboyBot)) {
        $bot = $vaultboyBot;
        $topicRaw = make_body($bot, $picked);
        $categoryDecision = konvo_pick_topic_category_decision($topicTitle, $topicRaw, $picked);
        $categoryId = (int)($categoryDecision['category_id'] ?? (int)KONVO_CATEGORY_ID);
    }
}
if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID && !konvo_has_youtube_video_url($topicRaw)) {
    out_json(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'Skipped gaming topic because no direct YouTube video URL was found.',
        'bot' => $bot,
        'picked' => $picked,
    ));
}

$previewPayload = array(
    'generated_at' => time(),
    'bot_username' => isset($bot['username']) ? (string)$bot['username'] : '',
    'bot_name' => isset($bot['name']) ? (string)$bot['name'] : '',
    'category_id' => $categoryId,
    'topic_title' => $topicTitle,
    'topic_raw' => $topicRaw,
    'picked_url' => isset($picked['url']) ? (string)$picked['url'] : '',
    'picked_title' => isset($picked['title']) ? (string)$picked['title'] : '',
    'picked_summary' => isset($picked['summary']) ? (string)$picked['summary'] : '',
    'picked_image_url' => isset($picked['image_url']) ? (string)$picked['image_url'] : '',
    'picked_kind' => isset($picked['kind']) ? (string)$picked['kind'] : '',
    'picked_source' => isset($picked['source']) ? (string)$picked['source'] : '',
    'picked_source_feed' => isset($picked['source_feed']) ? (string)$picked['source_feed'] : '',
    'picked_is_gaming' => $pickedLooksGaming,
    'category_decision' => $categoryDecision,
);
$previewToken = build_preview_token($previewPayload);

if ($previewMode) {
    out_json(200, array(
        'ok' => true,
        'preview' => true,
        'preview_token' => $previewToken,
        'bot' => $bot,
        'source' => array(
            'title' => isset($picked['title']) ? $picked['title'] : '',
            'summary' => isset($picked['summary']) ? $picked['summary'] : '',
            'url' => isset($picked['url']) ? $picked['url'] : '',
            'image_url' => isset($picked['image_url']) ? $picked['image_url'] : '',
            'kind' => isset($picked['kind']) ? $picked['kind'] : '',
            'source' => isset($picked['source']) ? $picked['source'] : '',
            'source_feed' => isset($picked['source_feed']) ? $picked['source_feed'] : '',
        ),
        'topic' => array(
            'title' => $topicTitle,
            'category_id' => $categoryId,
            'raw_preview' => $topicRaw,
            'category_decision' => $categoryDecision,
        ),
        'source_count' => count($feed_sources),
        'candidate_count' => count($candidates),
    ));
}

if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'preview_token' => $previewToken,
        'bot' => $bot,
        'topic' => array(
            'title' => $topicTitle,
            'category_id' => $categoryId,
            'url' => $picked['url'],
            'image_url' => isset($picked['image_url']) ? $picked['image_url'] : '',
            'kind' => $picked['kind'],
            'source' => isset($picked['source']) ? $picked['source'] : '',
            'source_feed' => isset($picked['source_feed']) ? $picked['source_feed'] : '',
            'raw_preview' => $topicRaw,
            'category_decision' => $categoryDecision,
        ),
        'source_count' => count($feed_sources),
        'candidate_count' => count($candidates),
    ));
}

$posted = post_topic($bot['username'], $topicTitle, $topicRaw, $categoryId);
if (!$posted['ok']) {
    out_json(500, array(
        'ok' => false,
        'error' => 'Discourse post failed',
        'status' => $posted['status'],
        'curl_error' => $posted['error'],
        'response' => $posted['body'],
        'raw' => $posted['raw'],
        'bot' => $bot,
        'picked' => $picked,
    ));
}

$topicId = isset($posted['body']['topic_id']) ? (int)$posted['body']['topic_id'] : 0;
$postNumber = isset($posted['body']['post_number']) ? (int)$posted['body']['post_number'] : 1;
$postUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

$seen[$picked['url']] = time();
save_seen_urls($seen);

out_json(200, array(
    'ok' => true,
    'posted' => true,
    'post_url' => $postUrl,
    'bot' => $bot,
    'topic' => array(
        'title' => $topicTitle,
        'category_id' => $categoryId,
        'url' => $picked['url'],
        'image_url' => isset($picked['image_url']) ? $picked['image_url'] : '',
        'kind' => $picked['kind'],
        'source' => isset($picked['source']) ? $picked['source'] : '',
        'source_feed' => isset($picked['source_feed']) ? $picked['source_feed'] : '',
        'category_decision' => $categoryDecision,
    ),
    'source_count' => count($feed_sources),
));
