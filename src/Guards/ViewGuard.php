<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use Closure;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewNamespaceException;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;
use SytxLabs\BladeSandbox\Support\Patterns;

final class ViewGuard extends Guard
{
    /** @var (Closure(ForbiddenViewException): void)|null learning mode: record denied views and render them anyway */
    private ?Closure $learning = null;

    /** @param (Closure(ForbiddenViewException): void)|null $record */
    public function learnInto(?Closure $record): void
    {
        $this->learning = $record;
    }

    public function assertView(mixed $view): string
    {
        if (!is_string($view) || !Patterns::isValidName($view)) {
            throw $this->deny(ForbiddenViewException::for(is_string($view) ? self::safeName($view) : get_debug_type($view), 'invalid view name'));
        }

        if ($this->policy->allowsView($view)) {
            return $view;
        }

        if ($this->learning !== null) {
            ($this->learning)(ForbiddenViewException::for($view));

            return $view;
        }

        $namespace = Patterns::namespaceOf($view);
        if ($namespace !== null && !$this->policy->allowsViewNamespace($namespace) && !$this->hasPatternFor($namespace)) {
            throw $this->deny(ForbiddenViewNamespaceException::for($namespace));
        }
        throw $this->deny(ForbiddenViewException::for($view));
    }

    private function hasPatternFor(string $namespace): bool
    {
        $describe = $this->policy instanceof SecurityPolicy ? $this->policy->describe() : [];
        foreach ($describe['views']['views'] ?? [] as $pattern) {
            if (str_starts_with($pattern, $namespace.'::')) {
                return true;
            }
        }

        return false;
    }

    /** Never echo arbitrary (possibly attacker controlled) names back in full. */
    private static function safeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-\.:\/\\\\]/', '?', $name) ?? '';

        return strlen($clean) > 80 ? substr($clean, 0, 80).'...' : $clean;
    }
}
