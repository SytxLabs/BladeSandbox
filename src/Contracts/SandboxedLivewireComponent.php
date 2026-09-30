<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

use SytxLabs\BladeSandbox\Sandbox;

/** Implemented by Livewire components whose incoming requests (actions, property updates, events) must be checked against a sandbox policy on the server. */
interface SandboxedLivewireComponent
{
    public function sandbox(): Sandbox;
}
