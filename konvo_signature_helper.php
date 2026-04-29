<?php

declare(strict_types=1);

function konvo_signature_base_name(string $name): string
{
    $raw = trim((string)$name);
    if ($raw === '') return 'Bot';

    $compact = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $raw));
    return match ($compact) {
        'sarahconnor' => 'Sarah',
        'arthurdent' => 'Arthur',
        'hariseldon' => 'Hari',
        'ellen1979' => 'Ellen',
        'baymax' => 'BayMax',
        'kirupabot' => 'kirupaBot',
        'mechaprime' => 'MechaPrime',
        'yoshiii' => 'Yoshiii',
        'bobamilk' => 'BobaMilk',
        'wafflefries' => 'WaffleFries',
        'vaultboy' => 'VaultBoy',
        'quelly' => 'Quelly',
        'sora' => 'Sora',
        default => $raw,
    };
}

function konvo_signature_name_candidates(string $name): array
{
    $candidates = array();
    $raw = trim((string)$name);
    if ($raw !== '') $candidates[] = $raw;

    $base = konvo_signature_base_name($raw);
    if ($base !== '') $candidates[] = $base;

    $deemoji = trim((string)preg_replace('/[^\p{L}\p{N}\s]/u', '', $raw));
    if ($deemoji !== '') $candidates[] = $deemoji;

    $fromDeemoji = konvo_signature_base_name($deemoji);
    if ($fromDeemoji !== '') $candidates[] = $fromDeemoji;

    $out = array();
    foreach ($candidates as $c) {
        $k = strtolower(trim((string)$c));
        if ($k === '' || isset($out[$k])) continue;
        $out[$k] = trim((string)$c);
    }
    return array_values($out);
}

function konvo_signature_with_optional_emoji(string $name, string $seed = ''): string
{
    $base = konvo_signature_base_name($name);
    if ($base === '') $base = 'Bot';

    $seedText = strtolower(trim((string)$seed));
    if ($seedText === '') $seedText = strtolower($base);

    $roll = abs((int)crc32($seedText . '|signature'));
    if (($roll % 100) >= 18) {
        return $base;
    }

    $emojis = array('😀', '🙂', '😄', '😊', '😎');
    $idx = abs((int)crc32($seedText . '|emoji|' . strtolower($base))) % count($emojis);
    return $base . ' ' . $emojis[$idx];
}
