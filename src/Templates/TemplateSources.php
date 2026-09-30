<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Templates;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Compatibility\ViewFinderAdapter;
use SytxLabs\BladeSandbox\Contracts\TemplateLoader;

final class TemplateSources
{
    /** @var array<string, TemplateLoader> */
    private array $loaders = [];

    public function __construct(private readonly ViewFinderAdapter $finder, private readonly Filesystem $files)
    {
    }

    public function addLoader(string $namespace, TemplateLoader $loader): self
    {
        if (preg_match('/\A[A-Za-z0-9_\-]+\z/', $namespace) !== 1) {
            throw new InvalidArgumentException('Invalid template loader namespace ['.$namespace.'].');
        }
        $this->loaders[$namespace] = $loader;

        return $this;
    }

    public function loader(string $namespace): ?TemplateLoader
    {
        return $this->loaders[$namespace] ?? null;
    }

    /** @return array<string, TemplateLoader> */
    public function loaders(): array
    {
        return $this->loaders;
    }

    public function usesLoader(string $view): bool
    {
        return $this->split($view) !== null;
    }

    public function exists(string $view): bool
    {
        return (($split = $this->split($view)) !== null) ? $split[0]->exists($split[1]) : $this->finder->exists($view);
    }

    public function source(string $view): string
    {
        return (($split = $this->split($view)) !== null) ? $split[0]->source($split[1]) : $this->files->get($this->finder->find($view));
    }

    /** The file of a filesystem view, null for loader views. */
    public function path(string $view): ?string
    {
        return $this->usesLoader($view) ? null : $this->finder->find($view);
    }

    public function finder(): ViewFinderAdapter
    {
        return $this->finder;
    }

    /** @return array{0: TemplateLoader, 1: string}|null */
    private function split(string $view): ?array
    {
        if ($this->loaders === [] || !str_contains($view, '::')) {
            return null;
        }
        [$namespace, $name] = explode('::', $view, 2);
        return isset($this->loaders[$namespace]) ? [$this->loaders[$namespace], $name] : null;
    }
}
