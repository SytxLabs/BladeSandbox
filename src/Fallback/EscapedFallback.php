<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Fallback;

use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use Throwable;

/** "escaped": the unrendered template shown as escaped text in a <pre> block (e.g. for editors). */
final class EscapedFallback implements FallbackRenderer
{
    public function render(string $source, string $view, Throwable $exception): string
    {
        return '<pre class="blade-sandbox-fallback">'.htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
    }
}
