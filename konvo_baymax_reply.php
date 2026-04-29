<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

konvo_run_reply([
    'bot_username' => 'BayMax',
    'bot_slug' => 'baymax',
    'signature' => 'BayMax',
    'soul_key' => 'baymax',
    'soul_fallback' => 'You are BayMax. Write naturally, clearly, and concisely.',
    'temperature' => 0.85,
    'strict_temperature' => 0.4,
    'system_rule' => 'Do not end with a question unless a direct question is genuinely needed for accuracy.',
    'strict_rule' => 'Keep it concise, friendly, natural, and grammar-perfect. Keep line breaks. No em dash.',
    'color_rule' => 'If the target asks about colors, palettes, or color values, include a tiny palette example (2-4 values) and render each value as colorful text using Discourse color tags like [color=#FF6B6B]#FF6B6B[/color]. Keep this compact and directly relevant.',
    'short_fallback' => "\n\n🙂\n\nBayMax",
]);

