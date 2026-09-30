<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

use Illuminate\Http\Request;
use SytxLabs\BladeSandbox\Sandbox;

/** Chooses the sandbox for the current request (tenant, role, author, ...): return a Sandbox, the name of a policy from config/blade-sandbox.php, or null for the default policy. */
interface PolicyResolver
{
    public function resolve(?Request $request): Sandbox|string|null;
}
