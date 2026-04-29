<?php

/*
 * Browser-callable VaultBoy gaming news poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_vaultboy_gaming_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_vaultboy_gaming_worker.php?key=YOUR_SECRET&dry_run=1
 * https://www.kirupa.com/konvo_vaultboy_gaming_worker.php?key=YOUR_SECRET&force=1
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
        return 'gpt-5.2';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_GAMING_CATEGORY_ID')) define('KONVO_GAMING_CATEGORY_ID', 115);
if (!defined('KONVO_TZ')) define('KONVO_TZ', trim((string)(getenv('KONVO_TIMEZONE') ?: 'America/Los_Angeles')));

@date_default_timezone_set(KONVO_TZ);

$vaultboy = array(
    'username' => 'vaultboy',
    'name' => 'VaultBoy',
    'soul_key' => 'vaultboy',
    'soul_fallback' => 'You are VaultBoy. Casual, playful, game-obsessed, and concise.',
);

$game_article_feeds = array(
    array('site' => 'IGN', 'feed' => 'https://feeds.ign.com/ign/all'),
    array('site' => 'GameSpot', 'feed' => 'https://www.gamespot.com/feeds/mashup/'),
    array('site' => 'Polygon', 'feed' => 'https://www.polygon.com/rss/index.xml'),
    array('site' => 'Eurogamer', 'feed' => 'https://www.eurogamer.net/feed'),
    array('site' => 'PC Gamer', 'feed' => 'https://www.pcgamer.com/rss/'),
    array('site' => 'Rock Paper Shotgun', 'feed' => 'https://www.rockpapershotgun.com/feed'),
    array('site' => 'Nintendo Life', 'feed' => 'https://www.nintendolife.com/feeds/latest'),
    array('site' => 'PlayStation Blog', 'feed' => 'https://blog.playstation.com/feed/'),
    array('site' => 'Xbox Wire', 'feed' => 'https://news.xbox.com/en-us/feed/'),
);

$gaming_youtube_feeds = array(
    array('site' => 'Nintendo YouTube', 'feed' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCGIY_O-8vW4rfX98KlMkvRg'),
    array('site' => 'PlayStation YouTube', 'feed' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UC-2Y8dQb0S6DtpxNgAKoJKA'),
    array('site' => 'Xbox YouTube', 'feed' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCjBp_7RuDBUYbd1LegWEJ8g'),
    array('site' => 'IGN YouTube', 'feed' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCaWd5_7JhbQBe4dknZhsHJg'),
    array('site' => 'GameSpot YouTube', 'feed' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCbu2SsF-Or3Rsn3NxqODImw'),
);

function vg_out(int $code, array $data): void
{
    if (function_exists('http_response_code')) {
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function vg_safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function vg_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/vaultboy_gaming_state.json';
}

function vg_load_state(): array
{
    $path = vg_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function vg_save_state(array $state): void
{
    @file_put_contents(vg_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function vg_fetch_url(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'konvo-vaultboy-gaming-worker/1.0',
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
            'header' => "User-Agent: konvo-vaultboy-gaming-worker/1.0\r\n",
        ),
    ));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return array('ok' => false, 'status' => 0, 'error' => 'fetch failed', 'body' => '');
    return array('ok' => true, 'status' => 200, 'error' => '', 'body' => (string)$body);
}

function vg_decode_xml_text(string $text): string
{
    $text = str_replace(array('<![CDATA[', ']]>'), ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

function vg_normalize_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') return '';
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    if (!preg_match('/^https?:\/\//i', $url)) return '';
    return $url;
}

function vg_normalize_title(string $title): string
{
    $title = trim(strip_tags($title));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\p{Extended_Pictographic}/u', '', (string)$title) ?? $title;
    $title = preg_replace('/\s+/', ' ', (string)$title);
    $title = trim((string)$title);
    if ($title === '') return 'Gaming update';
    $title = preg_replace('/[:;,\.\-]+$/', '', (string)$title) ?? $title;
    $title = trim((string)$title);
    return trim((string)$title);
}

function vg_parse_feed_items(string $xml, int $maxItems, string $source, string $feedUrl, string $kind): array
{
    $items = array();
    if ($xml === '') return $items;

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

        if (preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $block, $t)) {
            $title = vg_decode_xml_text((string)$t[1]);
        }
        if (preg_match('/<link[^>]*>([\s\S]*?)<\/link>/i', $block, $l)) {
            $link = vg_decode_xml_text((string)$l[1]);
        }
        if ($link === '' && preg_match('/<link[^>]*href=["\']([^"\']+)["\']/i', $block, $lh)) {
            $link = trim((string)$lh[1]);
        }
        if ($link === '' && preg_match('/<guid[^>]*>([\s\S]*?)<\/guid>/i', $block, $g)) {
            $guid = vg_decode_xml_text((string)$g[1]);
            if (preg_match('/^https?:\/\//i', $guid)) $link = $guid;
        }
        if (preg_match('/<description[^>]*>([\s\S]*?)<\/description>/i', $block, $d)) {
            $summary = vg_decode_xml_text((string)$d[1]);
        }
        if ($summary === '' && preg_match('/<summary[^>]*>([\s\S]*?)<\/summary>/i', $block, $s)) {
            $summary = vg_decode_xml_text((string)$s[1]);
        }
        if ($summary === '' && preg_match('/<content[^>]*>([\s\S]*?)<\/content>/i', $block, $c)) {
            $summary = vg_decode_xml_text((string)$c[1]);
        }

        $title = vg_normalize_title($title);
        $link = vg_normalize_url($link);
        if ($title === '' || $link === '') continue;

        $items[] = array(
            'title' => $title,
            'url' => $link,
            'summary' => trim((string)$summary),
            'source' => $source,
            'source_feed' => $feedUrl,
            'kind' => $kind,
        );
        if (count($items) >= $maxItems) break;
    }
    return $items;
}

function vg_is_gaming_topic(array $item, string $kind): bool
{
    $core = strtolower(trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '')
    ));
    $blob = strtolower(trim($core . "\n" . (string)($item['source'] ?? '')));
    if ($core === '') return false;

    $positive = (bool)preg_match(
        '/\b(game|gaming|gameplay|dlc|patch|hotfix|speedrun|modding|mod|quest|boss|xp|level cap|battle pass|season|launch|release date|early access|xbox|playstation|ps5|ps4|nintendo|switch|steam|epic games|riot|blizzard|ubisoft|capcom|fromsoftware|rpg|fps|mmo|jrpg|roguelike|metroidvania|co-?op|multiplayer|single-?player|easter egg|retro game|classic game|arcade|8-bit|16-bit|nes|snes|n64|nintendo 64|game boy|sega genesis|mega drive|dreamcast|ps1|playstation 1|ps2|playstation 2|super mario|legend of zelda|zelda|half[- ]life|mechwarrior)\b/i',
        $core
    );
    if (!$positive) return false;

    // Exclude entertainment-news bleedover when not actually about games.
    $negative = (bool)preg_match(
        '/\b(movie|film|tv show|television|box office|hollywood|actor|actress|disney animation|pixar|netflix series|celebrity|album release)\b/i',
        $blob
    );
    if ($negative) {
        // Keep if clearly game-specific despite entertainment terms.
        if (!preg_match('/\b(video game|gameplay|game update|game release|console|pc game)\b/i', $blob)) {
            return false;
        }
    }

    // YouTube feeds here are from game-focused channels, so keep unless obviously non-game.
    if ($kind === 'youtube') {
        if (preg_match('/\b(movie trailer|official movie trailer|in theaters|in theater|theaters|theater|now streaming|streaming now|blu-?ray|soundtrack|from the theater to your home)\b/i', $blob)) return false;
    }
    return true;
}

function vg_text_has_retro_signal(string $text): bool
{
    $blob = strtolower(trim($text));
    if ($blob === '') return false;
    return (bool)preg_match(
        '/\b(retro|classic|old school|old-school|arcade|8-bit|16-bit|80s|90s|dos|ms-dos|shareware|pixel art|super mario|mario kart|legend of zelda|zelda|ocarina of time|a link to the past|half[- ]life|mechwarrior|doom|quake|street fighter ii|metal slug|sonic|sega genesis|mega drive|snes|super nintendo|nes|n64|nintendo 64|game boy|dreamcast|ps1|playstation 1|ps2|playstation 2|arcade cabinet)\b/i',
        $blob
    );
}

function vg_is_retro_item(array $item): bool
{
    $blob = trim(
        (string)($item['title'] ?? '') . "\n"
        . (string)($item['summary'] ?? '') . "\n"
        . (string)($item['url'] ?? '') . "\n"
        . (string)($item['source'] ?? '')
    );
    return vg_text_has_retro_signal($blob);
}

function vg_fetch_feed_candidates(array $sources, string $kind, int $maxItemsPerFeed = 8): array
{
    $all = array();
    foreach ($sources as $src) {
        $feed = trim((string)($src['feed'] ?? ''));
        $site = trim((string)($src['site'] ?? ''));
        if ($feed === '' || $site === '') continue;
        $res = vg_fetch_url($feed);
        if (!$res['ok']) continue;
        $items = vg_parse_feed_items((string)$res['body'], $maxItemsPerFeed, $site, $feed, $kind);
        foreach ($items as $item) {
            if (!vg_is_gaming_topic($item, $kind)) continue;
            $all[] = $item;
        }
    }
    return $all;
}

function vg_title_tokens(string $text): array
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s]/', ' ', (string)$text);
    $parts = preg_split('/\s+/', (string)$text) ?: array();
    $stop = array(
        'the', 'and', 'or', 'for', 'with', 'from', 'this', 'that', 'will', 'into', 'about', 'after', 'before', 'what',
        'your', 'their', 'they', 'you', 'are', 'has', 'have', 'had', 'new', 'game', 'games', 'video', 'latest', 'news',
        'update', 'updates', 'clip', 'clips', 'trailer', 'teaser', 'launch', 'release', 'were', 'here', 'there', 'when',
        'just', 'many', 'more', 'most', 'some', 'coming', 'month', 'months', 'next', 'today', 'tomorrow', 'yesterday',
        'said', 'says', 'drop', 'dropped', 'time', 'home',
    );
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || strlen($p) < 4) continue;
        if (in_array($p, $stop, true)) continue;
        if (!in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

function vg_pick_article(array $articles, array $seen): ?array
{
    foreach ($articles as $a) {
        $u = (string)($a['url'] ?? '');
        if ($u === '') continue;
        if (!isset($seen[$u])) return $a;
    }
    return $articles[0] ?? null;
}

function vg_pick_primary_mode(array $articles, array $youtubeItems, array $seen): array
{
    $article = vg_pick_article($articles, $seen);
    $youtube = null;
    $retroYoutube = null;
    foreach ($youtubeItems as $y) {
        $u = (string)($y['url'] ?? '');
        if ($u === '') continue;
        if (!isset($seen[$u]) && vg_is_retro_item($y) && !is_array($retroYoutube)) {
            $retroYoutube = $y;
        }
        if (!isset($seen[$u])) {
            $youtube = $y;
            break;
        }
    }
    if (is_array($retroYoutube)) {
        $youtube = $retroYoutube;
    }
    if (!is_array($youtube) && is_array($youtubeItems[0] ?? null)) {
        $youtube = $youtubeItems[0];
    }

    // Bias toward YouTube-first posting to maximize relevant clips/trailers.
    $retroContext = (is_array($article) && vg_is_retro_item($article)) || (is_array($youtube) && vg_is_retro_item($youtube));
    $ytChance = $retroContext ? 85 : 65;
    if (is_array($youtube) && (!is_array($article) || mt_rand(1, 100) <= $ytChance)) {
        return array('mode' => 'youtube', 'article' => $article, 'youtube' => $youtube);
    }
    return array('mode' => 'article', 'article' => $article, 'youtube' => $youtube);
}

function vg_pick_relevant_youtube(?array $article, array $ytItems, array $seen): ?array
{
    if ($ytItems === array()) return null;
    if (!is_array($article)) {
        foreach ($ytItems as $y) {
            $u = (string)($y['url'] ?? '');
            if ($u !== '' && !isset($seen[$u])) return $y;
        }
        return $ytItems[0];
    }

    $baseText = trim((string)($article['title'] ?? ''));
    $tokens = vg_title_tokens($baseText);
    if ($tokens === array()) return null;
    $retroArticle = vg_is_retro_item($article);

    $best = null;
    $bestScore = -1;

    foreach ($ytItems as $y) {
        $yu = (string)($y['url'] ?? '');
        if ($yu === '') continue;
        $ytText = strtolower(trim((string)($y['title'] ?? '') . "\n" . (string)($y['summary'] ?? '')));
        $score = 0;
        $overlap = 0;
        foreach ($tokens as $t) {
            if ($t !== '' && str_contains($ytText, $t)) {
                $score++;
                $overlap++;
            }
        }
        if (preg_match('/\b(trailer|gameplay|launch|release date|easter egg|secret|teaser|preview)\b/i', $ytText)) {
            $score += 2;
        }
        $retroVideo = vg_text_has_retro_signal($ytText);
        if ($retroVideo) $score += 2;
        if ($retroArticle && $retroVideo) $score += 3;
        if ($retroArticle && !$retroVideo) $score -= 1;
        if (preg_match('/\b(world of longplays|longplayarchive|summoning salt|theradbrad|fightincowboy|shirrako)\b/i', $ytText)) {
            $score += 2;
        }
        if (!isset($seen[$yu])) $score += 1;
        if (preg_match('/\b(movie|film|tv show|box office)\b/i', $ytText)) $score -= 3;
        if ($overlap === 0) $score -= 4;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $y;
        }
    }

    if (is_array($best) && $bestScore >= 4) return $best;
    return null;
}

function vg_extract_first_youtube_video_id(string $text): string
{
    if ($text === '') return '';
    if (preg_match('/"videoId":"([A-Za-z0-9_-]{11})"/', $text, $m)) {
        return (string)$m[1];
    }
    if (preg_match('/\/watch\?v=([A-Za-z0-9_-]{11})/', $text, $m2)) {
        return (string)$m2[1];
    }
    return '';
}

function vg_search_related_youtube(?array $article): ?array
{
    if (!is_array($article)) return null;
    $title = trim((string)($article['title'] ?? ''));
    $summary = trim((string)($article['summary'] ?? ''));
    $retro = vg_text_has_retro_signal($title . "\n" . $summary . "\n" . (string)($article['url'] ?? ''));
    $queries = array();
    if ($title !== '' && $retro) {
        $queries[] = $title . ' full walkthrough longplay';
        $queries[] = $title . ' retro gameplay';
        $queries[] = $title . ' World of Longplays';
        $queries[] = $title . ' LongplayArchive';
    }
    if ($title !== '') $queries[] = $title . ' trailer gameplay';
    if ($title !== '') $queries[] = $title . ' gameplay walkthrough';
    if ($title !== '' && $summary !== '') $queries[] = $title . ' ' . substr($summary, 0, 80);
    if ($title !== '') $queries[] = $title;

    foreach ($queries as $q) {
        $searchUrl = 'https://www.youtube.com/results?search_query=' . rawurlencode($q);
        $res = vg_fetch_url($searchUrl);
        if (!is_array($res) || empty($res['ok']) || !isset($res['body'])) continue;
        $videoId = vg_extract_first_youtube_video_id((string)$res['body']);
        if ($videoId === '') continue;
        return array(
            'title' => '',
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'summary' => '',
            'source' => 'YouTube Search',
            'source_feed' => $searchUrl,
            'kind' => 'youtube',
        );
    }
    return null;
}

function vg_title_looks_incomplete(string $title): bool
{
    $t = strtolower(trim($title));
    if ($t === '') return true;
    if (preg_match('/\b(and|or|to|for|with|of|in|on|at|from|by|about|than|the|a|an)\s*$/i', $t)) return true;
    if (preg_match('/\b(with|for|about|around|across)\s+(the|a|an)\s*$/i', $t)) return true;
    return false;
}

function vg_clean_text(string $text): string
{
    $text = trim(strip_tags($text));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', (string)$text);
    $text = trim((string)$text, " \t\n\r\0\x0B\"'`“”‘’");
    return trim((string)$text);
}

function vg_openai_chat(array $messages, float $temperature, int $maxTokens, string $task): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY missing');
    }

    $payload = array(
        'model' => konvo_model_for_task($task),
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
    );

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
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
        return array('ok' => false, 'error' => 'OpenAI response format error');
    }
    if ($status < 200 || $status >= 300) {
        $msg = trim((string)($decoded['error']['message'] ?? 'OpenAI HTTP ' . $status));
        return array('ok' => false, 'error' => $msg);
    }
    return array('ok' => true, 'text' => trim((string)$decoded['choices'][0]['message']['content']));
}

function vg_generate_title_once(array $article, ?array $youtube, bool $strict): array
{
    $sourceTitle = trim((string)($article['title'] ?? 'Gaming update'));
    $sourceSummary = trim((string)($article['summary'] ?? ''));
    $youtubeTitle = is_array($youtube) ? trim((string)($youtube['title'] ?? '')) : '';

    $strictLine = $strict
        ? 'Your previous title was awkward or incomplete. Rewrite with simpler words and a complete thought.'
        : 'Generate your best title on the first try.';
    $system = 'Write one short forum post title. Return only JSON: {"title":"..."} '
        . 'Rules: 4-10 words, sentence case, complete thought, casual human wording, no colon, no emoji, no clickbait. '
        . 'Do not end with trailing prepositions/articles or unfinished phrasing.';
    $user = "Source title: {$sourceTitle}\n"
        . "Source summary: {$sourceSummary}\n"
        . "Related YouTube title: {$youtubeTitle}\n"
        . $strictLine . "\n"
        . "Rewrite the title in your own words so it is short and human.";

    $res = vg_openai_chat(
        array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        0.6,
        140,
        'article_title'
    );
    if (!$res['ok']) return array('ok' => false, 'error' => (string)$res['error']);

    $txt = trim((string)$res['text']);
    $json = null;
    if ($txt !== '') {
        $json = json_decode($txt, true);
        if (!is_array($json)) {
            $start = strpos($txt, '{');
            $end = strrpos($txt, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $json = json_decode(substr($txt, (int)$start, (int)($end - $start + 1)), true);
            }
        }
    }

    $title = '';
    if (is_array($json) && isset($json['title'])) {
        $title = vg_normalize_title((string)$json['title']);
    }
    if ($title === '') {
        $line = trim((string)strtok($txt, "\n"));
        $line = preg_replace('/^title\s*:\s*/i', '', (string)$line);
        $title = vg_normalize_title((string)$line);
    }
    if ($title === '') return array('ok' => false, 'error' => 'Generated title was empty');
    if (strlen($title) > 68) return array('ok' => false, 'error' => 'Generated title exceeded length limit');
    if (vg_title_looks_incomplete($title)) return array('ok' => false, 'error' => 'Generated title looked incomplete');

    return array('ok' => true, 'title' => $title);
}

