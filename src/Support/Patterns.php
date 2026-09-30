<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Support;

final class Patterns
{
    public static function isValidName(string $name): bool
    {
        return preg_match('/\A(?:[A-Za-z0-9_\-]+::)?[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*\z/', $name) === 1;
    }

    public static function namespaceOf(string $name): ?string
    {
        $position = strpos($name, '::');
        return $position === false ? null : substr($name, 0, $position);
    }

    public static function matches(string $pattern, string $name): bool
    {
        return $pattern === $name || (str_contains($pattern, '*') && preg_match('/\A'.collect(preg_split('/(\*\*|\*)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [])->implode(fn ($part) => match ($part) {
            '**' => '[A-Za-z0-9_\-\.]+', '*' => '[A-Za-z0-9_\-]+', default => preg_quote($part, '/')
        }, '').'\z/', $name) === 1);
    }

    /** @param iterable<string> $patterns */
    public static function matchesAny(iterable $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $name)) {
                return true;
            }
        }
        return false;
    }
}
