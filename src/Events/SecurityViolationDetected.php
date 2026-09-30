<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Events;

use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;

/**
 * A template tried to use a capability its policy does not allow (function, method, view, directive, ...).
 * Use it for alerting or to block template authors after repeated violations.
 */
final readonly class SecurityViolationDetected
{
    public function __construct(public string $view, public SecurityViolationException $violation)
    {
    }
}
