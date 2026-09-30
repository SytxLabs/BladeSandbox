<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/**
 * Converts Markdown written by template authors into HTML (@markdown).
 * Implementations must not pass raw HTML through and must drop unsafe link schemes (javascript:, data:, ...).
 * Replace the default with BladeSandbox::useMarkdownConverter().
 */
interface MarkdownConverter
{
    public function convert(string $markdown): string;
}
