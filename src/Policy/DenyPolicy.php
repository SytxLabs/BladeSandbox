<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use Illuminate\Support\Str;
use SytxLabs\BladeSandbox\Support\Patterns;

/**
 * Deny rules that take capabilities back out of broader grants (class namespaces, view namespaces,
 * helper sets, "*" rules, value-object defaults, DTOs, macros). A deny always wins over every allow.
 *
 * Class rules are inherited by subclasses and implementors. For class names that are not loaded yet
 * only the exact name (and the namespaces) are compared, so templates cannot trigger autoloading.
 */
final readonly class DenyPolicy
{
    /**
     * @param list<string> $classes lower-cased class / interface names
     * @param list<string> $namespaces lower-cased, ending with "\"
     * @param array<string, array<string, true>> $methods class => lower-cased method names ("*" = all)
     * @param array<string, array<string, true>> $properties class => property names ("*" = all)
     * @param array<string, array<string, true>> $classConstants class => constant names ("*" = all)
     * @param array<string, array<string, true>> $staticMethods class => lower-cased method names ("*" = all)
     * @param array<string, true> $functions lower-cased function names
     * @param array<string, true> $constants constant names
     * @param list<string> $views view patterns
     * @param list<string> $routes route name patterns
     * @param list<string> $translations translation key patterns
     */
    public function __construct(private array $classes = [], private array $namespaces = [], private array $methods = [], private array $properties = [], private array $classConstants = [], private array $staticMethods = [], private array $functions = [], private array $constants = [], private array $views = [], private array $routes = [], private array $translations = [])
    {
    }

    /** The whole class (or a parent / interface of it, or its namespace) is denied. */
    public function deniesClass(object|string $class): bool
    {
        if ($this->classes === [] && $this->namespaces === []) {
            return false;
        }
        $name = strtolower(ltrim(is_object($class) ? $class::class : $class, '\\'));
        foreach ($this->namespaces as $namespace) {
            if (str_starts_with($name, $namespace)) {
                return true;
            }
        }
        if ($this->classes === []) {
            return false;
        }
        return array_intersect($this->types($class), $this->classes) !== [];
    }

    public function deniesMethod(object|string $class, string $method): bool
    {
        return $this->deniesClass($class) || $this->deniesMember($this->methods, $class, strtolower($method));
    }

    public function deniesStaticMethod(string $class, string $method): bool
    {
        return $this->deniesMethod($class, $method) || $this->deniesMember($this->staticMethods, $class, strtolower($method));
    }

    public function deniesProperty(object|string $class, string $property): bool
    {
        return $this->deniesClass($class) || $this->deniesMember($this->properties, $class, $property);
    }

    public function deniesClassConstant(string $class, string $constant): bool
    {
        return $this->deniesClass($class) || $this->deniesMember($this->classConstants, $class, $constant);
    }

    public function deniesFunction(string $function): bool
    {
        return isset($this->functions[strtolower(ltrim($function, '\\'))]);
    }

    public function deniesConstant(string $constant): bool
    {
        return isset($this->constants[ltrim($constant, '\\')]);
    }

    public function deniesRoute(string $route): bool
    {
        return $this->routes !== [] && Str::is($this->routes, $route);
    }

    public function deniesTranslation(string $key): bool
    {
        return $this->translations !== [] && Str::is($this->translations, $key);
    }

    public function deniesView(string $view): bool
    {
        return $this->views !== [] && Patterns::matchesAny($this->views, $view);
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $sortedList = static function (array $list): array {
            $list = array_values(array_unique($list));
            sort($list);
            return $list;
        };
        $sortedRules = static function (array $rules): array {
            ksort($rules);

            return array_map(static function (array $names): array {
                $names = array_keys($names);
                sort($names);
                return $names;
            }, $rules);
        };
        $functions = array_keys($this->functions);
        $constants = array_keys($this->constants);

        return [
            'classes' => $sortedList($this->classes),
            'namespaces' => $sortedList($this->namespaces),
            'methods' => $sortedRules($this->methods),
            'properties' => $sortedRules($this->properties),
            'classConstants' => $sortedRules($this->classConstants),
            'staticMethods' => $sortedRules($this->staticMethods),
            'functions' => $sortedList($functions),
            'constants' => $sortedList($constants),
            'views' => $sortedList($this->views),
            'routes' => $sortedList($this->routes),
            'translations' => $sortedList($this->translations),
        ];
    }

    /** @param array<string, array<string, true>> $rules */
    private function deniesMember(array $rules, object|string $class, string $member): bool
    {
        if ($rules === []) {
            return false;
        }

        foreach ($this->types($class) as $type) {
            if (isset($rules[$type]) && (isset($rules[$type][$member]) || isset($rules[$type]['*']))) {
                return true;
            }
        }

        return false;
    }

    private function types(object|string $class): array
    {
        $name = is_object($class) ? $class::class : ltrim($class, '\\');
        return is_object($class) || class_exists($name, false) || interface_exists($name, false) || enum_exists($name, false) ? ObjectAccessPolicy::typesOf($class) : [strtolower($name)];
    }
}
