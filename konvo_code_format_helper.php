<?php

/*
 * Inline code formatting.
 *
 * Code mentioned in prose should be marked as code:
 *   Write a function findDuplicates(arr) ... should return [2,1].
 * becomes
 *   Write a function `findDuplicates(arr)` ... should return `[2,1]`.
 *
 * Deliberately conservative. Wrapping ordinary English in backticks looks far
 * worse than missing one token, so every pattern here requires a code-ish
 * signal (no space before the paren, digits or quotes or commas inside a
 * bracket, a colon inside braces).
 */

declare(strict_types=1);

if (!function_exists('konvo_format_inline_code')) {
    function konvo_format_inline_code(string $text): string
    {
        if (trim($text) === '') return $text;

        $slots = array();
        $stash = static function (string $chunk) use (&$slots): string {
            $token = "\x01KCF" . count($slots) . "\x02";
            $slots[$token] = $chunk;
            return $token;
        };

        // 1. Protect what must never be touched: fenced blocks, existing inline
        //    code, markdown links and images. Links matter most here, since a
        //    link's [label] would otherwise look exactly like an array literal.
        foreach (array('/```[\s\S]*?```/', '/`[^`\n]+`/', '/!?\[[^\]\n]*\]\([^)\s]+\)/') as $pattern) {
            $text = preg_replace_callback($pattern, static function ($m) use ($stash) {
                return $stash($m[0]);
            }, $text) ?? $text;
        }

        // 2. Function calls: name(...) with no space before the paren. English
        //    such as "the piece (see below)" has a space and is left alone.
        $text = preg_replace_callback(
            '/(?<![\w`$])([A-Za-z_$][A-Za-z0-9_$]{1,40})\(([^()\n]{0,90})\)/',
            static function ($m) use ($stash) {
                $name = $m[1];
                $args = $m[2];
                $looksCode = (bool)preg_match('/[A-Z]/', substr($name, 1))   // camelCase
                    || strpos($name, '_') !== false
                    || strpos($name, '$') !== false
                    || $args === ''
                    || (bool)preg_match('/[\d\'"\[\]{}=><.,]/', $args);
                // A run of ordinary words inside the parens means it is prose.
                if ($looksCode && preg_match('/^[A-Za-z]+(?:\s+[A-Za-z]+){2,}$/', trim($args))) {
                    $looksCode = false;
                }
                if (!$looksCode) return $m[0];
                return $stash('`' . $name . '(' . $args . ')`');
            },
            $text
        ) ?? $text;

        // 3. Array literals: need a digit or quote plus a comma, so "[2,1]" and
        //    "['a','b']" qualify while "[see the docs]" does not.
        $text = preg_replace_callback(
            '/(?<![\w`\]])\[([^\[\]\n]{1,70})\](?!\()/',
            static function ($m) use ($stash) {
                $inner = trim($m[1]);
                if ($inner === '') return $m[0];
                $hasData = (bool)preg_match('/[\d\'"]/', $inner);
                $hasComma = strpos($inner, ',') !== false;
                if (!$hasData || !$hasComma) return $m[0];
                if (preg_match('/[A-Za-z]{4,}\s+[A-Za-z]{4,}\s+[A-Za-z]{4,}/', $inner)) return $m[0];
                return $stash('`[' . $m[1] . ']`');
            },
            $text
        ) ?? $text;

        // 4. Object literals: braces containing a colon.
        $text = preg_replace_callback(
            '/(?<![\w`])\{([^{}\n]{1,70})\}/',
            static function ($m) use ($stash) {
                if (strpos($m[1], ':') === false) return $m[0];
                return $stash('`{' . $m[1] . '}`');
            },
            $text
        ) ?? $text;

        // 5. Property and method mentions written bare: .length, .push()
        $text = preg_replace_callback(
            '/(?<![\w`.])\.([A-Za-z_$][A-Za-z0-9_$]{1,30})\b(?!`)/',
            static function ($m) use ($stash) {
                return $stash('`.' . $m[1] . '`');
            },
            $text
        ) ?? $text;

        foreach ($slots as $token => $chunk) {
            $text = str_replace($token, $chunk, $text);
        }
        return $text;
    }
}
