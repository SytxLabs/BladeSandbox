<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use Illuminate\Filesystem\Filesystem;
use SytxLabs\BladeSandbox\Compatibility\ViewFinderAdapter;
use SytxLabs\BladeSandbox\Compiler\CompiledTemplateCache;
use SytxLabs\BladeSandbox\Compiler\SandboxRewriteVisitor;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\TemplateIntegrityException;
use SytxLabs\BladeSandbox\Templates\TemplateSources;
use Throwable;

/**
 * Resolves, compiles (through the sandbox compiler) and evaluates templates.
 *
 * View *resolution* uses the normal Laravel view finder, so namespaced / package / external views
 * work. The resolved file is never trusted: its contents are compiled by the sandbox compiler no
 * matter where it lives or which extension it has. Policy checks are performed by the callers
 * (entry points and SandboxRuntime) before a view is rendered.
 */
final readonly class SandboxViewRenderer
{
    public function __construct(private TemplateSources $sources, private CompiledTemplateCache $cache, private Filesystem $files, private array $hiddenVariables = ['app', '__env', '_instance', '__livewire'])
    {
    }

    public function finder(): ViewFinderAdapter
    {
        return $this->sources->finder();
    }

    public function sources(): TemplateSources
    {
        return $this->sources;
    }

    public function exists(string $view): bool
    {
        return $this->sources->exists($view);
    }

    /** @param array<string, mixed> $data */
    public function renderView(string $view, array $data, SandboxRuntime $runtime): string
    {
        return $this->renderSource($this->sources->source($view), $view, $data, $runtime);
    }

    /** @param array<string, mixed> $data */
    public function renderFile(string $path, string $view, array $data, SandboxRuntime $runtime): string
    {
        return $this->renderSource($this->files->get($path), $view, $data, $runtime);
    }

    /** @param array<string, mixed> $data */
    public function renderSource(string $source, string $view, array $data, SandboxRuntime $runtime): string
    {
        if (($manifest = $runtime->integrity()) !== null) {
            try {
                $manifest->assert($view, $source);
            } catch (TemplateIntegrityException $violation) {
                $runtime->audit()->record($violation, $view);

                throw $violation;
            }
        }

        return $this->renderString($source, $data, $runtime, $view);
    }

    /** @param array<string, mixed> $data */
    public function renderString(string $source, array $data, SandboxRuntime $runtime, string $name): string
    {
        $context = $runtime->context();
        $previous = $context->currentTemplate();
        $context->enter($name);
        $runtime->using($name);

        try {
            $compiled = $this->cache->compiledPath($source, $runtime->policy(), $runtime->audit(), $name);
            $output = $this->evaluate($compiled, $this->filter($data), $runtime);

            return $context->assertOutputSize($output);
        } finally {
            $context->leave();
            $runtime->using($previous);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function filter(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
                continue;
            }
            if (SandboxRewriteVisitor::isReservedVariable($key) || in_array($key, $this->hiddenVariables, true)) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /** @param array<string, mixed> $data */
    private function evaluate(string $compiled, array $data, SandboxRuntime $runtime): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__path, array $__data, SandboxRuntime $__sandbox): void {
                extract($__data, EXTR_SKIP);
                unset($__data);

                include $__path;
            })($compiled, $data, $runtime);
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }

        if (ob_get_level() !== $level + 1) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw new SandboxException('Unbalanced section, push, slot or component in sandboxed template.');
        }

        return (string) ob_get_clean();
    }
}
