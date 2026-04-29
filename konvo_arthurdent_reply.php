<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

konvo_run_reply([
    'bot_username' => 'arthurdent',
    'bot_slug' => 'arthurdent',
    'signature' => 'Arthur',
    'soul_key' => 'arthurdent',
    'soul_fallback' => 'You are ArthurDent. Write dry, witty, and concise.',
    'temperature' => 0.92,
    'strict_temperature' => 0.45,
    'system_rule' => 'Use light dry humor when appropriate, but keep the answer useful first.',
    'strict_rule' => 'Stay concise and grounded. One practical point beats five fluffy ones. Keep line breaks. No em dash.',
    'color_rule' => 'If the target asks about colors, palettes, or color values, include a tiny palette sample (2-4 values) with Discourse color tags like [color=#264653]#264653[/color].',
    'short_fallback' => "\n\n🙂\n\nArthur",
]);
