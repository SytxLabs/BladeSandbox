<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Console\Concerns;

use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Support\Patterns;

trait ResolvesTemplateTargets
{
    protected function resolveTarget(SandboxManager $manager, string $target): ?array
    {
        if (is_dir($target)) {
            $templates = [];
            foreach ($manager->viewFiles()->inDirectory($target) as $name => $path) {
                $templates[] = ['name' => $name, 'path' => $path];
            }
            return $templates;
        }
        if (is_file($target)) {
            return [['name' => $target, 'path' => $target]];
        }
        $namespace = str_ends_with($target, '::') ? substr($target, 0, -2) : (str_contains($target, '::') ? null : $target);
        if ($namespace !== null && $manager->finder()->namespaceDirectories($namespace) !== []) {
            $templates = [];
            foreach ($manager->viewFiles()->forNamespace($namespace) as $view => $path) {
                $templates[] = ['name' => $view, 'path' => $path];
            }

            return $templates;
        }
        if (Patterns::isValidName($target)) {
            return [['name' => $target, 'view' => $target]];
        }
        return null;
    }
}
