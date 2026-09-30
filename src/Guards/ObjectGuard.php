<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use ArrayAccess;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\View\ComponentAttributeBag;
use stdClass;
use Stringable;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use Traversable;
use UnitEnum;

/** Mediates the implicit object behaviours PHP triggers without an explicit method call: ArrayAccess, iteration, string conversion, Htmlable output, casts and destructuring. */
final class ObjectGuard extends Guard
{
    public function __construct(SandboxPolicy $policy, AuditLogger $audit, private readonly DtoGuard $dtos)
    {
        parent::__construct($policy, $audit);
    }

    public function offset(mixed $container, mixed $key, bool $quiet = false): mixed
    {
        if (is_array($container) || is_string($container)) {
            return $quiet ? ($container[$key] ?? null) : $container[$key];
        }

        if ($container === null || is_scalar($container)) {
            return null;
        }

        if (!is_object($container)) {
            throw new SandboxException('Cannot use a value of type '.get_debug_type($container).' as an array.');
        }

        if ($container instanceof ArrayAccess && $this->dtos->allows($container)) {
            if ((is_string($key) || is_int($key)) && $this->dtos->hasProperty($container, (string) $key)) {
                return $container[$key];
            }
            if ($quiet) {
                return null;
            }

            throw $this->deny(ForbiddenPropertyException::for($container::class.'['.(is_scalar($key) ? (string) $key : get_debug_type($key)).']', 'not a public DTO property'));
        }

        if ($container instanceof ArrayAccess && $this->policy->allowsArrayAccess($container)) {
            if ($quiet) {
                return $container->offsetExists($key) ? $container[$key] : null;
            }

            return $container[$key];
        }
        throw $this->deny(ForbiddenMethodException::for($container::class.'::offsetGet()', 'array access'));
    }

    /** @return iterable<mixed, mixed> */
    public function iterate(mixed $value, bool $destructure = false): iterable
    {
        $iterable = $this->iterable($value);

        return $destructure ? $this->onlyArrays($iterable) : $iterable;
    }

