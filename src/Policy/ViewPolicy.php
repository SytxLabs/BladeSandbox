<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use SytxLabs\BladeSandbox\Support\Patterns;

final readonly class ViewPolicy
{
    /**
     * @param list<string> $views exact names or wildcard patterns
     * @param list<string> $namespaces whole namespaces
     */
    public function __construct(private array $views = [], private array $namespaces = [])
    {
    }

    public function allowsView(string $view): bool
    {
        if (!Patterns::isValidName($view)) {
            return false;
        }
        $namespace = Patterns::namespaceOf($view);
        if ($namespace !== null && $this->allowsNamespace($namespace)) {
            return true;
        }
        return Patterns::matchesAny($this->views, $view);
    }

    public function allowsNamespace(string $namespace): bool
    {
        return in_array($namespace, $this->namespaces, true);
    }

    /** @return array<string, list<string>> */
    public function describe(): array
    {
        $views = $this->views;
        $namespaces = $this->namespaces;
        sort($views);
        sort($namespaces);

        return ['views' => $views, 'namespaces' => $namespaces];
    }
}