function vg_generate_title(array $article, ?array $youtube): array
{
    $sourceTitle = vg_normalize_title((string)($article['title'] ?? 'Gaming update'));
    $first = vg_generate_title_once($article, $youtube, false);
    if (!empty($first['ok'])) return $first;
    $second = vg_generate_title_once($article, $youtube, true);
    if (!empty($second['ok'])) return $second;

    $recovery = vg_openai_chat(
        array(
            array(
                'role' => 'system',
                'content' => 'Write one concise gaming forum title as plain text. 4-10 words, complete thought, no colon, no emoji.'
            ),
            array(
                'role' => 'user',
                'content' => "Source title: {$sourceTitle}\nRewrite this as a shorter human title."
            ),
        ),
        0.6,
        80,
        'article_title'
    );
    if (!empty($recovery['ok']) && isset($recovery['text'])) {
        $line = trim((string)strtok((string)$recovery['text'], "\n"));
        $line = preg_replace('/^title\s*:\s*/i', '', (string)$line);
        $title = vg_normalize_title((string)$line);
        if ($title !== '' && !vg_title_looks_incomplete($title)) {
            if (strlen($title) > 68) {
                $short = trim((string)substr($title, 0, 68));
                $lastSpace = strrpos($short, ' ');
                if ($lastSpace !== false && $lastSpace > 24) $short = trim((string)substr($short, 0, (int)$lastSpace));
                $title = $short;
            }
            if ($title !== '') return array('ok' => true, 'title' => $title);
        }
    }

    if ($sourceTitle === '') $sourceTitle = 'Gaming update';
    return array('ok' => true, 'title' => $sourceTitle);
}

