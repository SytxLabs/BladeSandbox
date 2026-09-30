<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Support;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

final class Macros
{
    public static function has(object|string $class, string $name): bool
    {
        $class = is_object($class) ? $class::class : ltrim($class, '\\');
        if (! method_exists($class, 'hasMacro')) {
            return false;
        }

        $method = new ReflectionMethod($class, 'hasMacro');
        if (! $method->isPublic() || ! $method->isStatic()) {
            return false;
        }
        try {
            return (bool) $class::hasMacro($name);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array{name: string, type: list<string>|null, variadic: bool}>|null */
    public static function parameters(object|string $class, string $name): ?array
    {
        $callable = self::callable(is_object($class) ? $class::class : ltrim($class, '\\'), $name);
        if ($callable === null) {
            return null;
        }
        try {
            return ClassMetadataCache::describe((new ReflectionFunction(Closure::fromCallable($callable)))->getParameters());
        } catch (Throwable) {
            return null;
        }
    }

    private static function callable(string $class, string $name): ?callable
    {
        foreach ([$class, ...array_values(class_parents($class) ?: [])] as $type) {
            if (!property_exists($type, 'macros')) {
                continue;
            }
            $property = new ReflectionProperty($type, 'macros');
            if (!$property->isStatic()) {
                return null;
            }
            $macros = $property->getValue();
            if (!is_array($macros)) {
                return null;
            }
            foreach ($macros as $macro => $callable) {
                if (is_callable($callable) && strcasecmp((string) $macro, $name) === 0) {
                    return $callable;
                }
            }
            return null;
        }
        return null;
    }
}
