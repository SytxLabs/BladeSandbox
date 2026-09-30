<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

class ForbiddenViewNamespaceException extends ForbiddenViewException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('View namespace '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'view-namespace', $subject);
    }
}
