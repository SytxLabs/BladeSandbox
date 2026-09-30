<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use InvalidArgumentException;
use SytxLabs\BladeSandbox\Config\SandboxConfig;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Sanitizers\HtmlSanitizer;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class ConfigOverrideTest extends TestCase
{
    public function testPartialConfigIsMergedOverTheDefaults(): void
    {
        $config = SandboxConfig::resolve(['limits' => ['timeout_ms' => 500], 'sanitizers' => ['cms' => HtmlSanitizer::class]]);

        $this->assertSame(500, $config['limits']['timeout_ms']);
        $this->assertSame(1_000_000, $config['limits']['max_iterations']);
        $this->assertSame('blade-sandbox:output:', $config['output_cache']['prefix']);
        $this->assertArrayHasKey('strict', $config['sanitizers']);
        $this->assertSame(HtmlSanitizer::class, $config['sanitizers']['cms']);
    }

    public function testListsReplaceAndEmptyGroupsKeepTheDefaults(): void
    {
        $config = SandboxConfig::resolve(['hidden_variables' => ['secret'], 'isolation' => []]);

        $this->assertSame(['secret'], $config['hidden_variables']);
        $this->assertSame(10, $config['isolation']['timeout']);
    }

    public function testLegacyKeysAreMapped(): void
    {
        $config = SandboxConfig::resolve(['max_iterations' => 7, 'audit' => ['enabled' => true, 'channel' => 'security']]);

        $this->assertSame(7, $config['limits']['max_iterations']);
        $this->assertArrayNotHasKey('max_iterations', $config);
        $this->assertTrue($config['logging']['enabled']);
        $this->assertSame('security', $config['logging']['channel']);
        $this->assertArrayNotHasKey('audit', $config);
    }

    public function testTheLoadedConfigurationIsResolved(): void
    {
        $this->assertSame(32, config('blade-sandbox.limits.max_depth'));
        $this->assertSame('warning', config('blade-sandbox.logging.level'));
        $this->assertNotNull(config('blade-sandbox.cache_path'));
    }

    public function testManagerCreatedWithAPartialConfiguration(): void
    {
        $this->app['config']->set('blade-sandbox', null);
        $manager = new SandboxManager($this->app, ['limits' => ['max_iterations' => 3]], $this->app->make(LivewireAdapter::class));

        $this->assertSame(3, $manager->make()->limits()->maxIterations);
        $this->assertSame(32, $manager->make()->limits()->maxDepth);
    }

    public function testConfigureOverridesAfterTheManagerWasResolved(): void
    {
        BladeSandbox::make();
        BladeSandbox::configure(['limits' => ['max_iterations' => 2], 'policies' => ['cms' => ['functions' => ['strtoupper']]]]);

        $this->assertSame(2, config('blade-sandbox.limits.max_iterations'));
        $this->assertSame(32, config('blade-sandbox.limits.max_depth'));
        $this->assertSame('X', BladeSandbox::policy('cms')->render('{{ strtoupper("x") }}'));

        $this->expectException(SandboxLimitExceededException::class);
        BladeSandbox::make()->render('@foreach([1, 2, 3] as $i){{ $i }}@endforeach');
    }

    public function testConfigureChangesTheDefaultPolicy(): void
    {
        BladeSandbox::render('{{ 1 }}');
        BladeSandbox::configure(['policies' => ['default' => ['functions' => ['strtoupper']]]]);

        $this->assertSame('A', BladeSandbox::render('{{ strtoupper("a") }}'));
    }

    public function testDefinePolicyWithAnArray(): void
    {
        BladeSandbox::definePolicy('cms', ['functions' => ['strtoupper']]);

        $this->assertTrue(BladeSandbox::hasPolicy('cms'));
        $this->assertSame('A', BladeSandbox::policy('cms')->render('{{ strtoupper("a") }}'));
    }

    public function testDefinePolicyWithAClosureWinsOverConfig(): void
    {
        config()->set('blade-sandbox.policies.cms', ['functions' => ['strtolower']]);
        BladeSandbox::definePolicy('cms', fn (Sandbox $sandbox) => $sandbox->allowFunction('strtoupper'));

        $this->assertSame('A', BladeSandbox::policy('cms')->render('{{ strtoupper("a") }}'));

        $this->expectException(ForbiddenFunctionException::class);
        BladeSandbox::policy('cms')->render('{{ strtolower("A") }}');
    }

    public function testExtendPolicyAddsToAConfigPolicyAndDenyRulesKeepWinning(): void
    {
        config()->set('blade-sandbox.policies.cms', ['functions' => ['strtoupper'], 'deny_functions' => ['ucfirst']]);
        BladeSandbox::extendPolicy('cms', ['functions' => ['strtolower', 'ucfirst']], fn (Sandbox $sandbox) => $sandbox->allowFunction('trim'));

        $sandbox = BladeSandbox::policy('cms');
        $this->assertSame('A|a|b', $sandbox->render('{{ strtoupper("a") }}|{{ strtolower("A") }}|{{ trim(" b ") }}'));

        $this->expectException(ForbiddenFunctionException::class);
        $sandbox->render('{{ ucfirst("x") }}');
    }

    public function testCodePoliciesCanExtendConfigPolicies(): void
    {
        config()->set('blade-sandbox.policies.base', ['functions' => ['strtoupper']]);
        BladeSandbox::definePolicy('cms', ['extends' => 'base', 'functions' => ['strtolower']]);

        $this->assertSame('A|a', BladeSandbox::policy('cms')->render('{{ strtoupper("a") }}|{{ strtolower("A") }}'));
    }

    public function testUnknownPolicyStillFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BladeSandbox::policy('missing');
    }

    public function testManagerLevelRenderAndRenderView(): void
    {
        $manager = $this->app->make(SandboxManager::class);

        $this->assertSame('<h1>Shaun</h1>', $manager->render('<h1>{{ $name }}</h1>', ['name' => 'Shaun']));
        $this->assertSame("<header>ok</header>\n", $manager->policy('default')->allowView('deployer::partials.header')->renderView('deployer::partials.header', ['deployment' => (object) ['status' => 'ok']]));
    }

    public function testConfiguringCacheOrLoadersResetsCachedState(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->sources();
        $manager->extendLoader('array', static fn (array $config): \SytxLabs\BladeSandbox\Contracts\TemplateLoader => new \SytxLabs\BladeSandbox\Templates\ArrayTemplateLoader((array) $config['templates']));

        $manager->configure(['cache' => ['driver' => 'file']]);
        $manager->configure(['loaders' => ['arr' => ['driver' => 'array', 'templates' => ['x' => 'y']]]]);

        $this->assertSame('y', $manager->make()->allowViewNamespace('arr')->renderView('arr::x'));
    }
}
