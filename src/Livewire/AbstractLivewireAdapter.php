<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

use Closure;
use Livewire\Livewire;

abstract class AbstractLivewireAdapter implements LivewireAdapter
{
    protected const PLAIN = ['loading', 'target', 'dirty', 'offline', 'ignore', 'key', 'navigate', 'confirm', 'transition', 'replace', 'cloak', 'stream', 'current', 'prompt'];
    protected const ACTIONS = ['click', 'submit', 'init', 'poll'];
    protected const INTERNAL = ['id', 'snapshot', 'effects', 'name', 'initial-data', 'end', 'partial'];

    /** @return list<string> */
    abstract protected function plainDirectives(): array;

    /** @return list<string> */
    abstract protected function expressionDirectives(): array;

    /** @return list<string> */
    abstract protected function internalDirectives(): array;

    public function isInstalled(): bool
    {
        return true;
    }

    public function directiveKind(string $directive): WireDirectiveKind
    {
        $directive = strtolower($directive);
        return match (true) {
            $directive === 'model' => WireDirectiveKind::Model,
            in_array($directive, $this->internalDirectives(), true) => WireDirectiveKind::Internal,
            in_array($directive, $this->expressionDirectives(), true) => WireDirectiveKind::Expression,
            in_array($directive, $this->plainDirectives(), true) => WireDirectiveKind::Plain,
            default => WireDirectiveKind::Action,
        };
    }

    public function registerRequestGuard(Closure $onCall, Closure $onUpdate): void
    {
        $before = $this->hookRegistrar();
        $before('call', static function ($component, $method, $params = []) use ($onCall): void {
            $onCall($component, (string) $method, is_array($params) ? $params : []);
        });
        $before('update', static function ($component, $path, $value = null) use ($onUpdate): void {
            $onUpdate($component, (string) $path, $value);
        });
    }

    public function mount(string $component, array $parameters): string
    {
        return Livewire::mount($component, $parameters);
    }

    /** @return Closure(string, Closure): mixed */
    abstract protected function hookRegistrar(): Closure;
}
