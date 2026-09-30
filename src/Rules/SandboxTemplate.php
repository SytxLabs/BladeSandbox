<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use SytxLabs\BladeSandbox\Sandbox;

final readonly class SandboxTemplate implements ValidationRule
{
    public function __construct(private Sandbox $sandbox)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The :attribute must be a template string.');
            return;
        }

        foreach ($this->sandbox->validate($value, $attribute)->violations() as $violation) {
            $fail(($violation->line !== null ? 'Line '.$violation->line.': ' : '').$violation->message);
        }
    }
}
