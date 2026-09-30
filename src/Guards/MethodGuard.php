<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Facade;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDtoException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Support\ClassMetadataCache;
use SytxLabs\BladeSandbox\Support\Macros;

final class MethodGuard extends Guard
{
    public function __construct(SandboxPolicy $policy, AuditLogger $audit, private readonly DtoGuard $dtos, private readonly ArgumentGuard $arguments)
    {
        parent::__construct($policy, $audit);
    }

    /** @param array<int|string, mixed> $arguments */
    public function call(mixed $object, string $method, array $arguments): mixed
    {
        if (! is_object($object)) {
            throw new SandboxException('Call to a member function '.$method.'() on '.get_debug_type($object).'.');
        }

        $subject = $object::class.'::'.$method.'()';
        $metadata = ClassMetadataCache::for($object);
        $declared = $metadata->publicMethod($method);

        if ($this->dtos->allows($object)) {
            if ($this->dtos->callable($object, $method)) {
                return $this->invoke($object, $method, $arguments, $subject, $declared !== null);
            }
            if (!$this->policy->usesNativeDtoConversion() && in_array(strtolower($method), DtoGuard::CONVERSION_METHODS, true)) {
                throw $this->deny(ForbiddenDtoException::for($subject, 'native DTO conversion is disabled'));
            }
            if (!$this->policy->allowsMethod($object, $method)) {
                throw $this->deny(ForbiddenMethodException::for($subject, 'not part of the DTO\'s public API'));
            }
        }

        if ($declared === null && $metadata->declaresNonPublicMethod($method)) {
            throw $this->deny(ForbiddenMethodException::for($subject));
        }
        if ($declared === null) {
            return $this->callUndeclared($object, $method, $arguments, $subject);
        }
        if (!$this->policy->allowsMethod($object, $method)) {
            throw $this->deny(ForbiddenMethodException::for($subject));
        }
        return $this->invoke($object, $method, $arguments, $subject, true);
    }

    private function callUndeclared(object $object, string $method, array $arguments, string $subject): mixed
    {
        if (Macros::has($object, $method) && $this->policy->allowsMacro($object, $method)) {
            $this->arguments->forTemplate($this->template)->check($subject, $arguments, Macros::parameters($object, $method));
            return $object->{$method}(...$arguments);
        }

        if (!$this->policy->allowsMethodExplicitly($object, $method)) {
            throw $this->deny(ForbiddenMethodException::for($subject, Macros::has($object, $method) ? 'macro not allowed' : 'not a declared public method'));
        }
        return $this->invoke($object, $method, $arguments, $subject, false);
    }

    public function callStatic(string $class, string $method, array $arguments): mixed
    {
        $class = ltrim($class, '\\');
        $subject = $class.'::'.$method.'()';

        $static = $this->policy->allowsStaticMethod($class, $method);
        if (!$static && !$this->policy->allowsMacro($class, $method)) {
            throw $this->deny(ForbiddenMethodException::for($subject));
        }
        if (!class_exists($class) && !enum_exists($class)) {
            throw $this->deny(ForbiddenMethodException::for($subject, 'unknown class'));
        }

        if (is_subclass_of($class, Facade::class)) {
            throw $this->deny(ForbiddenMethodException::for($subject, 'facades are never available in the sandbox'));
        }

        $info = ClassMetadataCache::for($class)->publicMethod($method);
        if ($info === null && Macros::has($class, $method) && !ClassMetadataCache::for($class)->declaresNonPublicMethod($method)) {
            if (!$this->policy->allowsMacro($class, $method)) {
                throw $this->deny(ForbiddenMethodException::for($subject, 'macro not allowed'));
            }
            $this->arguments->forTemplate($this->template)->check($subject, $arguments, Macros::parameters($class, $method));
            return $class::{$method}(...$arguments);
        }

        if ($info === null || ! $info['static'] || !$static) {
            throw $this->deny(ForbiddenMethodException::for($subject, 'not a public static method'));
        }
        $this->arguments->forTemplate($this->template)->check($subject, $arguments, ClassMetadataCache::parameters($class, $method));
        return $class::{$info['name']}(...$arguments);
    }

    /** @param array<int|string, mixed> $arguments */
    private function invoke(object $object, string $method, array $arguments, string $subject, bool $declared): mixed
    {
        $this->arguments->forTemplate($this->template)->check($subject, $arguments, $declared ? ClassMetadataCache::parameters($object, $method) : null, $object instanceof Enumerable);
        return $object->{$method}(...$arguments);
    }
}
