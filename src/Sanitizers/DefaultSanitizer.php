<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Sanitizers;

use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;
use SytxLabs\FileSanitizer\FileSanitizer;

/** Uses sytxlabs/filesanitizer when it is installed (suggested dependency) and falls back to the built-in {@see HtmlSanitizer} otherwise. */
final class DefaultSanitizer implements OutputSanitizer
{
    private ?OutputSanitizer $resolved = null;

    /** @param bool|null $useFileSanitizer null = automatic (use FileSanitizer if installed) */
    public function __construct(private readonly bool $rejectUnsafe = false, private readonly ?bool $useFileSanitizer = null)
    {
    }

    public function sanitize(string $html, string $view): string
    {
        return $this->sanitizer()->sanitize($html, $view);
    }

    public function sanitizer(): OutputSanitizer
    {
        return $this->resolved ??= ($this->useFileSanitizer ?? class_exists(FileSanitizer::class)) ? new FileSanitizerHtmlSanitizer($this->rejectUnsafe) : new HtmlSanitizer(rejectUnsafe: $this->rejectUnsafe);
    }
}
