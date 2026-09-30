<?php

/*
 * Test fixture: the real-world BaseDTO supplied for this package, kept 1:1 except for one change:
 * the PHP 8.4-only syntax `new ReflectionX(...)->method()` is wrapped in parentheses
 * (`(new ReflectionX(...))->method()`) so the test-suite also parses on PHP 8.2 / 8.3.
 * Behaviour is identical.
 */

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO;

use ArrayAccess;
use ArrayIterator;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use IteratorAggregate;
use Livewire\Wireable;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use Serializable;
use stdClass;
use Stringable;
use Throwable;
use Traversable;

abstract readonly class BaseDTO implements Arrayable, ArrayAccess, IteratorAggregate, Serializable, Stringable, Wireable
{
    abstract public function __construct(array $data = []);

    /** @noinspection MissingReturnTypeInspection */
    public function __toString()
    {
        $array = $this->toArray();
        /** @noinspection MagicMethodsValidityInspection */
        return empty($array) ? '' : rescue(static fn () => Json::encode($array, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '', false);
    }

    public function __serialize(): array
    {
        return $this->toArray();
    }

    public function __unserialize(array $data): void
    {
        $this->setPropertiesFromArray($data);
    }

    public function __get(string $name): mixed
    {
        if (method_exists($this, $name)) {
            return $this->{$name}();
        }
        if (! property_exists($this, $name)) {
            @trigger_error('Undefined property: '.static::class.'::$'.$name, E_USER_WARNING);
            return null;
        }
        return $this->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        @trigger_error('Cannot set property '.static::class.'::$'.$name.' directly. Use the constructor or fromModel method instead.', E_USER_WARNING);
    }

    public function __isset(string $name): bool
    {
        if (method_exists($this, $name)) {
            return true; // Method exists, so we consider it "set"
        }
        if (!property_exists($this, $name)) {
            @trigger_error('Undefined property: '.static::class.'::$'.$name, E_USER_WARNING);
            return false;
        }
        return isset($this->{$name});
    }

    public function __debugInfo(): array
    {
        return collect(self::getMethods())
            ->filter(static fn ($method, $name) => ! in_array($name, self::exceptedMethods(), true))
            ->filter(static fn ($method) => collect($method['parameters'])->every(static fn ($param) => $param['optional']))
            ->mapWithKeys(function ($method, $name) {
                try {
                    $result = $this->{$name}();
                    return ['+' . $name . '()' => is_array($result) ? array_map(static fn ($item) => $item instanceof Proxy ? $item->getObject() : $item, $result) : $result];
                } catch (Throwable) {
                    @trigger_error('Failed to call method '.$name.' on '.static::class.'.', E_USER_WARNING);
                }
                return null;
            })->toArray();
    }

    protected function setPropertiesFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                try {
                    $isNullable = collect(explode('|', self::getTypes((new ReflectionProperty($this, $key))->getType())))->filter(static fn ($t) => $t === 'null' || $t === 'mixed')->isNotEmpty();
                    $primaryType = collect(explode('|', self::getTypes((new ReflectionProperty($this, $key))->getType())))->filter(static fn ($t) => $t !== 'null' && $t !== 'mixed')->first() ?? 'mixed';
                    $phpType = gettype($value);
                    if (!match (strtolower($primaryType)) {
                        'int', 'integer' => $phpType === 'integer',
                        'float', 'double' => $phpType === 'double',
                        'string' => $phpType === 'string',
                        'bool', 'boolean' => $phpType === 'boolean',
                        'array' => $phpType === 'array',
                        'object' => $phpType === 'object',
                        default => true
                    }) {
                        $value = match ($primaryType) {
                            'int', 'integer' => (int) $value,
                            'float', 'double' => (float) $value,
                            'string' => (string) $value,
                            'bool', 'boolean' => (bool) $value,
                            'array' => Arr::wrap($value),
                            default => $value,
                        };
                    }
                    if (str_contains($primaryType, 'Interface')) {
                        $primaryType = str_replace(['ModuleInterface\\', 'Interface'], '', $primaryType);
                    }
                    if (!($phpType instanceof $primaryType) && (class_exists($primaryType) || enum_exists($primaryType))) {
                        try {
                            if (enum_exists($primaryType)) {
                                if ($isNullable) {
                                    $value = method_exists($primaryType, 'tryFromWithModules') ? $primaryType::tryFromWithModules(enum_value($value)) : $primaryType::tryFrom(enum_value($value));
                                } else {
                                    $value = method_exists($primaryType, 'fromWithModules') ? $primaryType::fromWithModules(enum_value($value)) : $primaryType::from(enum_value($value));
                                }
                            } elseif (is_a($primaryType, Carbon::class)) {
                                $value = Carbon::parse($value);
                            }
                        } catch (Throwable $e) {
                            throw_if_debug($e);
                            @trigger_error('Failed to instantiate property '.$key.' of type '.$primaryType.' on '.static::class.'.', E_USER_WARNING);
                        }
                    }
                    $this->{$key} = $value;
                } catch (Throwable $e) {
                    throw_if_debug($e);
                    @trigger_error('Failed to set property '.$key.' on '.static::class.'.', E_USER_WARNING);
                    continue;
                }
            } else {
                @trigger_error('Undefined property: '.static::class.'::$'.$key, E_USER_WARNING);
            }
        }

        foreach (collect((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC))->filter(fn ($property) => ! $property->isInitialized($this)) as $property) {
            try {
                $types = collect(explode('|', self::getTypes($property->getType())))->filter(static fn ($type) => $type !== 'mixed');
                /** @var ReflectionProperty $property */
                if ($types->isEmpty() || $types->contains(static fn ($type) => $type === 'null')) {
                    $property->setValue($this, null);
                    continue;
                }
                $type = $types->first();
                $property->setValue($this, match (strtolower($type)) {
                    'int' => 0,
                    'float' => 0.0,
                    'string' => '',
                    'bool' => false,
                    'array' => [],
                    'object' => new stdClass(),
                    default => null,
                });
            } catch (Throwable $e) {
                throw_if_debug($e);
                @trigger_error('Failed to initialize property '.$property->getName().' on '.static::class.'.', E_USER_WARNING);
            }
        }
    }

    public static function fromModel(Model $model): static
    {
        $modelClass = static::getModelClass();
        if ($modelClass !== null && ! is_a($model, $modelClass, true)) {
            throw new InvalidArgumentException('Expected instance of '.($modelClass).', got '.get_class($model));
        }
        $dto = new static();
        foreach (get_object_vars($dto) as $property => $value) {
            if (property_exists($model, $property)) {
                try {
                    $modelValue = $model->{$property};
                    if ($modelValue instanceof Proxy) {
                        $modelValue = $modelValue->getObject();
                    }
                    $dto->{$property} = $modelValue;
                } catch (Throwable) {
                    @trigger_error('Failed to access property '.$property.' on model '.get_class($model).'.', E_USER_WARNING);
                }
            }
        }

        return $dto;
    }

    /** @return  class-string|null */
    public static function getModelClass(): ?string
    {
        return null;
    }

    public static function getName(): string
    {
        return __('The :name DTO', ['name' => class_basename(static::getModelClass() ?? static::class)]);
    }

    private static function exceptedMethods(): array
    {
        return ['__construct', 'fromModel', 'toArray', 'getIterator', '__toString', 'toLivewire', 'fromLivewire'];
    }

    public function toArray(): array
    {
        $array = array_map(static fn ($value) => $value instanceof Proxy ? $value->getObject() : $value, get_object_vars($this));
        collect(self::getMethods())->filter(static fn ($method, $name) => ! in_array($name, self::exceptedMethods(), true))
            ->filter(static fn ($method) => collect($method['parameters'])->every(static fn ($param) => $param['optional']))
            ->each(function ($method, $name) use (&$array) {
                try {
                    $result = $this->{$name}();
                    $array[$name] = is_array($result) ? array_map(static fn ($item) => $item instanceof Proxy ? $item->getObject() : $item, $result) : $result;
                } catch (Throwable) {
                    @trigger_error('Failed to call method '.$name.' on '.static::class.'.', E_USER_WARNING);
                }
            });
        return $array;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    public function toLivewire(): array
    {
        return $this->toArray();
    }

    /** @noinspection MissingParameterTypeDeclarationInspection */
    public static function fromLivewire($value): static
    {
        if (is_array($value)) {
            return new static($value);
        }
        throw new InvalidArgumentException('Expected an array for Livewire DTO conversion.');
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!property_exists($this, $offset)) {
            @trigger_error('Undefined property: '.static::class.'::$'.$offset, E_USER_WARNING);
            return false;
        }
        return isset($this->{$offset});
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!property_exists($this, $offset)) {
            @trigger_error('Undefined property: '.static::class.'::$'.$offset, E_USER_WARNING);
            return null;
        }
        return $this->{$offset};
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        @trigger_error('Cannot set property '.static::class.'::$'.$offset.' directly. Use the constructor or fromModel method instead.', E_USER_WARNING);
    }

    public function offsetUnset(mixed $offset): void
    {
        @trigger_error('Cannot unset property '.static::class.'::$'.$offset.'. Use the constructor or fromModel method instead.', E_USER_WARNING);
    }

    public function serialize(): ?string
    {
        return serialize($this->toArray());
    }

    public function unserialize(string $data): void
    {
        if (empty($data)) {
            @trigger_error('Cannot unserialize empty data.', E_USER_WARNING);
            return;
        }
        $array = unserialize($data, ['allowed_classes' => false]);
        if (! is_array($array)) {
            throw new InvalidArgumentException('Unserialized data is not an array.');
        }
        $this->__unserialize($array);
    }

    /** @noinspection NestedTernaryOperatorInspection */
    public static function getProperties(): array
    {
        return collect((new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC))->mapWithKeys(function (ReflectionProperty $property) {
            $tags = static::getTags($property->getDocComment());
            if (!isset($tags['var'])) {
                $tags['var'] = self::getTypes($property->getType());
            }
            return [$property->getName() => array_merge(['type' => $tags['var'], 'description' => isset($tags['translate']) ? trans($tags['translate']) : ($tags['description'] ?? null)], Arr::except($tags, ['var', 'translate', 'description']))];
        })->toArray();
    }

    /** @noinspection NestedTernaryOperatorInspection */
    public static function getMethods(): array
    {
        return collect((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method) => $method->class === static::class && $method->name !== '__construct' && $method->name !== 'getModelClass' && $method->name !== 'fromModel' && ! in_array($method->name, static::exceptedMethods(), true))
            ->mapWithKeys(fn (ReflectionMethod $method) => [$method->getName() => [
                'description' => value(fn (false|string $doc) => isset(($tags = static::getTags($doc))['translate']) ? trans($tags['translate']) : ($tags['description'] ?? null), $method->getDocComment()),
                'parameters' => collect($method->getParameters())->mapWithKeys(fn (ReflectionParameter $param) => [
                    $param->getName() => array_merge(['type' => static::getTypes($param->getType()), 'position' => $param->getPosition()], $param->isDefaultValueAvailable() ? ['default' => $param->getDefaultValue(), 'optional' => true] : ['optional' => false]),
                ])->toArray(),
                'return_type' => static::getTypes($method->getReturnType()),
            ]])->toArray();
    }

    private static function getTypes(ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType|null $method): string
    {
        $types = collect(match (true) {
            $method instanceof ReflectionNamedType => [$method, $method->allowsNull() ? 'null' : null],
            $method instanceof ReflectionUnionType => $method->getTypes(),
            $method instanceof ReflectionIntersectionType => $method->getTypes(),
            default => [],
        })->filter();
        return $types->isEmpty() ? 'mixed' : ($types->map(fn ($type) => is_string($type) ? $type : (method_exists($type, 'getName') ? $type->getName() : null))->filter()->implode('|'));
    }

    private static function getTags(false|string $doc): array
    {
        return ($doc && preg_match('/\*\*([\s\S]*?)\*\//', $doc, $matches)) ? collect(array_filter(array_map(static fn ($line) => preg_replace('/^\s*\*\s?/', '', $line), preg_split('/\r?\n/', $matches[1]))))
            ->map(static fn ($line) => preg_replace('/^\s*\*\s?/', '', $line))
            ->mapWithKeys(fn ($line) => match (true) {
                (bool) preg_match('/@(\w+)\s+(.*)/', $line, $tagMatch) => [$tagMatch[1] => trim($tagMatch[2])],
                (bool) preg_match('/@translate\((["\']?)(.*?)\1\)/', trim($line), $m) => ['translate' => $m[2]],
                !empty(trim($line)) => ['description' => trim($line)],
                default => [],
            })->filter(static fn ($value) => !empty($value))->toArray() : [];
    }
}
