<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use SytxLabs\BladeSandbox\Contracts\SandboxedLivewireComponent;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Sandbox;

class ActionsComponent extends Component implements SandboxedLivewireComponent
{
    public static bool $deleted = false;

    public string $title = 'draft';

    public string $secret = 'keep';

    public int $saved = 0;

    public function sandbox(): Sandbox
    {
        return BladeSandbox::make()
            ->allowView('deployer::livewire-actions')
            ->allowLivewireDirective('click')
            ->allowLivewireDirective('model')
            ->allowLivewireAction('save')
            ->allowLivewireModel('title')
            ->allowLivewireEvent('refresh-title');
    }

    public function save(): void
    {
        $this->saved++;
    }

    public function deleteEverything(): void
    {
        static::$deleted = true;
    }

    #[On('refresh-title')]
    public function refreshTitle(): void
    {
        $this->title = 'refreshed';
    }

    #[On('nuke')]
    public function nuke(): void
    {
        static::$deleted = true;
    }

    public function render()
    {
        return $this->sandbox()->view('deployer::livewire-actions');
    }
}
