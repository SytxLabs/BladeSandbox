<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use ReflectionFunction;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenRouteException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenTranslationException;
use SytxLabs\BladeSandbox\Support\ClassMetadataCache;

final class FunctionGuard extends Guard
{
    public function __construct(SandboxPolicy $policy, AuditLogger $audit, private readonly ArgumentGuard $arguments)
    {
        parent::__construct($policy, $audit);
    }

    /** @param array<int|string, mixed> $arguments */
    public function call(string $function, array $arguments): mixed
    {
        $function = ltrim($function, '\\');
        if (!function_exists($function) || !$this->policy->allowsFunction($function)) {
            throw $this->deny(ForbiddenFunctionException::for($function.'()'));
        }
        $reflection = new ReflectionFunction($function);
        $this->arguments->forTemplate($this->template)->check($function.'()', $arguments, ClassMetadataCache::describe($reflection->getParameters()), false, true);
        return $function(...$arguments);
    }

    /** Checks a translation key against the translation policy (__(), trans(), trans_choice(), @lang, @choice). */
    public function assertTranslation(string $key, string $via): void
    {
        if (!$this->policy->allowsTranslation($key)) {
            throw $this->deny(ForbiddenTranslationException::for($key, 'via '.$via));
        }
    }

    /** Checks a route name against the route policy (route()). */
    public function assertRoute(string $name): void
    {
        if (!$this->policy->allowsRoute($name)) {
            throw $this->deny(ForbiddenRouteException::for($name));
        }
    }

    public function constant(string $name): mixed
    {
        if (!defined($name) || !$this->policy->allowsConstant($name)) {
            throw $this->deny(ForbiddenPropertyException::for('constant '.$name));
        }
        return constant($name);
    }

    public function classConstant(string $class, string $name): mixed
    {
        if (!$this->policy->allowsClassConstant($class, $name) || !defined($class.'::'.$name)) {
            throw $this->deny(ForbiddenPropertyException::for($class.'::'.$name));
        }
        return constant($class.'::'.$name);
    }
}
