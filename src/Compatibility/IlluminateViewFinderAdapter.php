<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compatibility;

use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\View\Factory;
use Illuminate\View\ViewFinderInterface;
use InvalidArgumentException;

/** Adapter over Illuminate's ViewFinderInterface / FileViewFinder. The APIs used here (find, getHints, getExtensions) are identical in Laravel 10 – 13. */
final readonly class IlluminateViewFinderAdapter implements ViewFinderAdapter
{
    public function __construct(private FactoryContract $factory)
    {
    }

    /** @throws InvalidArgumentException if the view cannot be found */
    public function find(string $view): string
    {
        return $this->finder()->find($view);
    }

    public function exists(string $view): bool
    {
        try {
            $this->find($view);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function namespaceDirectories(string $namespace): array
    {
        $finder = $this->finder();
        return array_values(array_map('strval', (array) ((method_exists($finder, 'getHints') ? $finder->getHints() : [])[$namespace] ?? [])));
    }

    public function extensions(): array
    {
        $extensions = $this->factory instanceof Factory ? array_keys($this->factory->getExtensions()) : ['blade.php', 'php'];
        usort($extensions, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return $extensions;
    }

    private function finder(): ViewFinderInterface
    {
        if (!method_exists($this->factory, 'getFinder')) {
            throw new InvalidArgumentException('The view factory does not expose a view finder.');
        }
        return $this->factory->getFinder();
    }
}
