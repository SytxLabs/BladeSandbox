<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use SytxLabs\BladeSandbox\Contracts\ObjectPolicy;

final readonly class ObjectAccessPolicy implements ObjectPolicy
{
    /**
     * @param array<string, array<string, true>> $methods class => lower-cased method names ("*" = all public methods)
     * @param array<string, array<string, true>> $properties class => property names ("*" = all)
     * @param list<string> $arrayAccess
     * @param list<string> $iteration
     * @param list<string> $stringConversion
     * @param list<string> $htmlable
     * @param array<string, array<string, true>> $macros class => lower-cased macro names ("*" = every registered macro)
     */
    public function __construct(private array $methods = [], private array $properties = [], private array $arrayAccess = [], private array $iteration = [], private array $stringConversion = [], private array $htmlable = [], private array $macros = [])
    {
    }

    public function allowsMethod(object|string $class, string $method): bool
    {
        $method = strtolower($method);
        if (str_starts_with($method, '__')) {
            return false;
        }
        foreach (self::typesOf($class) as $type) {
            $rules = $this->methods[$type] ?? null;
            if ($rules !== null && (isset($rules[$method]) || isset($rules['*']))) {
                return true;
            }
        }

        return false;
    }

    public function allowsMethodExplicitly(object|string $class, string $method): bool
    {
        $method = strtolower($method);
        if (str_starts_with($method, '__')) {
            return false;
        }

        foreach (self::typesOf($class) as $type) {
            if (isset($this->methods[$type][$method])) {
                return true;
            }
        }

        return false;
    }

    public function allowsMacro(object|string $class, string $macro): bool
    {
        $macro = strtolower($macro);
        if ($this->macros === [] || str_starts_with($macro, '__')) {
            return false;
        }

        $name = is_object($class) ? $class::class : ltrim($class, '\\');
        $types = is_object($class) || class_exists($name, false) || interface_exists($name, false) ? self::typesOf($class) : [strtolower($name)];
        foreach ($types as $type) {
            $rules = $this->macros[$type] ?? null;
            if ($rules !== null && (isset($rules[$macro]) || isset($rules['*']))) {
                return true;
            }
        }
        return false;
    }

    public function allowsProperty(object|string $class, string $property): bool
    {
        foreach (self::typesOf($class) as $type) {
            $rules = $this->properties[$type] ?? null;
            if ($rules !== null && (isset($rules[$property]) || isset($rules['*']))) {
                return true;
            }
        }
        return false;
    }

    public function allowsArrayAccess(object $object): bool
    {
        return self::isAny($object, $this->arrayAccess);
    }

    public function allowsIteration(object $object): bool
    {
        return self::isAny($object, $this->iteration);
    }

    public function allowsStringConversion(object $object): bool
    {
        return self::isAny($object, $this->stringConversion);
    }

    public function allowsHtmlable(object $object): bool
    {
        return self::isAny($object, $this->htmlable);
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'methods' => self::sorted($this->methods),
            'properties' => self::sorted($this->properties),
            'arrayAccess' => self::sortedList($this->arrayAccess),
            'iteration' => self::sortedList($this->iteration),
            'string' => self::sortedList($this->stringConversion),
            'htmlable' => self::sortedList($this->htmlable),
            'macros' => self::sorted($this->macros),
        ];
    }

    /** @return list<string> */
    public static function typesOf(object|string $class): array
    {
        static $cache = [];

        $name = is_object($class) ? $class::class : ltrim($class, '\\');
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return $cache[$name] = [strtolower($name)];
        }

        $types = [strtolower($name)];
        foreach (class_parents($name) ?: [] as $parent) {
            $types[] = strtolower($parent);
        }
        foreach (class_implements($name) ?: [] as $interface) {
            $types[] = strtolower($interface);
        }

        return $cache[$name] = $types;
    }

    /** @param list<string> $classes */
    private static function isAny(object $object, array $classes): bool
    {
        if ($classes === []) {
            return false;
        }

        $types = array_flip(self::typesOf($object));
        foreach ($classes as $class) {
            if (isset($types[$class])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, true>> $rules
     *
     * @return array<string, list<string>>
     */
    private static function sorted(array $rules): array
    {
        ksort($rules);

        return array_map(static function (array $names): array {
            $names = array_keys($names);
            sort($names);

            return $names;
        }, $rules);
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    private static function sortedList(array $list): array
    {
        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }
}
