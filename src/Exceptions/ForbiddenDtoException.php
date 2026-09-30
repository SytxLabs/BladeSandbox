<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenDtoException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('DTO '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'dto', $subject);
    }
}
