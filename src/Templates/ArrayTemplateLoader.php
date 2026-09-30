<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Templates;

use InvalidArgumentException;
use SytxLabs\BladeSandbox\Contracts\TemplateLoader;

final class ArrayTemplateLoader implements TemplateLoader
{
    /** @param array<string, string> $templates */
    public function __construct(private array $templates = [])
    {
    }

    public function put(string $name, string $source): self
    {
        $this->templates[$name] = $source;
        return $this;
    }

    public function exists(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    public function source(string $name): string
    {
        return $this->templates[$name] ?? throw new InvalidArgumentException('Template ['.$name.'] not found.');
    }
}
