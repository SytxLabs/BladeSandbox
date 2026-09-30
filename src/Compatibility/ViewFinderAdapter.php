<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compatibility;

/**
 * Resolves view names to files through the application's normal view finder (namespaces, package views, published overrides) without trusting the result.
 */
interface ViewFinderAdapter
{
    /** Absolute path of the view file; throws \InvalidArgumentException when it does not exist. */
    public function find(string $view): string;

    public function exists(string $view): bool;

    /**
     * Directories registered for a namespace (addNamespace/prependNamespace/replaceNamespace/loadViewsFrom).
     *
     * @return list<string>
     */
    public function namespaceDirectories(string $namespace): array;

    /** @return list<string> file extensions known to the view factory, longest first */
    public function extensions(): array;
}
