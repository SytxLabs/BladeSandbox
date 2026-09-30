<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

final readonly class FunctionPolicy
{
    /**
     * @param array<string, true> $functions lower-cased, no leading backslash
     * @param array<string, true> $constants exact names, no leading backslash
     * @param array<string, array<string, true>> $classConstants lower-cased class => constant names ("*" = all)
     * @param array<string, array<string, true>> $staticMethods lower-cased class => lower-cased method names ("*" = all)
     */
    public function __construct(private array $functions = [], private array $constants = [], private array $classConstants = [], private array $staticMethods = [])
    {
    }

    public function allowsStaticMethod(string $class, string $method): bool
    {
        $method = strtolower($method);
        if (str_starts_with($method, '__')) {
            return false;
        }
        $rules = $this->staticMethods[strtolower(ltrim($class, '\\'))] ?? null;

        return $rules !== null && (isset($rules[$method]) || isset($rules['*']));
    }

    public function allowsFunction(string $name): bool
    {
        return isset($this->functions[strtolower(ltrim($name, '\\'))]);
    }

    public function allowsConstant(string $name): bool
    {
        return isset($this->constants[ltrim($name, '\\')]);
    }

    public function allowsClassConstant(string $class, string $constant): bool
    {
        $rules = $this->classConstants[strtolower(ltrim($class, '\\'))] ?? null;

        return $rules !== null && (isset($rules[$constant]) || isset($rules['*']));
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $functions = array_keys($this->functions);
        $constants = array_keys($this->constants);
        sort($functions);
        sort($constants);
        $classConstants = array_map(static function (array $names): array {
            $names = array_keys($names);
            sort($names);
            return $names;
        }, $this->classConstants);
        ksort($classConstants);
        $staticMethods = array_map(static function (array $names): array {
            $names = array_keys($names);
            sort($names);
            return $names;
        }, $this->staticMethods);
        ksort($staticMethods);

        return ['functions' => $functions, 'constants' => $constants, 'classConstants' => $classConstants, 'staticMethods' => $staticMethods];
    }
}
