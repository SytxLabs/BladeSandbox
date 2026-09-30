<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use Illuminate\Contracts\View\Engine;
use SytxLabs\BladeSandbox\SandboxManager;

/**
 * Decorates the application's view engines (blade, php, file, ...) once view namespaces are bound
 * to a sandbox with SandboxManager::sandboxNamespace(). Any view file that lives in a bound
 * namespace's directories is rendered by that sandbox - no matter whether it is rendered through
 * view(), @include of a normal template, Mail, Livewire or anything else. Other views are passed to
 * the original engine unchanged.
 */
final readonly class SandboxAwareEngine implements Engine
{
    public function __construct(private Engine $inner, private SandboxManager $manager)
    {
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    /** @param array<string, mixed> $data */
    public function get(mixed $path, array $data = []): string
    {
        $binding = $this->manager->bindingForPath((string) $path);
        return $binding === null ? $this->inner->get($path, $data) : $binding['sandbox']->renderFile((string) $path, $binding['view'], $data);
    }

    public function inner(): Engine
    {
        return $this->inner;
    }
}
