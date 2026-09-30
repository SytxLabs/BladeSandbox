<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Fallback;

use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use Throwable;

/** "empty": no output (the failure is still reported and dispatched as an event). */
final class EmptyFallback implements FallbackRenderer
{
    public function render(string $source, string $view, Throwable $exception): string
    {
        return '';
    }
}
