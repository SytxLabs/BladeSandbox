<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenMethodException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Method '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'method', $subject);
    }
}
