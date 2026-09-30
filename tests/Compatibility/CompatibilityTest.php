<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Compatibility;

use Composer\InstalledVersions;
use Livewire\Livewire;
use SytxLabs\BladeSandbox\Compatibility\ComponentResolver;
use SytxLabs\BladeSandbox\Livewire\Livewire3Adapter;
use SytxLabs\BladeSandbox\Livewire\Livewire4Adapter;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapterFactory;
use SytxLabs\BladeSandbox\Livewire\NullLivewireAdapter;
use SytxLabs\BladeSandbox\Livewire\WireDirectiveKind;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class CompatibilityTest extends TestCase
{
    public function testSupportedLaravelVersion(): void
    {
        $major = (int) explode('.', $this->app->version())[0];

        $this->assertContains($major, [10, 11, 12, 13]);
    }

    public function testTheLivewireAdapterMatchesTheInstalledVersion(): void
    {
        $adapter = $this->app->make(LivewireAdapter::class);

        if (! class_exists(Livewire::class)) {
            $this->assertInstanceOf(NullLivewireAdapter::class, $adapter);
            $this->assertFalse($adapter->isInstalled());

            return;
        }

        $major = (int) ltrim((string) InstalledVersions::getVersion('livewire/livewire'), 'v');
        $this->assertSame($major, $adapter->majorVersion());
        $this->assertInstanceOf($major >= 4 ? Livewire4Adapter::class : Livewire3Adapter::class, $adapter);
    }

    public function testAdapterDirectiveCatalogs(): void
    {
        foreach ([new Livewire3Adapter(), new Livewire4Adapter(), new NullLivewireAdapter()] as $adapter) {
            $this->assertSame(WireDirectiveKind::Model, $adapter->directiveKind('model'));
            $this->assertSame(WireDirectiveKind::Action, $adapter->directiveKind('click'));
            $this->assertSame(WireDirectiveKind::Action, $adapter->directiveKind('keydown'));
            $this->assertSame(WireDirectiveKind::Plain, $adapter->directiveKind('loading'));
            $this->assertSame(WireDirectiveKind::Internal, $adapter->directiveKind('snapshot'));
            $this->assertSame(WireDirectiveKind::Expression, $adapter->directiveKind('show'));
        }

        $this->assertSame(WireDirectiveKind::Plain, (new Livewire4Adapter())->directiveKind('ref'));
        $this->assertSame(WireDirectiveKind::Expression, (new Livewire4Adapter())->directiveKind('bind'));
        $this->assertSame(WireDirectiveKind::Action, (new Livewire3Adapter())->directiveKind('ref'));
        $this->assertInstanceOf(Livewire3Adapter::class, LivewireAdapterFactory::forVersion('v3.6.1'));
        $this->assertInstanceOf(Livewire4Adapter::class, LivewireAdapterFactory::forVersion('4.0.0'));
        $this->assertInstanceOf(NullLivewireAdapter::class, LivewireAdapterFactory::forVersion('2.12.0'));
    }

    public function testViewFinderAdapterUsesLaravelNamespaces(): void
    {
        $finder = $this->app->make(SandboxManager::class)->finder();

        $this->assertStringEndsWith('deployer/test.blade.php', $finder->find('deployer::test'));
        $this->assertTrue($finder->exists('deployer::emails.deploy'));
        $this->assertFalse($finder->exists('deployer::missing'));
        $this->assertNotEmpty($finder->namespaceDirectories('deployer'));
        $this->assertContains('blade.php', $finder->extensions());
    }

    public function testComponentResolutionUsesLaravelRules(): void
    {
        $resolver = new ComponentResolver($this->app->make('blade.compiler'));

        $this->assertSame(['type' => 'view', 'target' => 'deployer::components.button'], $resolver->resolve('deployer::button'));
    }
}