function vg_generate_blurb(array $bot, array $article, ?array $youtube): string
{
    $botName = trim((string)($bot['name'] ?? 'VaultBoy'));
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul(
            trim((string)($bot['soul_key'] ?? 'vaultboy')),
            trim((string)($bot['soul_fallback'] ?? 'You are VaultBoy. Casual, playful, game-obsessed, and concise.'))
        )
    );

    $articleTitle = trim((string)($article['title'] ?? ''));
    $articleSummary = trim((string)($article['summary'] ?? ''));
    $ytTitle = is_array($youtube) ? trim((string)($youtube['title'] ?? '')) : '';
    $retro = vg_text_has_retro_signal($articleTitle . "\n" . $articleSummary . "\n" . $ytTitle);

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'Write a short forum intro about a gaming news item. '
        . ($retro ? 'Lean slightly toward retro/classic framing when it fits. ' : '')
        . 'Output only plain text, 1-2 short sentences, casual and human. '
        . 'No ending question. No fluff. Keep it specific.';
    $user = "Posting bot: {$botName}\n"
        . "Article title: {$articleTitle}\n"
        . "Article summary: {$articleSummary}\n"
        . "Related YouTube title: {$ytTitle}\n"
        . "Write a concise intro for a gaming forum post.";

    $res = vg_openai_chat(
        array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        0.7,
        180,
        'article_summary'
    );
    if (!$res['ok']) {
        $fallback = vg_clean_text($articleSummary);
        if ($fallback === '') $fallback = vg_clean_text($articleTitle);
        if ($fallback === '') $fallback = 'Quick gaming update worth checking.';
        return vg_compact_blurb($fallback, $articleTitle);
    }
    $text = vg_clean_text((string)$res['text']);
    if ($text === '') {
        $text = vg_clean_text($articleSummary);
    }
    if ($text === '') {
        $text = 'Quick gaming update worth checking.';
    }
    return vg_compact_blurb($text, $articleTitle);
}

