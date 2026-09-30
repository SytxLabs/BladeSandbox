<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Support;

use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final class ClassMetadataCache
{
    /** @var array<class-string, ClassMetadata> */
    private static array $classes = [];

    /** @var array<string, list<array{name: string, type: list<string>|null, variadic: bool}>> */
    private static array $parameters = [];

    /**
     * @throws ReflectionException
     */
    public static function for(object|string $class): ClassMetadata
    {
        $name = is_object($class) ? $class::class : $class;

        return self::$classes[$name] ??= ClassMetadata::fromClass($name);
    }

    /**
     * Parameter type names of a method, `null` meaning "untyped".
     *
     * @throws ReflectionException
     *
     * @return list<array{name: string, type: list<string>|null, variadic: bool}>
     */
    public static function parameters(object|string $class, string $method): array
    {
        $name = (is_object($class) ? $class::class : $class).'::'.strtolower($method);

        return self::$parameters[$name] ?? (self::$parameters[$name] = self::describe((new ReflectionMethod($class, $method))->getParameters()));
    }

    /**
     * @param array<ReflectionParameter> $parameters
     *
     * @return list<array{name: string, type: list<string>|null, variadic: bool}>
     */
    public static function describe(array $parameters): array
    {
        $result = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $result[] = ['name' => $parameter->getName(), 'type' => $type !== null ? collect(($type instanceof ReflectionNamedType ? [$type] : ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : [])))->map(fn ($single) => $single instanceof ReflectionNamedType ? strtolower($single->getName()) : 'mixed')->all() : null, 'variadic' => $parameter->isVariadic()];
        }

        return $result;
    }

    public static function flush(): void
    {
        self::$classes = [];
        self::$parameters = [];
    }
}
