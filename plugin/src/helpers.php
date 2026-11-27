<?php

declare(strict_types=1);

/** Escape a value for HTML output. */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gd_duration(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return '—';
    }
    return $seconds < 60 ? $seconds . 's' : intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
}

/** Shorten text to $max characters (UTF-8 safe, no mbstring needed). */
function gd_trim(string $text, int $max): string
{
    if (preg_match('/^.{0,' . $max . '}/us', $text, $m) !== 1 || $m[0] === $text) {
        return $text;
    }
    return rtrim($m[0]) . '…';
}
