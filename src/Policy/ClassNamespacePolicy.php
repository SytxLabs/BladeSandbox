<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

final readonly class ClassNamespacePolicy
{
    /** @param list<string> $namespaces lower-cased, ending with "\" */
    public function __construct(private array $namespaces = [])
    {
    }

    public static function normalize(string $namespace): string
    {
        $namespace = strtolower(trim(str_replace('/', '\\', $namespace), '\\'));
        return $namespace === '' ? '' : $namespace.'\\';
    }

    public function contains(object|string $class): bool
    {
        if ($this->namespaces === []) {
            return false;
        }
        $name = strtolower(ltrim(is_object($class) ? $class::class : $class, '\\'));
        foreach ($this->namespaces as $namespace) {
            if (str_starts_with($name, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function describe(): array
    {
        $namespaces = $this->namespaces;
        sort($namespaces);
        return $namespaces;
    }
}
