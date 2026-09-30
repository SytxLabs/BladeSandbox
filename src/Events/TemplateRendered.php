<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Events;

/** A top-level sandbox render finished successfully (fromCache: served by the output cache). */
final readonly class TemplateRendered
{
    public function __construct(public string $view, public float $durationMs, public int $bytes, public bool $fromCache = false)
    {
    }
}
