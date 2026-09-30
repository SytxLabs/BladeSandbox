<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Events;

use Throwable;

/** A top-level render failed for any reason. `fallback` names the fallback mode whose output was returned instead of throwing, or is null when the exception was rethrown. */
final readonly class TemplateRenderFailed
{
    public function __construct(public string $view, public Throwable $exception, public ?string $fallback = null)
    {
    }
}
