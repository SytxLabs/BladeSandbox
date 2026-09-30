<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use stdClass;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Cache\FileStore;
use SytxLabs\BladeSandbox\Compiler\CompiledTemplateCache;
use SytxLabs\BladeSandbox\Compiler\ExpressionSandboxer;
use SytxLabs\BladeSandbox\Compiler\PhpAstValidator;
use SytxLabs\BladeSandbox\Compiler\SandboxBladeCompiler;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenLivewireDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Livewire\Livewire4Adapter;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Livewire\NullLivewireAdapter;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class CacheAndAuditTest extends TestCase
{
    public function testCompiledTemplatesAreCachedPerPolicy(): void
    {
        $template = '{!! $html !!}';
        $permissive = $this->sandbox()->allowRawEcho();

        $this->assertSame('<b>x</b>', $permissive->render($template, ['html' => '<b>x</b>']));

        // Same source, stricter policy: the template compiled under the permissive policy is not reused.
        $this->expectException(ForbiddenDirectiveException::class);
        $this->sandbox()->render($template, ['html' => '<b>x</b>']);
    }

    public function testSameTemplateAndPolicyReuseTheCompiledFile(): void
    {
        $cache = $this->app->make(SandboxManager::class)->cache();
        $sandbox = $this->sandbox();
        $audit = $sandbox->runtime()->audit();

        $first = $cache->compiledPath('{{ $a }}', $sandbox->policy(), $audit, 't');
        $second = $cache->compiledPath('{{ $a }}', $sandbox->policy(), $audit, 't');
        $other = $cache->compiledPath('{{ $a }}', $this->sandbox()->allowFunction('strlen')->policy(), $audit, 't');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
        $this->assertFileExists($first);
    }

    public function testRuntimeChecksApplyEvenWhenACompiledFileIsReused(): void
    {
        $template = '{{ strtoupper($a) }}';
        $this->assertSame('X', $this->sandbox()->allowFunction('strtoupper')->render($template, ['a' => 'x']));

        $this->expectException(ForbiddenFunctionException::class);
        $this->sandbox()->render($template, ['a' => 'x']);
    }

    public function testViolationsAreAuditedWithoutTemplateContents(): void
    {
        Log::shouldReceive('log')
            ->once()
            ->withArgs(static function (string $level, string $message, array $context): bool {
                return $level === 'warning' && $message === '[blade-sandbox] Forbidden method stdClass::delete()'
                    && $context['capability'] === 'method'
                    && ! str_contains(json_encode($context), 'secret-value');
            });

        try {
            $this->sandbox()->debug()->render('{{ $o->delete() }} secret-value', ['o' => new stdClass()]);
            $this->fail('expected violation');
        } catch (ForbiddenMethodException $exception) {
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }

    public function testPoliciesFromConfiguration(): void
    {
        config()->set('blade-sandbox.policies.mail', [
            'views' => ['deployer::emails.*'],
            'functions' => ['strtoupper'],
            'directives' => ['lang'],
        ]);

        $manager = new SandboxManager($this->app, config('blade-sandbox'), $this->app->make(LivewireAdapter::class));
        $sandbox = $manager->policy('mail');

        $this->assertTrue($sandbox->policy()->allowsView('deployer::emails.deploy'));
        $this->assertSame('ABC', $sandbox->render("{{ strtoupper('abc') }}"));
        $this->assertSame('messages.missing', $sandbox->render("@lang('messages.missing')"));
    }

    public function testClearCommand(): void
    {
        $this->sandbox()->render('{{ $a }}', ['a' => 1]);
        $directory = config('blade-sandbox.cache_path');
        $this->assertNotEmpty(glob($directory.'/*.php'));

        $this->artisan('blade-sandbox:clear')->assertSuccessful();

        $this->assertSame([], glob($directory.'/*.php'));
        $this->assertSame('1', $this->sandbox()->render('{{ $a }}', ['a' => 1]));
    }

    public function testCacheKeyDependsOnTheLivewireInstallation(): void
    {
        $compiler = static fn ($adapter) => new SandboxBladeCompiler(
            new ExpressionSandboxer(),
            new PhpAstValidator(),
            $adapter,
            static fn (): array => [],
        );
        $directory = sys_get_temp_dir().'/blade-sandbox-context-'.getmypid();
        $policy = $this->sandbox()->policy();
        $audit = new AuditLogger();

        $without = (new CompiledTemplateCache($compiler(new NullLivewireAdapter()), new FileStore(new Filesystem(), $directory)))
            ->compiledPath('<div x-data="{}"></div>', $policy, $audit, 't');
        $this->assertFileExists($without);

        // With Livewire installed the same template is compiled again (and rejected, JS surfaces are enforced).
        $this->expectException(ForbiddenLivewireDirectiveException::class);
        (new CompiledTemplateCache($compiler(new Livewire4Adapter()), new FileStore(new Filesystem(), $directory)))
            ->compiledPath('<div x-data="{}"></div>', $policy, $audit, 't');
    }

    public function testAuditLoggerGetters(): void
    {
        $audit = new AuditLogger(null, true, 'error');

        $this->assertSame('error', $audit->level());
        $this->assertTrue($audit->enabled());
        $this->assertSame([], $audit->recorded());

        $disabled = $audit->withEnabled(false);
        $this->assertFalse($disabled->enabled());
        $this->assertSame('error', $disabled->level());
    }
}