    /** @return iterable<mixed, mixed> */
    private function iterable(mixed $value): iterable
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return [];
        }
        if (!is_object($value)) {
            throw new SandboxException('foreach() argument must be of type array|object, '.get_debug_type($value).' given.');
        }
        if ($this->dtos->allows($value)) {
            return ($value instanceof Traversable && $this->policy->usesNativeDtoConversion()) ? $value : $this->dtos->visibleProperties($value);
        }
        if ($value instanceof Traversable && $this->policy->allowsIteration($value)) {
            return $value;
        }
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        throw $this->deny(ForbiddenMethodException::for($value::class.'::getIterator()', 'iteration'));
    }

    /**
     * @param iterable<mixed, mixed> $iterable
     *
     * @return Generator<mixed, array>
     */
    private function onlyArrays(iterable $iterable): Generator
    {
        foreach ($iterable as $key => $item) {
            yield $key => $this->destructure($item);
        }
    }

    public function toString(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            throw new SandboxException('Array to string conversion.');
        }
        if (!is_object($value)) {
            throw new SandboxException('Cannot convert '.get_debug_type($value).' to string.');
        }

        if ($this->dtos->allows($value)) {
            if ($value instanceof Stringable && $this->policy->usesNativeDtoConversion()) {
                return (string) $value;
            }
            $properties = $this->dtos->visibleProperties($value);
            foreach ($properties as $property => $item) {
                if (is_object($item) || is_resource($item)) {
                    unset($properties[$property]);
                }
            }

            return $properties === [] ? '' : (string) json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if ($value instanceof Stringable && $this->policy->allowsStringConversion($value)) {
            if ($value instanceof ComponentAttributeBag) {
                $this->assertAttributeValues($value, false);
            }

            return (string) $value;
        }

        throw $this->deny(ForbiddenMethodException::for($value::class.'::__toString()', 'string conversion'));
    }

    /** Escaped echo in text context: {{ $value }} (allowed Htmlable values render their markup). */
    public function escape(mixed $value): string
    {
        return ($value instanceof Htmlable && $this->policy->allowsHtmlable($value)) ? $this->toHtml($value) : $this->escapeText($value);
    }

    /** Escaped output that never renders markup (attribute values, comments, raw text elements). */
    public function escapeText(mixed $value): string
    {
        if ($value instanceof Htmlable && $this->policy->allowsHtmlable($value)) {
            return htmlspecialchars($this->toHtml($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        return htmlspecialchars($this->toString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    /** Raw echo: {!! $value !!} (only when the policy allows raw output). */
    public function raw(mixed $value): string
    {
        return ($value instanceof Htmlable && $this->policy->allowsHtmlable($value)) ? $this->toHtml($value) : $this->toString($value);
    }

    private function toHtml(Htmlable $value): string
    {
        if ($value instanceof ComponentAttributeBag) {
            $this->assertAttributeValues($value, false);
        }
        return $value->toHtml();
    }

    /** Renders an attribute bag in attribute position (`<div {{ $attributes }}>`). */
    public function attributeBag(ComponentAttributeBag $bag): string
    {
        $this->assertAttributeValues($bag, true);
        return $bag->toHtml();
    }

    /**
     * An attribute bag renders `key="value"` pairs without HTML-escaping (it only replaces `"` by `\"`,
     * which does not end an HTML attribute value safely) and converts objects with e(), which calls
     * __toString() or renders Htmlable objects raw. Keys and values are therefore validated here.
     */
    private function assertAttributeValues(ComponentAttributeBag $bag, bool $inTag): void
    {
        foreach ($bag->getAttributes() as $key => $attribute) {
            if (preg_match('/\A[^\s"\'<>\/=]+\z/', (string) $key) !== 1) {
                throw $this->deny(new SecurityViolationException('Invalid attribute name in attribute bag.', 'attribute', 'attribute bag'));
            }
            if (is_object($attribute)) {
                if ($attribute instanceof Htmlable) {
                    if (!$this->policy->allowsHtmlable($attribute)) {
                        throw $this->deny(ForbiddenMethodException::for($attribute::class.'::toHtml()', 'attribute value'));
                    }
                    $attribute = $attribute->toHtml();
                } else {
                    $attribute = $this->toString($attribute);
                }
            }
            if (is_string($attribute) && (str_contains($attribute, '"') || (!$inTag && (str_contains($attribute, '<') || str_contains($attribute, '>'))))) {
                throw $this->deny(new SecurityViolationException('Attribute bag values must be escaped (use bound attributes or {{ }} instead of merge(..., false)).', 'attribute', (string) $key));
            }
        }
    }

    public function assertLooselyComparable(mixed $value, int $depth = 0): void
    {
        if (is_array($value)) {
            if ($depth > 32) {
                throw new SandboxException('Value is nested too deeply.');
            }
            foreach ($value as $item) {
                $this->assertLooselyComparable($item, $depth + 1);
            }

            return;
        }

        if (is_object($value) && !$value instanceof UnitEnum && !$value instanceof DateTimeInterface) {
            $this->toString($value);
        }
    }

    public function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || is_scalar($value)) {
            return (array) $value;
        }
        if (is_object($value) && $this->dtos->allows($value)) {
            return $this->dtos->visibleProperties($value);
        }
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        throw $this->deny(ForbiddenMethodException::for(self::describeType($value).' (array) cast'));
    }

    public function destructure(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            throw $this->deny(ForbiddenMethodException::for($value::class.'::offsetGet()', 'destructuring'));
        }
        throw new SandboxException('Only arrays can be destructured in a sandbox, '.get_debug_type($value).' given.');
    }

    public function spread(mixed $value, ?Closure $tick = null): array
    {
        $result = [];
        foreach ($this->iterate($value) as $key => $item) {
            if ($tick !== null && !is_array($value)) {
                $tick();
            }
            if (is_int($key)) {
                $result[] = $item;
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    public function compare(string $operator, mixed $left, mixed $right): bool|int
    {
        if (!(is_object($left) && is_object($right))) {
            $this->assertLooselyComparable($left);
            $this->assertLooselyComparable($right);
        }

        /** @noinspection TypeUnsafeComparisonInspection */
        return match ($operator) {
            '==' => $left == $right,
            '!=' => $left != $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<=>' => $left <=> $right,
            default => throw new SandboxException('Unknown comparison operator.'),
        };
    }
}
