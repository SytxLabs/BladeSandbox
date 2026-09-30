<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/**
 * Storage for compiled sandbox templates.
 *
 * Compiled templates are PHP files that get included, so every store must hand out paths on a local filesystem that untrusted parties cannot write to. Keys are content-addressed sha256 hex digests.
 */
interface CompiledTemplateStore
{
    /** Path of the stored compiled template, or null when the key is not stored yet. */
    public function get(string $key): ?string;

    /** Stores the compiled template atomically and returns its path. */
    public function put(string $key, string $compiled): string;

    /** Removes every stored compiled template. */
    public function flush(): void;

    /** The directory the compiled templates are stored in (for diagnostics / blade-sandbox:clear output). */
    public function directory(): string;
}
