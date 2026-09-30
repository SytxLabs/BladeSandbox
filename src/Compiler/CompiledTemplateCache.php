<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;

/**
 * Compiles sandbox templates on demand and keeps them in a {@see CompiledTemplateStore}.
 *
 * The cache key is sha256(compiler version + Livewire version | policy fingerprint | source),
 * so a template that was compiled and validated under one policy is never reused under a different (e.g. more permissive or more restrictive) policy, and every source change produces a new entry.
 * Runtime checks are still performed on every render; the key only guarantees that compile-time decisions match the policy.
 *
 * The store's directory must not be writable by untrusted parties (see SECURITY.md).
 */
final class CompiledTemplateCache
{
    /** @var array<string, string> key => compiled path */
    private array $verified = [];

    public function __construct(private readonly SandboxBladeCompiler $compiler, private readonly CompiledTemplateStore $store)
    {
    }

    public function compiledPath(string $source, SecurityPolicy $policy, AuditLogger $audit, string $template): string
    {
        $key = hash('sha256', $this->compiler->cacheContext()."\0".$policy->fingerprint()."\0".$source);
        if (isset($this->verified[$key])) {
            return $this->verified[$key];
        }

        $path = $this->store->get($key);
        if ($path === null) {
            $compiled = '<?php /* blade-sandbox '.SandboxBladeCompiler::VERSION." */ ?>\n".$this->compiler->compile($source, $policy, $audit, $template);
            $path = $this->store->put($key, $compiled);
        }
        return $this->verified[$key] = $path;
    }

    public function store(): CompiledTemplateStore
    {
        return $this->store;
    }

    public function flush(): void
    {
        $this->verified = [];
        $this->store->flush();
    }
}
