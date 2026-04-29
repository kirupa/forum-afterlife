<?php

declare(strict_types=1);

function konvo_normalize_soul_key(string $botKey): string
{
    $key = strtolower(trim($botKey));
    return match ($key) {
        'mechaprime' => 'mechaprime',
        'yoshiii' => 'yoshiii',
        'bobamilk' => 'bobamilk',
        'wafflefries' => 'wafflefries',
        'vaultboy' => 'vaultboy',
        'quelly' => 'quelly',
        'sora' => 'sora',
        'sarah_connor' => 'sarah_connor',
        'sarahconnor' => 'sarah_connor',
        'ellen1979' => 'ellen1979',
        'arthurdent' => 'arthurdent',
        'hariseldon' => 'hariseldon',
        'baymax' => 'baymax',
        'kirupabot' => 'kirupabot',
        default => preg_replace('/[^a-z0-9_-]/', '', $key) ?? '',
    };
}

function konvo_load_soul(string $botKey, string $fallback = ''): string
{
    $normalized = konvo_normalize_soul_key($botKey);
    if ($normalized === '') {
        return trim($fallback);
    }

    $path = __DIR__ . '/souls/' . $normalized . '.SOUL.md';
    if (!is_file($path) || !is_readable($path)) {
        return trim($fallback);
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return trim($fallback);
    }

    $contents = trim((string)$contents);
    return $contents !== '' ? $contents : trim($fallback);
}
