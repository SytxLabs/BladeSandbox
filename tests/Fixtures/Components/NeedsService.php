<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\Components;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Component;

final class NeedsService extends Component
{
    public function __construct(public Filesystem $files)
    {
    }

    public function render()
    {
        return 'files: {{ $label ?? "none" }}';
    }
}
