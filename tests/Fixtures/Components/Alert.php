<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\Components;

use Illuminate\View\Component;

final class Alert extends Component
{
    public function __construct(public string $type = 'info', public string $message = '')
    {
    }

    public function secretMethod(): string
    {
        return 'component-method';
    }

    public function render()
    {
        return view('deployer::components.alert');
    }
}
