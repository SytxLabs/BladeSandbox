<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Runtime\LoopState;
use SytxLabs\BladeSandbox\Support\ClassMetadataCache;

final class PropertyGuard extends Guard
{
    public function __construct(SandboxPolicy $policy, AuditLogger $audit, private readonly DtoGuard $dtos)
    {
        parent::__construct($policy, $audit);
    }

    /** @param bool $quiet inside isset()/empty()/??: missing links resolve to null */
    public function get(mixed $object, string $property, bool $quiet = false): mixed
    {
        if ($object === null) {
            if ($quiet) {
                return null;
            }
            throw new SandboxException('Attempt to read property "'.$property.'" on null.');
        }

        if (is_array($object) && $quiet) {
            return null;
        }

        if (!is_object($object)) {
            throw new SandboxException('Attempt to read property "'.$property.'" on '.get_debug_type($object).'.');
        }

        if ($object instanceof LoopState) {
            if (ClassMetadataCache::for($object)->hasPublicProperty($property)) {
                return $object->{$property};
            }
            throw $this->deny(ForbiddenPropertyException::for('$loop->'.$property));
        }

        $subject = $object::class.'::$'.$property;

        if ($this->dtos->allows($object)) {
            $kind = $this->dtos->readable($object, $property);
            if ($kind === 'property') {
                return ClassMetadataCache::for($object)->reflectProperty($property)->isInitialized($object) ? $object->{$property} : null;
            }
            if ($kind === 'method') {
                return $object->{$property}();
            }
            if ($quiet && !$this->policy->allowsProperty($object, $property)) {
                return null;
            }
            if (!$this->policy->allowsProperty($object, $property)) {
                throw $this->deny(ForbiddenPropertyException::for($subject, 'not part of the DTO\'s public API'));
            }
        }

        $metadata = ClassMetadataCache::for($object);
        if ($metadata->declaresNonPublicProperty($property) && !$metadata->hasPublicProperty($property)) {
            throw $this->deny(ForbiddenPropertyException::for($subject));
        }
        if (!$this->policy->allowsProperty($object, $property)) {
            throw $this->deny(ForbiddenPropertyException::for($subject));
        }
        if ($quiet) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            return $object->{$property} ?? null;
        }
        return $object->{$property};
    }
}
