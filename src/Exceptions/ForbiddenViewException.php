<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

class ForbiddenViewException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('View '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'view', $subject);
    }
}
