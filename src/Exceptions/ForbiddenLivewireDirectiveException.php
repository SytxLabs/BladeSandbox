<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenLivewireDirectiveException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Livewire directive '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'livewire-directive', $subject);
    }
}
