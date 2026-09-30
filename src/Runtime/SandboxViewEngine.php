<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use Illuminate\Contracts\View\Engine;
use SytxLabs\BladeSandbox\Sandbox;

final readonly class SandboxViewEngine implements Engine
{
    public function __construct(private Sandbox $sandbox, private string $view)
    {
    }

    /** @param array<string, mixed> $data */
    public function get(mixed $path, array $data = []): string
    {
        return $this->sandbox->renderFile((string) $path, $this->view, $data);
    }
}
