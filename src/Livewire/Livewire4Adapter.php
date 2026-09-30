<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

use Closure;

final class Livewire4Adapter extends AbstractLivewireAdapter
{
    public function majorVersion(): int
    {
        return 4;
    }

    protected function plainDirectives(): array
    {
        return [...self::PLAIN, 'ref', 'sort:item', 'sort:handle', 'sort:ignore', 'sort:group', 'preserve-scroll'];
    }

    protected function expressionDirectives(): array
    {
        return ['show', 'text', 'bind'];
    }

    protected function internalDirectives(): array
    {
        return [...self::INTERNAL, 'island'];
    }

    protected function hookRegistrar(): Closure
    {
        return static fn (string $event, Closure $callback) => \Livewire\before($event, $callback);
    }
}
