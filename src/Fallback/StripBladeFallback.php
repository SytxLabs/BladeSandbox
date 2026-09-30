<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Fallback;

use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use Throwable;

/** "strip": the unrendered template with all Blade syntax removed (echoes, directives, comments, component and Livewire tags), leaving the static HTML skeleton. */
final class StripBladeFallback implements FallbackRenderer
{
    public function render(string $source, string $view, Throwable $exception): string
    {
        return BladeStripper::strip($source);
    }
}
