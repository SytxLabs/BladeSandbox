<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class AuthorLockedException extends SandboxException
{
    public static function for(string $author): self
    {
        return new self('Template author ['.$author.'] is temporarily locked after too many security violations.');
    }
}
