<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

use Throwable;

/**
 * Produces the output returned instead of an exception when a render fails and the sandbox has a fallback enabled (renderFallback()).
 * The result is post-processed by the sandbox: attributes of JavaScript frameworks the policy does not allow are removed and the configured output sanitizer runs.
 */
interface FallbackRenderer
{
    /**
     * @param string $source the unrendered template source of the top-level view ('' if unavailable)
     */
    public function render(string $source, string $view, Throwable $exception): string;
}
