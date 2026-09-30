<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * Reflection facts about one class that the guards need. Instances are memoised per class by
 * {@see ClassMetadataCache}; they never contain runtime values, only structure.
 */
final readonly class ClassMetadata
{
    /**
     * @param array<string, true> $publicProperties public, non-static property names (case-sensitive)
     * @param array<string, true> $nonPublicProperties private/protected/static property names
     * @param array<string, array{name: string, required: int, static: bool}> $publicMethods lower-cased name => info
     * @param array<string, true> $nonPublicMethods lower-cased private/protected method names
     */
    public function __construct(public string $class, public array $publicProperties, public array $nonPublicProperties, public array $publicMethods, public array $nonPublicMethods)
    {
    }

    /** @param  class-string  $class
     * @throws ReflectionException
     */
    public static function fromClass(string $class): self
    {
        $reflection = new ReflectionClass($class);

        $public = [];
        $nonPublic = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $public[$property->getName()] = true;
            } else {
                $nonPublic[$property->getName()] = true;
            }
        }

        $methods = [];
        $nonPublicMethods = [];
        foreach ($reflection->getMethods() as $method) {
            $key = strtolower($method->getName());
            if ($method->isPublic()) {
                $methods[$key] = ['name' => $method->getName(), 'required' => $method->getNumberOfRequiredParameters(), 'static' => $method->isStatic()];
            } else {
                $nonPublicMethods[$key] = true;
            }
        }

        return new self($reflection->getName(), $public, $nonPublic, $methods, $nonPublicMethods);
    }

    public function hasPublicProperty(string $name): bool
    {
        return isset($this->publicProperties[$name]);
    }

    public function declaresNonPublicProperty(string $name): bool
    {
        return isset($this->nonPublicProperties[$name]);
    }

    /** @return array{name: string, required: int, static: bool}|null */
    public function publicMethod(string $name): ?array
    {
        return $this->publicMethods[strtolower($name)] ?? null;
    }

    public function declaresNonPublicMethod(string $name): bool
    {
        return isset($this->nonPublicMethods[strtolower($name)]);
    }

    /**
     * @throws ReflectionException
     */
    public function reflectProperty(string $name): ReflectionProperty
    {
        return new ReflectionProperty($this->class, $name);
    }
}
