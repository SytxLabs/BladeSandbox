<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compatibility;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;

/**
 * Maps a component name ("plugin::button", "alert", "forms.input") to a class component or an anonymous component view,
 * reusing Laravel's own resolution rules (aliases, class namespaces, anonymous component namespaces and paths). Resolution only; it grants no trust.
 */
final class ComponentResolver
{
    /** @var array<string, array{type: 'class'|'view', target: string}> */
    private array $resolved = [];

    public function __construct(private readonly BladeCompiler $blade)
    {
    }

    /** @return array{type: 'class'|'view', target: string} */
    public function resolve(string $component): array
    {
        if (isset($this->resolved[$component])) {
            return $this->resolved[$component];
        }

        $compiler = new ComponentTagCompiler($this->blade->getClassComponentAliases(), $this->blade->getClassComponentNamespaces(), $this->blade);
        try {
            $target = $compiler->componentClass($component);
        } catch (InvalidArgumentException) {
            throw new SandboxException('Unable to locate a class or view for component ['.$component.'].');
        }
        return $this->resolved[$component] = ['type' => class_exists($target) ? 'class' : 'view', 'target' => $target];
    }
}
