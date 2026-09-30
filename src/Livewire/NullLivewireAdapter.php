<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

use Closure;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;

final class NullLivewireAdapter implements LivewireAdapter
{
    public function isInstalled(): bool
    {
        return false;
    }

    public function majorVersion(): ?int
    {
        return null;
    }

    public function directiveKind(string $directive): WireDirectiveKind
    {
        return match (strtolower($directive)) {
            'model' => WireDirectiveKind::Model,
            'id', 'snapshot', 'effects', 'name' => WireDirectiveKind::Internal,
            'show', 'text', 'bind' => WireDirectiveKind::Expression,
            'loading', 'target', 'dirty', 'offline', 'ignore', 'key', 'navigate', 'confirm', 'transition', 'replace', 'cloak', 'stream', 'current' => WireDirectiveKind::Plain,
            default => WireDirectiveKind::Action,
        };
    }

    public function registerRequestGuard(Closure $onCall, Closure $onUpdate): void
    {
    }

    public function mount(string $component, array $parameters): string
    {
        throw new SandboxException('Livewire is not installed.');
    }
}
