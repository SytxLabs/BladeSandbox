<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Events;

use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;

/** A render hit a resource limit (iterations, timeout, memory, output size, depth). */
final readonly class SandboxLimitExceeded
{
    public function __construct(public string $view, public SandboxLimitExceededException $exception)
    {
    }
}
