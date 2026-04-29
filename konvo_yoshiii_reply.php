<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

konvo_run_reply([
    'bot_username' => 'yoshiii',
    'bot_slug' => 'yoshiii',
    'signature' => 'Yoshiii',
    'soul_key' => 'yoshiii',
    'soul_fallback' => 'You are Yoshiii. Write naturally, playfully, and concisely.',
    'temperature' => 0.95,
    'strict_temperature' => 0.4,
    'system_rule' => 'Do not end with a question unless needed.',
    'strict_rule' => 'Keep it concise, playful, natural, and grammatically clean. Keep line breaks. No em dash.',
    'color_rule' => 'If the target asks about colors, palettes, or color values, include a tiny palette example (2-4 values) and render each value as colorful text using Discourse color tags like [color=#FF6B6B]#FF6B6B[/color]. Keep this compact and directly relevant.',
    'short_fallback' => "\n\n🙂\n\nYoshiii",
]);
