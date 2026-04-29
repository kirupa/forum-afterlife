<?php

declare(strict_types=1);

function konvo_load_skill_file(string $relativePath, string $fallback = ''): string
{
    $safePath = ltrim($relativePath, '/');
    $path = __DIR__ . '/' . $safePath;
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

function konvo_load_humanizer_skill(string $fallback = ''): string
{
    return konvo_load_skill_file('skills/humanizer/SKILL.md', $fallback);
}

function konvo_load_stop_slop_skill(string $fallback = ''): string
{
    return konvo_load_skill_file('skills/stop-slop/SKILL.md', $fallback);
}

function konvo_load_writing_style_skills(): string
{
    $chunks = [];
    $humanizer = konvo_load_humanizer_skill();
    if ($humanizer !== '') {
        $chunks[] = "Humanizer skill guidance:\n" . $humanizer;
    }

    $stopSlop = konvo_load_stop_slop_skill();
    if ($stopSlop !== '') {
        $chunks[] = "Stop-slop skill guidance:\n" . $stopSlop;
    }

    return trim(implode("\n\n", $chunks));
}
