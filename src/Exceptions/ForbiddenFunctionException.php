<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenFunctionException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Function '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'function', $subject);
    }
}
