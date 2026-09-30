<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use SytxLabs\BladeSandbox\Support\ClassMetadataCache;

final class DtoGuard extends Guard
{
    public const DENIED_METHODS = ['serialize', 'unserialize', 'tolivewire', 'fromlivewire', 'offsetset', 'offsetunset', 'offsetget', 'offsetexists', 'setpropertiesfromarray', 'frommodel', 'fill', 'setattribute', 'setattributes'];
    public const CONVERSION_METHODS = ['toarray', 'getiterator', 'tojson', 'jsonserialize'];

    public function allows(object $object): bool
    {
        return $this->policy->allowsDto($object);
    }

    /** @return 'method'|'property'|null How `$dto->name` is resolved, or null when it is not reachable. */
    public function readable(object $dto, string $name): ?string
    {
        $metadata = ClassMetadataCache::for($dto);
        if ($metadata->hasPublicProperty($name)) {
            return 'property';
        }
        $method = $metadata->publicMethod($name);
        if ($method !== null && $method['required'] === 0 && $this->callable($dto, $name)) {
            return 'method';
        }
        return null;
    }

    public function hasProperty(object $dto, string $name): bool
    {
        return ClassMetadataCache::for($dto)->hasPublicProperty($name);
    }

    public function callable(object $dto, string $method): bool
    {
        $info = ClassMetadataCache::for($dto)->publicMethod($method);
        if ($info === null || $info['static']) {
            return false;
        }
        $lower = strtolower($method);
        if (str_starts_with($lower, '__') || in_array($lower, self::DENIED_METHODS, true)) {
            return false;
        }
        if (in_array($lower, self::CONVERSION_METHODS, true)) {
            return $this->policy->usesNativeDtoConversion();
        }
        return true;
    }

    public function visibleProperties(object $dto): array
    {
        $values = [];
        foreach (array_keys(ClassMetadataCache::for($dto)->publicProperties) as $property) {
            $reflection = ClassMetadataCache::for($dto)->reflectProperty($property);
            if ($reflection->isInitialized($dto)) {
                $values[$property] = $reflection->getValue($dto);
            }
        }
        return $values;
    }
}
