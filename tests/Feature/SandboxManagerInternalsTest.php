<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as FactoryContract;
use InvalidArgumentException;
use ReflectionProperty;
use stdClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Livewire\NullLivewireAdapter;
use SytxLabs\BladeSandbox\Runtime\SandboxAwareEngine;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class SandboxManagerInternalsTest extends TestCase
{
    private function bareManager(): SandboxManager
    {
        return new SandboxManager(new Container(), [], new NullLivewireAdapter());
    }

    public function testManagerLevelNamespaceGettersOnABareContainer(): void
    {
        $manager = $this->bareManager();

        $this->assertSame([], $manager->sandboxedNamespaces());
        $this->assertNull($manager->bindingForPath(__FILE__));
    }

    public function testManagerLevelRenderAndRenderView(): void
    {
        $manager = $this->app->make(SandboxManager::class);

        $this->assertSame('1', $manager->render('{{ 1 }}'));
        $this->assertSame("<header>ok</header>\n", $manager->policy('default')->allowView('deployer::partials.header')->renderView('deployer::partials.header', ['deployment' => (object) ['status' => 'ok']]));
    }

    public function testGuardLivewireComponentAndLookupByInstance(): void
    {
        $manager = $this->bareManager();
        $sandbox = $manager->make();

        $manager->guardLivewireComponent(SandboxManagerInternalsFakeComponent::class, $sandbox);

        $this->assertSame($sandbox, $manager->sandboxForLivewireComponent(new SandboxManagerInternalsFakeComponent()));
        $this->assertNull($manager->sandboxForLivewireComponent(new stdClass()));
    }

    public function testLockoutAndOutputCacheNeedTheCacheService(): void
    {
        $manager = $this->bareManager();

        try {
            $manager->lockout();
            $this->fail('lockout() without a cache service must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString("Laravel's cache", $exception->getMessage());
        }

        try {
            $manager->outputCacheStore();
            $this->fail('outputCacheStore() without a cache service must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString("Laravel's cache", $exception->getMessage());
        }
    }

    public function testAuditLoggerWithoutALogServiceHasNoLogger(): void
    {
        $manager = $this->bareManager();

        $audit = $manager->auditLogger(true);
        $this->assertTrue($audit->enabled());

        // No PSR logger and no bound 'log' service: violations are silently not logged anywhere.
        $audit->record(\SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException::for('x()'));
        $this->assertCount(1, $audit->recorded());
    }

    public function testSandboxNamespaceAcceptsAPolicyNameOrDefaultsToTheDefaultPolicy(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        config()->set('blade-sandbox.policies.ns-policy', ['functions' => ['strtoupper']]);

        $named = $manager->sandboxNamespace('ns-with-name', 'ns-policy');
        $this->assertTrue($named->policy()->allowsFunction('strtoupper'));

        $default = $manager->sandboxNamespace('ns-without-sandbox');
        $this->assertSame($manager->default()->policy()->fingerprint(), $default->policy()->fingerprint());
    }

    public function testBindingForPathIsNullWhenTheRealPathDoesNotExist(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->sandboxNamespace('deployer', $this->sandbox());

        $this->assertNull($manager->bindingForPath(__DIR__.'/../Fixtures/views/deployer/no-such-file.blade.php'));
    }

    public function testWrapEnginesIsIdempotentAndSkipsUnresolvableEngines(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->sandboxNamespace('deployer', $this->sandbox());

        $manager->wrapEngines();
        $manager->wrapEngines();

        $this->assertTrue(true);
    }

    public function testLoggingDescriptionCoversEveryTargetType(): void
    {
        $manager = $this->app->make(SandboxManager::class);

        $manager->useLogger(new class implements \Psr\Log\LoggerInterface
        {
            use \Psr\Log\LoggerTrait;

            public function log($level, $message, array $context = []): void
            {
            }
        });
        $this->assertStringContainsString('@anonymous', $manager->loggingDescription());

        $manager->useLogger('security-channel');
        $this->assertStringStartsWith('security-channel (', $manager->loggingDescription());

        config()->set('blade-sandbox.logging.enabled', true);
        $manager->useLogger(null);
        $this->assertStringStartsWith('default channel (', $manager->loggingDescription());
    }

    public function testResolveLoggerUsesAChannelWhenTheLogServiceSupportsIt(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->useLogger('single');

        $audit = $manager->auditLogger(true);
        $audit->record(\SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException::for('x()'));
        $this->assertCount(1, $audit->recorded());
    }

    public function testExtendCacheRecreatesTheActiveStoreWhenItMatchesTheCurrentDriver(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->make()->render('x');

        $manager->extendCache('file', static fn (array $config, SandboxManager $m): \SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore => new \SytxLabs\BladeSandbox\Cache\FileStore(new \Illuminate\Filesystem\Filesystem(), sys_get_temp_dir().'/blade-sandbox-extend-cache-'.getmypid()));

        $this->assertSame('x', $manager->make()->render('x'));
    }

    public function testCreateLoaderAcceptsATemplateLoaderInstanceDirectlyAndRejectsInvalidConfig(): void
    {
        $manager = $this->app->make(SandboxManager::class);

        $manager->configure(['loaders' => ['direct' => new \SytxLabs\BladeSandbox\Templates\ArrayTemplateLoader(['x' => 'y'])]]);
        $this->assertSame('y', $manager->make()->allowViewNamespace('direct')->renderView('direct::x'));

        try {
            $manager->configure(['loaders' => ['bad' => 42]]);
            $this->fail('a non-array, non-loader, non-string definition must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Invalid template loader configuration', $exception->getMessage());
        }
    }

    public function testEloquentLoaderCanBeConfiguredThroughTheLoadersArray(): void
    {
        $this->createUsers();
        $manager = $this->app->make(SandboxManager::class);

        $manager->configure(['loaders' => ['cfg-eloquent' => ['driver' => 'eloquent', 'model' => \SytxLabs\BladeSandbox\Tests\Fixtures\Models\User::class, 'name' => 'name', 'content' => 'email']]]);

        $this->assertInstanceOf(\SytxLabs\BladeSandbox\Templates\EloquentTemplateLoader::class, $manager->sources()->loader('cfg-eloquent'));
    }

    public function testManagerRenderViewGoesThroughTheRequestSandbox(): void
    {
        $this->expectException(ForbiddenViewException::class);
        $this->app->make(SandboxManager::class)->renderView('deployer::admin.secret');
    }

    public function testSandboxAwareEngineWrapsAndForwardsToTheInnerEngine(): void
    {
        $this->app->make(SandboxManager::class)->sandboxNamespace('deployer');
        $this->app->make(SandboxManager::class)->wrapEngines();
        $engine = $this->app->make('view.engine.resolver')->resolve('blade');

        $this->assertInstanceOf(SandboxAwareEngine::class, $engine);
        $this->assertNotInstanceOf(SandboxAwareEngine::class, $engine->inner());
        $this->assertSame($engine->inner()->getCompiler(), $engine->getCompiler());
    }

    public function testEnginesThatCannotBeResolvedOrAreAlreadyWrappedAreSkipped(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->sandboxNamespace('deployer');
        $this->app->make('view')->addExtension('sandbox-test', 'not-a-registered-engine');

        $manager->wrapEngines();
        (new ReflectionProperty($manager, 'enginesWrapped'))->setValue($manager, false);
        $manager->wrapEngines();

        $this->assertInstanceOf(SandboxAwareEngine::class, $this->app->make('view.engine.resolver')->resolve('blade'));
    }

    public function testSandboxedViewsNeedALaravelViewFactory(): void
    {
        $this->app->instance('view', $this->createMock(FactoryContract::class));

        $this->expectException(ForbiddenViewException::class);
        $this->sandbox()->allowView('*')->view('anything');
    }
}

final class SandboxManagerInternalsFakeComponent
{
}
