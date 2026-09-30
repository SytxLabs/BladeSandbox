<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Security;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use SytxLabs\BladeSandbox\Contracts\ViolationLimiter;

/**
 * Default ViolationLimiter on Laravel's cache: an author with max_violations security violations
 * within decay_minutes is locked until the window expires (or clear() is called).
 */
final readonly class AuthorLockout implements ViolationLimiter
{
    private RateLimiter $limiter;

    public function __construct(Repository $cache, private int $maxViolations = 5, private int $decayMinutes = 60, private string $prefix = 'blade-sandbox:lockout:')
    {
        $this->limiter = new RateLimiter($cache);
    }

    public function hit(string $author): int
    {
        return $this->limiter->hit($this->key($author), max(1, $this->decayMinutes) * 60);
    }

    public function hits(string $author): int
    {
        return (int) $this->limiter->attempts($this->key($author));
    }

    public function isLocked(string $author): bool
    {
        return $this->hits($author) >= $this->maxViolations();
    }

    public function clear(string $author): void
    {
        $this->limiter->clear($this->key($author));
    }

    public function maxViolations(): int
    {
        return max(1, $this->maxViolations);
    }

    private function key(string $author): string
    {
        return $this->prefix.hash('sha256', $author);
    }
}
