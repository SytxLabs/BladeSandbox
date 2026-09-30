<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Fallback;

use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use Throwable;

/** "source": the unrendered template as it is (Blade syntax stays visible, nothing is evaluated). */
final class SourceFallback implements FallbackRenderer
{
    public function render(string $source, string $view, Throwable $exception): string
    {
        return BladeStripper::removePhp($source);
    }
}
