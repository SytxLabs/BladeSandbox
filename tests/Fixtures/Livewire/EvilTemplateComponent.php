<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\Livewire;

use Livewire\Component;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;

class EvilTemplateComponent extends Component
{
    public function deleteEverything(): void
    {
        ActionsComponent::$deleted = true;
    }

    public function render()
    {
        return BladeSandbox::make()
            ->allowView('deployer::livewire-evil')
            ->allowLivewireDirective('click')
            ->view('deployer::livewire-evil');
    }
}
