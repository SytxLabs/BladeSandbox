<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

/**
 * Thrown at compile time when a template contains a construct that can never be sandboxed
 * (raw PHP, eval, include, new, static calls, closures, ...) or that cannot be parsed.
 */
final class InvalidSandboxTemplateException extends SecurityViolationException
{
    public static function forbiddenConstruct(string $construct, ?int $line = null): self
    {
        $exception = new self('Forbidden construct in sandboxed template: '.$construct.self::at($line).'.', 'construct', $construct);
        return $line !== null ? $exception->atLine($line) : $exception;
    }

    public static function syntax(string $what, ?int $line = null): self
    {
        $exception = new self('Invalid sandboxed template: '.$what.self::at($line).'.', 'syntax', $what);
        return $line !== null ? $exception->atLine($line) : $exception;
    }

    private static function at(?int $line): string
    {
        return $line !== null ? ' on template line '.$line : '';
    }
}