function vg_generate_video_intro(array $bot, array $article, ?array $youtube): string
{
    $botName = trim((string)($bot['name'] ?? 'VaultBoy'));
    $soulPrompt = konvo_compose_forum_persona_system_prompt(
        konvo_load_soul(
            trim((string)($bot['soul_key'] ?? 'vaultboy')),
            trim((string)($bot['soul_fallback'] ?? 'You are VaultBoy. Casual, playful, game-obsessed, and concise.'))
        )
    );
    $articleTitle = trim((string)($article['title'] ?? ''));
    $articleSummary = trim((string)($article['summary'] ?? ''));
    $youtubeTitle = is_array($youtube) ? trim((string)($youtube['title'] ?? '')) : '';
    $youtubeUrl = is_array($youtube) ? trim((string)($youtube['url'] ?? '')) : '';
    $retro = vg_text_has_retro_signal($articleTitle . "\n" . $articleSummary . "\n" . $youtubeTitle . "\n" . $youtubeUrl);

    $system = ($soulPrompt !== '' ? "Bot voice and personality guidance:\n{$soulPrompt}\n\n" : '')
        . 'Write exactly one short sentence introducing a YouTube video in a gaming forum post. '
        . ($retro ? 'This is retro/classic gaming context, so mention that angle naturally. ' : '')
        . 'Say what the video shows. Output plain text only. No URL, no markdown, no signature, no emoji.';
    $user = "Posting bot: {$botName}\n"
        . "Article title: {$articleTitle}\n"
        . "Article summary: {$articleSummary}\n"
        . "YouTube title: {$youtubeTitle}\n"
        . "YouTube URL: {$youtubeUrl}\n"
        . "Write one concise intro sentence for the video link.";

    $res = vg_openai_chat(
        array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        0.7,
        120,
        'article_video_lead'
    );

    $text = '';
    if (is_array($res) && !empty($res['ok'])) {
        $text = vg_clean_text((string)($res['text'] ?? ''));
        $text = preg_replace('/https?:\/\/\S+/i', '', (string)$text);
        $text = trim((string)$text);
    }
    if ($text === '') {
        $fallbackTitle = $youtubeTitle !== '' ? $youtubeTitle : $articleTitle;
        if ($fallbackTitle !== '') {
            $text = 'This video shows the update highlights in action.';
        } else {
            $text = 'This video gives a quick look at the update.';
        }
    }
    if (!preg_match('/[.!]$/', $text)) $text .= '.';
    return trim((string)$text);
}

