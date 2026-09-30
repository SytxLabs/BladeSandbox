<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use Closure;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;

/** Prevents template-supplied arguments from being executed as callables by the called code (e.g. `$collection->filter('system')` or `->contains(['Artisan', 'call'])`). */
final class ArgumentGuard extends Guard
{
    /** Callable type names that turn a string argument into code execution. */
    private const CALLABLE_TYPES = ['callable', 'closure', 'mixed'];

    /**
     * @param array<int|string, mixed> $arguments positional and/or named arguments
     * @param list<array{name: string, type: list<string>|null, variadic: bool}>|null $parameters null = unknown signature (__call)
     * @param bool $untypedStringsAreData the callee is known to never call untyped string arguments (Laravel's useAsCallable())
     */
    public function check(string $subject, array $arguments, ?array $parameters, bool $untypedStringsAreData = false, bool $function = false): void
    {
        $position = 0;
        foreach ($arguments as $key => $argument) {
            $unknown = $parameters === null;
            $parameter = null;

            if (!$unknown) {
                $parameter = is_string($key) ? self::byName($parameters, $key) : self::byPosition($parameters, $position);
                $unknown = $parameter === null;
            }
            $this->checkValue($subject, $argument, $parameter['type'] ?? null, $unknown, $untypedStringsAreData, 0, $function);
            $position++;
        }
    }

    /**
     * @param list<array{name: string, type: list<string>|null, variadic: bool}> $parameters
     *
     * @return array{name: string, type: list<string>|null, variadic: bool}|null
     */
    private static function byPosition(array $parameters, int $position): ?array
    {
        if (isset($parameters[$position])) {
            return $parameters[$position];
        }
        $last = $parameters[count($parameters) - 1] ?? null;
        return $last !== null && $last['variadic'] ? $last : null;
    }

    /**
     * @param list<array{name: string, type: list<string>|null, variadic: bool}> $parameters
     *
     * @return array{name: string, type: list<string>|null, variadic: bool}|null
     */
    private static function byName(array $parameters, string $name): ?array
    {
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === $name && !$parameter['variadic']) {
                return $parameter;
            }
        }
        return null;
    }

    /** @param list<string>|null $types */
    private function checkValue(string $subject, mixed $value, ?array $types, bool $unknownSignature, bool $untypedStringsAreData, int $depth, bool $function): void
    {
        if ($value instanceof Closure || (is_object($value) && method_exists($value, '__invoke'))) {
            throw $this->deny($this->violation($subject, 'callable arguments', $function));
        }
        if (is_array($value)) {
            if (self::looksCallable($value)) {
                throw $this->deny($this->violation($subject, 'callable arguments', $function));
            }
            if ($depth < 3) {
                foreach ($value as $item) {
                    $this->checkValue($subject, $item, ['array'], false, $untypedStringsAreData, $depth + 1, $function);
                }
            }
            return;
        }
        if (is_string($value) && $depth === 0 && self::looksCallable($value)) {
            if ($unknownSignature || ($types === null && !$untypedStringsAreData) || ($types !== null && array_intersect($types, self::CALLABLE_TYPES) !== [])) {
                throw $this->deny($this->violation($subject, 'callable string arguments', $function));
            }
        }
    }

    /** Callable detection without autoloading: is_callable() would autoload any class named in a template string. "Class::method" strings and [class, method] pairs are treated as callables. */
    private static function looksCallable(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, '::') || function_exists($value);
        }
        if (is_array($value) && count($value) === 2 && array_is_list($value)) {
            return (is_string($value[0]) || is_object($value[0])) && is_string($value[1]);
        }
        return false;
    }

    private function violation(string $subject, string $reason, bool $function): ForbiddenFunctionException|ForbiddenMethodException
    {
        return $function ? ForbiddenFunctionException::for($subject, $reason) : ForbiddenMethodException::for($subject, $reason);
    }
}
