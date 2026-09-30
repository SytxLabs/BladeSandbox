<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

final readonly class DtoPolicy
{
    /**
     * @param list<string> $classes lower-cased class / interface names
     * @param list<string> $namespaces lower-cased namespace prefixes ending with "\"
     */
    public function __construct(private array $classes = [], private array $namespaces = [], private bool $nativeConversion = true)
    {
    }

    public function allows(object|string $class): bool
    {
        if (is_string($class) && ! class_exists($class)) {
            return false;
        }
        $types = ObjectAccessPolicy::typesOf($class);
        foreach ($this->classes as $allowed) {
            if (in_array($allowed, $types, true)) {
                return true;
            }
        }
        $name = $types[0];
        foreach ($this->namespaces as $namespace) {
            if (str_starts_with($name, $namespace)) {
                return true;
            }
        }

        return false;
    }

    public function nativeConversion(): bool
    {
        return $this->nativeConversion;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $classes = $this->classes;
        $namespaces = $this->namespaces;
        sort($classes);
        sort($namespaces);
        return ['classes' => $classes, 'namespaces' => $namespaces, 'native' => $this->nativeConversion];
    }
}
