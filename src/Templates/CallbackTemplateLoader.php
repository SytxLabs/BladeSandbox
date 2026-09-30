<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Templates;

use Closure;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Contracts\TemplateLoader;

final class CallbackTemplateLoader implements TemplateLoader
{
    /** @var array<string, string|null> */
    private array $memo = [];

    /** @param Closure(string): ?string $resolver */
    public function __construct(private readonly Closure $resolver)
    {
    }

    public function exists(string $name): bool
    {
        return $this->lookup($name) !== null;
    }

    public function source(string $name): string
    {
        return $this->lookup($name) ?? throw new InvalidArgumentException('Template ['.$name.'] not found.');
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    private function lookup(string $name): ?string
    {
        if (!array_key_exists($name, $this->memo)) {
            $source = ($this->resolver)($name);
            $this->memo[$name] = is_string($source) ? $source : null;
        }
        return $this->memo[$name];
    }
}
