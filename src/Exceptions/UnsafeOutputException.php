<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class UnsafeOutputException extends SecurityViolationException
{
    public static function for(string $view, array $findings = []): self
    {
        return new self('Rendered output of '.$view.' contains unsafe markup'.($findings !== [] ? ' ('.implode(', ', $findings).')' : '').'.', 'output', $view);
    }
}
