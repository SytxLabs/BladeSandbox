<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use Closure;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Guards\DirectiveGuard;
use SytxLabs\BladeSandbox\Guards\LivewireGuard;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;

/**
 * The sandbox's own Blade compiler.
 *
 * It deliberately does not extend Illuminate's BladeCompiler: that compiler passes raw PHP through, supports @php, executes every application directive and emits scaffolding that talks to the view factory ($__env) and facades.
 * This compiler only knows a closed set of Blade constructs, routes all user expressions through {@see ExpressionSandboxer} and finally verifies the whole output with {@see PhpAstValidator}.
 */
final class SandboxBladeCompiler
{
    public const VERSION = '1.0.0';

    /** @var list<string>|null */
    private ?array $directiveNames = null;

    /** @param Closure(): list<string> $applicationDirectives lazily returns names registered via Blade::directive()/Blade::if() */
    public function __construct(private readonly ExpressionSandboxer $expressions, private readonly PhpAstValidator $validator, private readonly LivewireAdapter $livewire, private readonly Closure $applicationDirectives)
    {
    }

    public function cacheContext(): string
    {
        return self::VERSION.'|livewire:' . ($this->livewire->majorVersion() ?? 'none');
    }

    public function compile(string $source, SecurityPolicy $policy, AuditLogger $audit, string $template, ?TemplateReferences $references = null): string
    {
        $this->directiveNames ??= array_values(array_map('strtolower', ($this->applicationDirectives)()));
        $compilation = new Compilation($source, $template, $policy, $this->expressions, (new DirectiveGuard($policy, $audit))->forTemplate($template), (new LivewireGuard($policy, $audit, $this->livewire))->forTemplate($template), $this->directiveNames, $references);
        $this->expressions->collectInto($references);
        try {
            $compiled = $compilation->run();
        } finally {
            $this->expressions->collectInto(null);
        }
        $this->validator->validate($compiled);
        return $compiled;
    }
}
