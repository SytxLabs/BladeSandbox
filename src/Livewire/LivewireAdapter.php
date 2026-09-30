<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

use Closure;

interface LivewireAdapter
{
    public function isInstalled(): bool;

    public function majorVersion(): ?int;

    public function directiveKind(string $directive): WireDirectiveKind;

    /**
     * @param Closure(object $component, string $method, array<int, mixed> $params): void $onCall
     * @param Closure(object $component, string $path, mixed $value): void $onUpdate
     */
    public function registerRequestGuard(Closure $onCall, Closure $onUpdate): void;

    /** @param array<string, mixed> $parameters */
    public function mount(string $component, array $parameters): string;
}
