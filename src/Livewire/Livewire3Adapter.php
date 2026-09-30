<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

use Closure;

final class Livewire3Adapter extends AbstractLivewireAdapter
{
    public function majorVersion(): int
    {
        return 3;
    }

    protected function plainDirectives(): array
    {
        return self::PLAIN;
    }

    protected function expressionDirectives(): array
    {
        return ['show', 'text'];
    }

    protected function internalDirectives(): array
    {
        return self::INTERNAL;
    }

    protected function hookRegistrar(): Closure
    {
        return function_exists('Livewire\before') ? static fn (string $event, Closure $callback) => \Livewire\before($event, $callback) : static fn (string $event, Closure $callback) => \Livewire\on($event, $callback);
    }
}
