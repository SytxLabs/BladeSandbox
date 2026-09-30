<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Facades;

use Illuminate\Support\Facades\Facade;
use RuntimeException;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Testing\SandboxRecorder;
use Throwable;

/**
 * @method static \SytxLabs\BladeSandbox\Sandbox make()
 * @method static \SytxLabs\BladeSandbox\Sandbox policy(string $name)
 * @method static \SytxLabs\BladeSandbox\Sandbox default()
 * @method static bool hasPolicy(string $name)
 * @method static \SytxLabs\BladeSandbox\SandboxManager definePolicy(string $name, array|\Closure $definition)
 * @method static \SytxLabs\BladeSandbox\SandboxManager extendPolicy(string $name, array|\Closure ...$definitions)
 * @method static \SytxLabs\BladeSandbox\SandboxManager configure(array $overrides)
 * @method static mixed setting(string $key, mixed $default = null)
 * @method static \SytxLabs\BladeSandbox\SandboxManager useLogger(\Psr\Log\LoggerInterface|string|null $logger, ?string $level = null)
 * @method static string render(string $template, array $data = [])
 * @method static string renderView(string $view, array $data = [])
 * @method static \SytxLabs\BladeSandbox\Sandbox sandboxNamespace(string $namespace, \SytxLabs\BladeSandbox\Sandbox|string|null $sandbox = null)
 * @method static void guardLivewireComponent(string $component, \SytxLabs\BladeSandbox\Sandbox $sandbox)
 * @method static \SytxLabs\BladeSandbox\SandboxManager useCache(\SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore|string $store)
 * @method static \SytxLabs\BladeSandbox\SandboxManager extendCache(string $driver, \Closure $factory)
 * @method static \SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore cacheStore()
 * @method static \SytxLabs\BladeSandbox\SandboxManager loader(string $namespace, \SytxLabs\BladeSandbox\Contracts\TemplateLoader|\Closure $loader)
 * @method static \SytxLabs\BladeSandbox\SandboxManager extendLoader(string $driver, \Closure $factory)
 * @method static \SytxLabs\BladeSandbox\SandboxManager extendFallback(string $name, \SytxLabs\BladeSandbox\Contracts\FallbackRenderer|\Closure $renderer)
 * @method static \SytxLabs\BladeSandbox\SandboxManager helpers(string $name, \Closure $apply)
 * @method static \SytxLabs\BladeSandbox\Contracts\ViolationLimiter lockout()
 * @method static \SytxLabs\BladeSandbox\SandboxManager resolvePolicyUsing(\SytxLabs\BladeSandbox\Contracts\PolicyResolver|\Closure|string|null $resolver)
 * @method static \SytxLabs\BladeSandbox\Sandbox forRequest(?\Illuminate\Http\Request $request = null)
 * @method static \SytxLabs\BladeSandbox\SandboxManager useViolationLimiter(\SytxLabs\BladeSandbox\Contracts\ViolationLimiter $limiter)
 * @method static \SytxLabs\BladeSandbox\SandboxManager useMarkdownConverter(\SytxLabs\BladeSandbox\Contracts\MarkdownConverter $converter)
 * @method static \SytxLabs\BladeSandbox\SandboxManager useTextConverter(\SytxLabs\BladeSandbox\Contracts\TextConverter $converter)
 * @method static \SytxLabs\BladeSandbox\SandboxManager varyOutputCacheBy(\Closure $vary)
 * @method static \SytxLabs\BladeSandbox\Templates\TemplateSources sources()
 *
 * @see SandboxManager
 */
final class BladeSandbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SandboxManager::class;
    }

    public static function fake(): SandboxRecorder
    {
        return self::manager()->record();
    }

    public static function assertRendered(string $view, ?int $times = null): SandboxRecorder
    {
        return self::recorder()->assertRendered($view, $times);
    }

    public static function assertNotRendered(string $view): SandboxRecorder
    {
        return self::recorder()->assertNotRendered($view);
    }

    public static function assertNothingRendered(): SandboxRecorder
    {
        return self::recorder()->assertNothingRendered();
    }

    /** @param class-string<Throwable>|null $exception */
    public static function assertViolation(?string $exception = null): SandboxRecorder
    {
        return self::recorder()->assertViolation($exception);
    }

    public static function assertNoViolations(): SandboxRecorder
    {
        return self::recorder()->assertNoViolations();
    }

    public static function assertFallbackUsed(?string $view = null): SandboxRecorder
    {
        return self::recorder()->assertFallbackUsed($view);
    }

    private static function manager(): SandboxManager
    {
        $manager = self::getFacadeRoot();
        if (!$manager instanceof SandboxManager) {
            throw new RuntimeException('The blade sandbox manager is not available.');
        }
        return $manager;
    }

    private static function recorder(): SandboxRecorder
    {
        return self::manager()->recorder() ?? throw new RuntimeException('Call BladeSandbox::fake() before using sandbox assertions.');
    }
}