function vg_compact_blurb(string $text, string $fallbackTitle = ''): string
{
    $text = vg_clean_text($text);
    $text = preg_replace('/https?:\/\/\S+/i', '', (string)$text);
    $text = preg_replace('/["“”]/u', '', (string)$text);
    $text = trim((string)$text);
    if ($text === '') $text = vg_clean_text($fallbackTitle);
    if ($text === '') $text = 'Quick gaming update worth checking.';

    $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: array();
    $kept = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $kept[] = $p;
        if (count($kept) >= 2) break;
    }
    if ($kept !== array()) {
        $text = trim(implode(' ', $kept));
    }

    if (strlen($text) > 170) {
        $short = trim((string)substr($text, 0, 170));
        $lastPunct = max((int)strrpos($short, '. '), (int)strrpos($short, '! '));
        if ($lastPunct > 70) {
            $short = trim((string)substr($short, 0, $lastPunct + 1));
        } else {
            $lastSpace = strrpos($short, ' ');
            if ($lastSpace !== false && $lastSpace > 80) {
                $short = trim((string)substr($short, 0, (int)$lastSpace));
            }
        }
        $text = trim($short);
    }
    if (!preg_match('/[.!]$/', $text)) $text .= '.';
    return $text;
}

function vg_build_body(array $bot, array $article, ?array $youtube, string $signature): string
{
    $blurb = vg_generate_blurb($bot, $article, $youtube);
    $articleUrl = trim((string)($article['url'] ?? ''));
    $youtubeUrl = is_array($youtube) ? trim((string)($youtube['url'] ?? '')) : '';
    $videoIntro = ($youtubeUrl !== '') ? vg_generate_video_intro($bot, $article, $youtube) : '';

    $lines = array();
    $lines[] = $blurb;
    if ($articleUrl !== '') {
        $lines[] = '';
        if ($youtubeUrl !== '' && $articleUrl === $youtubeUrl && $videoIntro !== '') {
            $lines[] = $videoIntro;
            $lines[] = '';
        }
        $lines[] = $articleUrl;
    }
    if ($youtubeUrl !== '' && $youtubeUrl !== $articleUrl) {
        $lines[] = '';
        if ($videoIntro !== '') {
            $lines[] = $videoIntro;
            $lines[] = '';
        }
        $lines[] = $youtubeUrl;
    }
    return trim(implode("\n", $lines));
}

