<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

interface SandboxRenderer
{
    /**
     * Render an untrusted Blade template string.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string;

    /**
     * Render a view resolved through the regular Laravel view finder (supports namespaces like "plugin::page").
     *
     * @param array<string, mixed> $data
     */
    public function renderView(string $view, array $data = []): string;
}
