<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\Livewire;

use Livewire\Component;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;

/**
 * Renders a view of the sandboxed "deployer" namespace with the plain view() helper (spec §25).
 */
class TestComponent extends Component
{
    public TestDTO $dto;

    public function mount(): void
    {
        $this->dto = new TestDTO(['name' => 'api', 'status' => 'running']);
    }

    public function render()
    {
        return view('deployer::livewire');
    }
}