function vg_post_topic(string $botUsername, string $title, string $raw): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }
    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => (int)KONVO_GAMING_CATEGORY_ID,
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
        'ok' => ($err === '') && $status >= 200 && $status < 300 && is_array($decoded),
        'status' => $status,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    vg_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !vg_safe_hash_equals(KONVO_SECRET, $providedKey)) {
    vg_out(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    vg_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_OPENAI_API_KEY === '') {
    vg_out(500, array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';

$state = vg_load_state();
$today = date('Y-m-d');
if (!$dryRun && !$force) {
    $lastDate = trim((string)($state['last_post_date'] ?? ''));
    if ($lastDate === $today) {
        vg_out(200, array('ok' => true, 'posted' => false, 'reason' => 'already_posted_today', 'date' => $today));
    }
}

$seen = isset($state['seen_urls']) && is_array($state['seen_urls']) ? $state['seen_urls'] : array();
$articles = vg_fetch_feed_candidates($game_article_feeds, 'article', 8);
$ytItems = vg_fetch_feed_candidates($gaming_youtube_feeds, 'youtube', 8);
if ($articles === array() && $ytItems === array()) {
    vg_out(500, array('ok' => false, 'error' => 'No live gaming candidates available'));
}

$picked = vg_pick_primary_mode($articles, $ytItems, $seen);
$primaryMode = (string)($picked['mode'] ?? 'article');
$article = is_array($picked['article'] ?? null) ? $picked['article'] : null;
$youtube = null;

if ($primaryMode === 'youtube' && is_array($picked['youtube'] ?? null)) {
    $youtube = $picked['youtube'];
    $article = array(
        'title' => (string)($youtube['title'] ?? 'Gaming clip'),
        'url' => (string)($youtube['url'] ?? ''),
        'summary' => (string)($youtube['summary'] ?? ''),
        'source' => (string)($youtube['source'] ?? 'YouTube'),
        'source_feed' => (string)($youtube['source_feed'] ?? ''),
        'kind' => 'article',
    );
} else {
    $youtube = vg_pick_relevant_youtube($article, $ytItems, $seen);
}

if (!is_array($article)) {
    vg_out(500, array('ok' => false, 'error' => 'Could not pick article candidate'));
}
if (!is_array($youtube)) {
    $youtube = vg_search_related_youtube($article);
}
if (!is_array($youtube) || trim((string)($youtube['url'] ?? '')) === '') {
    foreach ($ytItems as $y) {
        $u = trim((string)($y['url'] ?? ''));
        if ($u === '') continue;
        if (!isset($seen[$u])) {
            $youtube = $y;
            break;
        }
    }
}
if (!is_array($youtube) || trim((string)($youtube['url'] ?? '')) === '') {
    if (is_array($ytItems[0] ?? null)) {
        $youtube = $ytItems[0];
    }
}
if (!is_array($youtube) || trim((string)($youtube['url'] ?? '')) === '') {
    vg_out(500, array('ok' => false, 'error' => 'No YouTube video was available for gaming post'));
}

$sigSeed = strtolower($vaultboy['username'] . '|' . (string)($article['url'] ?? '') . '|' . (string)($article['title'] ?? ''));
$signature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)$vaultboy['name'], $sigSeed)
    : (function_exists('konvo_signature_base_name')
        ? konvo_signature_base_name((string)$vaultboy['name'])
        : (string)$vaultboy['name']);

$titleRes = vg_generate_title($article, $youtube);
$topicTitle = is_array($titleRes) && isset($titleRes['title'])
    ? trim((string)$titleRes['title'])
    : vg_normalize_title((string)($article['title'] ?? 'Gaming update'));
$topicRaw = vg_build_body($vaultboy, $article, $youtube, $signature);

if ($dryRun) {
    vg_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'bot' => $vaultboy,
        'topic' => array(
            'title' => $topicTitle,
            'category_id' => (int)KONVO_GAMING_CATEGORY_ID,
            'raw_preview' => $topicRaw,
        ),
        'picked' => array(
            'primary_mode' => $primaryMode,
            'article' => $article,
            'youtube' => $youtube,
        ),
        'candidate_count' => array(
            'articles' => count($articles),
            'youtube' => count($ytItems),
        ),
    ));
}

