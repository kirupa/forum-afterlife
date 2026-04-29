<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

function tightenBobaMilkReply(string $text, bool $isCodeQuestion, bool $wantsArchDiagram): string
{
    $text = trim($text);
    if ($text === '') {
        return $text;
    }

    $lines = preg_split('/\R+/', $text) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(feel free to|it[\'’]s always|if you need (some )?inspiration)/i', $line)) {
            continue;
        }
        $kept[] = $line;
    }

    $text = trim(implode("\n\n", $kept));
    if ($text === '' || $wantsArchDiagram) {
        return $text;
    }

    $maxChars = $isCodeQuestion ? 360 : 200;
    $maxSentences = $isCodeQuestion ? 3 : 2;
    if (strlen($text) <= $maxChars) {
        return $text;
    }

    $sentences = preg_split('/(?<=[.!?])\s+/u', preg_replace('/\s+/', ' ', $text) ?? $text) ?: [];
    $picked = [];
    $len = 0;
    foreach ($sentences as $s) {
        $s = trim($s);
        if ($s === '') {
            continue;
        }
        $nextLen = $len + ($len > 0 ? 1 : 0) + strlen($s);
        if ($nextLen > $maxChars || count($picked) >= $maxSentences) {
            break;
        }
        $picked[] = $s;
        $len = $nextLen;
    }

    if ($picked === []) {
        return rtrim(substr($text, 0, $maxChars));
    }

    return trim(implode(' ', $picked));
}

konvo_run_reply([
    'bot_username' => 'bobamilk',
    'bot_slug' => 'bobamilk',
    'signature' => 'BobaMilk',
    'soul_key' => 'bobamilk',
    'soul_fallback' => 'You are BobaMilk. Write in very short, simple, natural phrasing.',
    'temperature' => 0.6,
    'strict_temperature' => 0.4,
    'system_rule' => 'Do not end with a question unless needed. Keep it to 1-3 short sentences total (unless a code block is required). No generic wrap-up lines, no call-to-action lines, and no extra niceties at the end.',
    'strict_rule' => 'Keep it concise, playful, natural, and grammatically clean. Use very short and simple phrasings. Sound like a human in a hurry. Keep it to 1-2 short sentences unless code formatting is needed. Remove generic wrap-up/filler lines. If relevant, lightly reflect an architecture/design student perspective. Keep line breaks. No em dash.',
    'color_rule' => 'If the target asks about colors, palettes, or color values, include one tiny palette example (2-3 values) and render each value as colorful text using Discourse color tags like "#FF6B6B - color name". Keep this very compact.',
    'short_fallback' => "\n\n🙂\n\nBobaMilk",
    'postprocess_fn' => 'tightenBobaMilkReply',
]);
