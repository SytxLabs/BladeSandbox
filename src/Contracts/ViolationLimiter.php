<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/** Counts security violations per template author and decides when an author is locked (see Sandbox::forAuthor()). Replace the default with BladeSandbox::useViolationLimiter(). */
interface ViolationLimiter
{
    /** Records a violation and returns the number of violations in the current window. */
    public function hit(string $author): int;

    public function hits(string $author): int;

    public function isLocked(string $author): bool;

    /** Unlocks the author and resets the counter. */
    public function clear(string $author): void;

    /** Violations after which an author is locked. */
    public function maxViolations(): int;
}