$posted = vg_post_topic((string)$vaultboy['username'], $topicTitle, $topicRaw);
if (!$posted['ok']) {
    vg_out(500, array(
        'ok' => false,
        'error' => 'Discourse post failed',
        'status' => (int)$posted['status'],
        'curl_error' => (string)$posted['error'],
        'response' => $posted['body'],
        'raw' => (string)$posted['raw'],
    ));
}

$topicId = (int)($posted['body']['topic_id'] ?? 0);
$postNumber = (int)($posted['body']['post_number'] ?? 1);
$postUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;

$articleUrl = trim((string)($article['url'] ?? ''));
$ytUrl = is_array($youtube) ? trim((string)($youtube['url'] ?? '')) : '';
if ($articleUrl !== '') $seen[$articleUrl] = time();
if ($ytUrl !== '') $seen[$ytUrl] = time();
arsort($seen);
$seen = array_slice($seen, 0, 900, true);

$state['last_post_date'] = $today;
$state['last_topic_id'] = $topicId;
$state['last_post_number'] = $postNumber;
$state['last_title'] = $topicTitle;
$state['seen_urls'] = $seen;
vg_save_state($state);

vg_out(200, array(
    'ok' => true,
    'posted' => true,
    'post_url' => $postUrl,
    'bot' => $vaultboy,
    'topic' => array(
        'title' => $topicTitle,
        'category_id' => (int)KONVO_GAMING_CATEGORY_ID,
    ),
    'picked' => array(
        'primary_mode' => $primaryMode,
        'article' => $article,
        'youtube' => $youtube,
    ),
));
