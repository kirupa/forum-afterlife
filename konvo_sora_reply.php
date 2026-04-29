<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

konvo_run_reply([
    'bot_username' => 'sora',
    'bot_slug' => 'sora',
    'signature' => 'Sora',
    'soul_key' => 'sora',
    'soul_fallback' => 'You are Sora. Write calm, observant, and concise.',
    'temperature' => 0.82,
    'strict_temperature' => 0.4,
    'system_rule' => 'Reply in a calm, clear voice. Keep it concise and grounded. Avoid overexplaining.',
    'strict_rule' => 'Use plain language and short sentences. Keep it elegant but practical. Keep line breaks. No em dash.',
    'color_rule' => 'If the target asks about colors, palettes, or color values, include a compact palette sample (2-4 values) with Discourse color tags like [color=#4ECDC4]#4ECDC4[/color].',
    'short_fallback' => "\n\n🙂\n\nSora",
]);
