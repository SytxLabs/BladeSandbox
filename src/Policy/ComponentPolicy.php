<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use Closure;
use SytxLabs\BladeSandbox\Support\Patterns;

final readonly class ComponentPolicy
{
    /**
     * @param list<string> $components exact names or wildcard patterns (e.g. "plugin::button", "plugin::forms.*")
     * @param array<string, Closure> $factories explicit constructors for class components that need services
     */
    public function __construct(private array $components = [], private array $factories = [])
    {
    }

    public function allows(string $component): bool
    {
        return Patterns::isValidName($component) && Patterns::matchesAny($this->components, $component);
    }

    public function factory(string $component): ?Closure
    {
        if (isset($this->factories[$component])) {
            return $this->factories[$component];
        }

        foreach ($this->factories as $pattern => $factory) {
            if (Patterns::matches($pattern, $component)) {
                return $factory;
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    public function describe(): array
    {
        $components = $this->components;
        $factories = array_keys($this->factories);
        sort($components);
        sort($factories);
        return ['components' => $components, 'factories' => $factories];
    }
}
