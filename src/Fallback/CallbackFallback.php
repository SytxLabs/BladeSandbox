<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Fallback;

use Closure;
use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use Throwable;

final readonly class CallbackFallback implements FallbackRenderer
{
    /** @param Closure(string, string, Throwable): string $callback */
    public function __construct(private Closure $callback)
    {
    }

    public function render(string $source, string $view, Throwable $exception): string
    {
        return ($this->callback)($source, $view, $exception);
    }
}
