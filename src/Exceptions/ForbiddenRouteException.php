<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenRouteException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Route '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'route', $subject);
    }
}
