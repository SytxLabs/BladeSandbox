<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenLivewireActionException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Livewire action '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'livewire-action', $subject);
    }
}
