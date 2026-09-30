<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Sanitizers;

use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\UnsafeOutputException;
use SytxLabs\FileSanitizer\FileSanitizer;

/**
 * Output sanitizer backed by sytxlabs/filesanitizer (https://github.com/SytxLabs/FileSanitizer).
 *
 * Its HTML sanitizer keeps an allowlist of tags / attributes and removes scripts, event handlers,
 * javascript:/data: URLs, dangerous CSS and meta refreshes.
 *
 *  - default ("clean"): unsafe markup is removed, the rest of the page is kept;
 *  - rejectUnsafe: the render fails with UnsafeOutputException when the scanner finds unsafe markup.
 *
 * Note: the allowlist also removes Livewire (wire:*) and Alpine attributes, so do not use it for
 * Livewire views.
 */
final class FileSanitizerHtmlSanitizer implements OutputSanitizer
{
    public function __construct(private readonly bool $rejectUnsafe = false, private ?object $sanitizer = null)
    {
    }

    public function sanitize(string $html, string $view): string
    {
        if (trim($html) === '') {
            return $html;
        }
        /** @var array{scan: object, sanitizedData?: string|null} $result */
        $result = ($this->sanitizer ??= $this->makeSanitizer())->processString($html, 'sandbox-output.html', null, !$this->rejectUnsafe, 'text/html');

        $scan = $result['scan'];
        if ($this->rejectUnsafe && !$scan->safe) {
            throw UnsafeOutputException::for($view, array_values(array_unique(array_map(static fn (object $issue): string => (string) $issue->code, $scan->issues ?? []))));
        }
        return (string) ($result['sanitizedData'] ?? '');
    }

    private function makeSanitizer(): object
    {
        if (!class_exists(FileSanitizer::class)) {
            throw new SandboxException('The FileSanitizer output sanitizer needs the sytxlabs/filesanitizer package (composer require sytxlabs/filesanitizer).');
        }
        return new FileSanitizer();
    }
}
