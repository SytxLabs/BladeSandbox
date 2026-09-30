<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ViewErrorBag;
use SytxLabs\BladeSandbox\Events\TemplateRendered;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Templates\ArrayTemplateLoader;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class OutputCacheTest extends TestCase
{
    private ArrayTemplateLoader $loader;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new ArrayTemplateLoader(['page' => '<p>{{ $n }}</p>']);
        $this->app->make(SandboxManager::class)->loader('c', $this->loader);
    }

    private function cached(): Sandbox
    {
        return $this->sandbox()->allowViewNamespace('c')->cacheOutput(60);
    }

    public function testOutputIsServedFromTheCache(): void
    {
        Event::fake([TemplateRendered::class]);
        $sandbox = $this->cached();

        $this->assertSame('<p>1</p>', $sandbox->renderView('c::page', ['n' => 1]));
        $this->assertSame('<p>1</p>', $sandbox->renderView('c::page', ['n' => 1]));

        Event::assertDispatchedTimes(TemplateRendered::class, 2);
        Event::assertDispatched(TemplateRendered::class, static fn (TemplateRendered $event): bool => $event->fromCache);
    }

    public function testKeyCoversDataSourceAndPolicy(): void
    {
        $sandbox = $this->cached();
        $this->assertSame('<p>1</p>', $sandbox->renderView('c::page', ['n' => 1]));
        $this->assertSame('<p>2</p>', $sandbox->renderView('c::page', ['n' => 2]));

        $this->loader->put('page', '<b>{{ $n }}</b>');
        $this->assertSame('<b>1</b>', $sandbox->renderView('c::page', ['n' => 1]));

        $this->assertSame('<b>1</b>!', $this->cached()->allowFunction('strtoupper')->render('<b>{{ $n }}</b>!', ['n' => 1]));
    }

    /** @return list<bool> fromCache flags of the recorded renders */
    private function hits(): array
    {
        return Event::dispatched(TemplateRendered::class)
            ->map(static fn (array $arguments): bool => $arguments[0]->fromCache)
            ->values()
            ->all();
    }

    public function testUncacheableDataIsRenderedNormally(): void
    {
        Event::fake([TemplateRendered::class]);
        $sandbox = $this->cached();
        $data = ['n' => 1, 'callback' => static fn () => 1];

        $this->assertSame('1', $sandbox->render('{{ $n }}', $data));
        $this->assertSame('1', $sandbox->render('{{ $n }}', $data));
        $this->assertSame([false, false], $this->hits());
    }

    public function testVaryByAndCustomKey(): void
    {
        Event::fake([TemplateRendered::class]);
        $locale = 'de';
        $this->app->make(SandboxManager::class)->varyOutputCacheBy(static function () use (&$locale): string {
            return $locale;
        });
        $tenant = 1;
        $sandbox = $this->cached()->cacheOutput(60, null, static function () use (&$tenant): int {
            return $tenant;
        });

        $sandbox->render('{{ $n }}', ['n' => 1]);
        $locale = 'en';
        $sandbox->render('{{ $n }}', ['n' => 1]);
        $tenant = 2;
        $sandbox->render('{{ $n }}', ['n' => 1]);
        $sandbox->render('{{ $n }}', ['n' => 1]);

        $this->assertSame([false, false, false, true], $this->hits());
    }

    public function testFailuresAreNotCachedAndConfig(): void
    {
        Event::fake([TemplateRendered::class]);
        $this->app['config']->set('blade-sandbox.fallback_report', false);
        $sandbox = PolicyConfiguration::apply($this->sandbox(), ['output_cache' => ['ttl' => 10], 'fallback' => 'empty']);

        $this->assertSame('', $sandbox->render('{{ exec("id") }}'));
        $this->assertSame('', $sandbox->render('{{ exec("id") }}'));
        $this->assertSame('ok', $sandbox->render('ok'));
        $this->assertSame('ok', $sandbox->render('ok'));
        $this->assertSame([false, true], $this->hits());
    }

    public function testWithoutOutputCacheDisablesCaching(): void
    {
        Event::fake([TemplateRendered::class]);
        $sandbox = $this->cached()->withoutOutputCache();

        $sandbox->renderView('c::page', ['n' => 1]);
        $sandbox->renderView('c::page', ['n' => 1]);

        $this->assertSame([false, false], $this->hits());
    }

    public function testLivewireCapablePoliciesAreNeverCached(): void
    {
        Event::fake([TemplateRendered::class]);
        $sandbox = $this->cached()->allowLivewireComponent('counter');

        $sandbox->renderView('c::page', ['n' => 1]);
        $sandbox->renderView('c::page', ['n' => 1]);

        $this->assertSame([false, false], $this->hits());
    }

    public function testAuthDirectivesVaryByUser(): void
    {
        $sandbox = $this->cached()->allowAuthDirectives();
        $template = '@auth in @else out @endauth';

        $this->assertSame(' out ', $sandbox->render($template));
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->assertSame(' in ', $sandbox->render($template));
    }

    public function testTheCsrfTokenIsPartOfTheCacheKey(): void
    {
        Event::fake([TemplateRendered::class]);
        $session = $this->app['session']->driver();
        $session->regenerateToken();
        $sandbox = $this->cached()->allowDirective('csrf');

        $sandbox->renderView('c::page', ['n' => 1]);
        $sandbox->renderView('c::page', ['n' => 1]);
        $session->regenerateToken();
        $sandbox->renderView('c::page', ['n' => 1]);

        $this->assertSame([false, true, false], $this->hits());
    }

    public function testPagesWithValidationErrorsAreNeverCached(): void
    {
        Event::fake([TemplateRendered::class]);
        $this->app['session']->driver()->put('errors', new ViewErrorBag());
        $sandbox = $this->cached()->allowDirective('error');

        $sandbox->renderView('c::page', ['n' => 1]);
        $sandbox->renderView('c::page', ['n' => 1]);

        $this->assertSame([false, false], $this->hits());
    }
}
