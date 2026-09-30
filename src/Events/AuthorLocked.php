<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Events;

/** A template author reached the violation limit and is locked (Sandbox::forAuthor()). */
final readonly class AuthorLocked
{
    public function __construct(public string $author, public int $violations)
    {
    }
}
