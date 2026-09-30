<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Livewire;

use Closure;
use ReflectionMethod;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Livewire\Livewire3Adapter;
use SytxLabs\BladeSandbox\Livewire\NullLivewireAdapter;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class LivewireAdapterTest extends TestCase
{
    public function testNullLivewireAdapterRefusesToMount(): void
    {
        $adapter = new NullLivewireAdapter();
        $adapter->registerRequestGuard(static fn () => null, static fn () => null);

        $this->expectException(SandboxException::class);
        $adapter->mount('component', []);
    }

    public function testLivewire3AdapterDescribesItself(): void
    {
        $adapter = new Livewire3Adapter();
        $registrar = new ReflectionMethod($adapter, 'hookRegistrar');

        $this->assertSame(3, $adapter->majorVersion());
        $this->assertInstanceOf(Closure::class, $registrar->invoke($adapter));
    }
}
