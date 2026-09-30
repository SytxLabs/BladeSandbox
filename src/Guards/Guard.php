<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;

abstract class Guard
{
    public function __construct(protected readonly SandboxPolicy $policy, protected readonly AuditLogger $audit, protected string $template = '')
    {
    }

    public function forTemplate(string $template): static
    {
        $this->template = $template;
        return $this;
    }

    /**
     * @template T of SecurityViolationException
     *
     * @param T $violation
     *
     * @return T
     */
    protected function deny(SecurityViolationException $violation): SecurityViolationException
    {
        $this->audit->record($violation, $this->template);
        return $violation;
    }

    protected static function describeType(mixed $value): string
    {
        return is_object($value) ? $value::class : get_debug_type($value);
    }
}
