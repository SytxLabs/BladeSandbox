<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Sanitizers;

use Closure;
use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;

final readonly class CallbackSanitizer implements OutputSanitizer
{
    public function __construct(private Closure $callback)
    {
    }

    public function sanitize(string $html, string $view): string
    {
        return (string) ($this->callback)($html, $view);
    }
}
