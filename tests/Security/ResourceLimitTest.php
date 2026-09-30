<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Runtime\SandboxContext;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class ResourceLimitTest extends TestCase
{
    private int $obLevel = 0;

    #[DataProvider('infiniteLoops')]
    public function testInfiniteLoopsAreStopped(string $template): void
    {
        $started = microtime(true);

        try {
            $this->sandbox()->maxIterations(10_000)->timeout(2_000)->render($template, [
                'big' => range(1, 1_000),
                'endless' => LazyCollection::make(static function () {
                    $i = 0;
                    while (true) {
                        yield $i++;
                    }
                }),
            ]);
            $this->fail('Loop was not stopped: '.$template);
        } catch (SandboxLimitExceededException $exception) {
            $this->assertStringContainsString('possible infinite loop', $exception->getMessage());
        }

        $this->assertLessThan(2.5, microtime(true) - $started);
        $this->assertSame(0, ob_get_level() - $this->obLevel, 'output buffers must be cleaned up');
    }

    /** @return iterable<string, array{string}> */
    public static function infiniteLoops(): iterable
    {
        yield 'while true' => ['@while(true) @endwhile'];
        yield 'while true with output' => ['@while(1) x @endwhile'];
        yield 'for without condition' => ['@for($i = 0; ; $i++) @endfor'];
        yield 'for that never ends' => ['@for($i = 0; $i >= 0; $i++) {{ $i }} @endfor'];
        yield 'while with continue' => ['@var($i = 0) @while(true) @var($i++) @continue($i > 0) @endwhile'];
        yield 'nested loops' => ['@foreach($big as $a) @foreach($big as $b) @foreach($big as $c) . @endforeach @endforeach @endforeach'];
        yield 'infinite generator' => ['@foreach($endless as $i) @endforeach'];
        yield 'forelse over infinite generator' => ['@forelse($endless as $i) @empty none @endforelse'];
        yield 'spread of infinite generator' => ['{{ count([...$endless]) }}'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->obLevel = ob_get_level();
    }

    public function testTimeoutStopsLoopsBelowTheIterationLimit(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('time limit of 50 ms');

        $this->sandbox()->maxIterations(0)->timeout(50)->render('@while(true) @endwhile');
    }

    public function testDefaultLimitsApplyWithoutConfiguration(): void
    {
        $limits = $this->sandbox()->limits();
        $this->assertSame(1_000_000, $limits->maxIterations);
        $this->assertSame(10_000, $limits->timeoutMs);

        $this->expectException(SandboxLimitExceededException::class);
        $this->sandbox()->render('@while(true) @endwhile');
    }

    public function testMemoryLimit(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('memory limit');

        // Every iteration doubles the string; checked at each iteration.
        $this->sandbox()->maxMemory(8 * 1024 * 1024)->render("@var(\$s = 'x') @while(true) @var(\$s .= \$s) @endwhile");
    }

    public function testOutputLimitIsCheckedWhileLooping(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('output exceeds');

        $this->sandbox()->maxIterations(0)->timeout(0)->maxOutputBytes(10_000)->render('@while(true) xxxxxxxxxx @endwhile');
    }

    public function testLimitsCountAcrossIncludesAndLegitLoopsWork(): void
    {
        $this->assertSame(1000, substr_count($this->sandbox()->maxIterations(1_000)->render('@foreach($items as $i).@endforeach', ['items' => range(1, 1000)]), '.'));

        $this->expectException(SandboxLimitExceededException::class);
        $this->sandbox()->maxIterations(1_000)->render('@foreach($items as $i).@endforeach', ['items' => range(1, 1001)]);
    }

    public function testLimitsFromConfig(): void
    {
        config()->set('blade-sandbox.max_iterations', 5);
        $manager = new SandboxManager($this->app, config('blade-sandbox'), $this->app->make(LivewireAdapter::class));

        $this->assertSame(5, $manager->make()->limits()->maxIterations);
        $this->expectException(SandboxLimitExceededException::class);
        $manager->make()->render('@for($i = 0; $i < 6; $i++) @endfor');
    }

    public function testSandboxContextCountsIterationsAndDepth(): void
    {
        $context = new SandboxContext();
        $context->tick();
        $context->tick();
        $context->tick(false);

        $this->assertSame(2, $context->iterations());
        $this->assertSame(0, $context->depth());
    }

    public function testLoopStateMustExistBeforeItIsIncremented(): void
    {
        $this->expectException(SandboxException::class);
        (new SandboxContext())->incrementLoop();
    }
}
