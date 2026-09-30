<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenDirectiveException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Directive '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'directive', $subject);
    }
}
