<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use stdClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Runtime\SandboxLimits;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Sanitizers\HtmlSanitizer;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class IsolatedRenderingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('blade-sandbox.isolation.command', [PHP_BINARY, __DIR__.'/../Fixtures/isolated-render.php']);
        $app['config']->set('blade-sandbox.fallback_report', false);
    }

    public function testRendersInAChildProcess(): void
    {
        $pid = $this->sandbox()->allowFunction('getmypid')->isolated(20)->render('{{ getmypid() }}');

        $this->assertMatchesRegularExpression('/\A\d+\z/', $pid);
        $this->assertNotSame((string) getmypid(), $pid);
    }

    public function testViewsAndData(): void
    {
        // Data is serialized into the child process (stdClass properties are a value-object default).
        $html = $this->sandbox()->allowView('deployer::emails.deploy')->isolated(20)
            ->renderView('deployer::emails.deploy', ['deployment' => (object) ['name' => 'api']]);

        $this->assertSame("Deploy api\n", $html);
    }

    public function testViolationsAreRethrownInTheParent(): void
    {
        try {
            $this->sandbox()->isolated(20)->render("line\n{{ exec('id') }}");
            $this->fail('The violation must be rethrown.');
        } catch (ForbiddenFunctionException $exception) {
            $this->assertSame('function', $exception->capability());
            $this->assertSame('exec()', $exception->subject());
        }
    }

    public function testTheProcessIsKilledAfterTheTimeout(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('time limit');

        // Cooperative limits off: only the process timeout can stop this loop.
        $this->sandbox()->maxIterations(0)->timeout(0)->isolated(2)->render('@for($i = 0; ; $i++) @endfor');
    }

    public function testTheMemoryLimitIsEnforced(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('memory limit');

        $this->sandbox()->maxIterations(0)->timeout(0)->isolated(20, '32M')
            ->render('@var($s = "xxxxxxxxxx") @for($i = 0; ; $i++) @var($s .= $s) @endfor');
    }

    public function testSanitizerAndFallbackRunInTheParent(): void
    {
        $sandbox = $this->sandbox()->isolated(20);

        $this->assertSame('<p>ok</p>', $sandbox->sanitizeWith(new HtmlSanitizer())->render('<p style="color:red">ok</p>'));
        $this->assertSame('', $this->sandbox()->isolated(20)->renderFallback('empty')->render('{{ exec("id") }}'));
    }

    public function testClosuresCannotBeTransferred(): void
    {
        try {
            $this->sandbox()->directive('x', static fn (): string => 'y')->isolated(20)->render('a');
            $this->fail('A sandbox with closures cannot be transferred.');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('closures', $exception->getMessage());
        }

        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('serializable template data');
        $this->sandbox()->isolated(20)->render('a', ['callback' => static fn () => 1]);
    }

    public function testStateOnlyAcceptsItsOwnClasses(): void
    {
        $state = $this->sandbox()->maxIterations(5)->isolationState(true);
        $manager = $this->app->make(\SytxLabs\BladeSandbox\SandboxManager::class);

        $restored = Sandbox::fromIsolationState($manager, $state);
        $this->assertSame(5, $restored->limits()->maxIterations);

        // Foreign objects in the limits / integrity state are not instantiated: defaults apply.
        $foreign = Sandbox::fromIsolationState($manager, ['limits' => serialize(new stdClass()), 'integrity' => serialize(new stdClass())] + $state);
        $this->assertSame((new SandboxLimits())->maxIterations, $foreign->limits()->maxIterations);

        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Invalid isolated render payload');
        Sandbox::fromIsolationState($manager, ['builder' => serialize(new stdClass())] + $state);
    }

    public function testQueuedOutputStateIsTypeChecked(): void
    {
        $state = $this->sandbox()->sanitizeWith(new HtmlSanitizer())->isolationState(true);
        $manager = $this->app->make(\SytxLabs\BladeSandbox\SandboxManager::class);

        $this->assertInstanceOf(Sandbox::class, Sandbox::fromIsolationState($manager, $state));

        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Invalid isolated render payload');
        Sandbox::fromIsolationState($manager, ['output' => serialize(['sanitizers' => [['pattern' => null, 'sanitizer' => new stdClass()]], 'fallback' => false])] + $state);
    }

    public function testWithoutIsolationRunsInTheSameProcess(): void
    {
        $pid = $this->sandbox()->allowFunction('getmypid')->isolated(20)->withoutIsolation()->render('{{ getmypid() }}');

        $this->assertSame((string) getmypid(), $pid);
    }

    public function testStateWithANonArraySanitizersOrAnInvalidFallbackTypeIsRejected(): void
    {
        $state = $this->sandbox()->isolationState(true);
        $manager = $this->app->make(\SytxLabs\BladeSandbox\SandboxManager::class);

        try {
            Sandbox::fromIsolationState($manager, ['output' => serialize(['sanitizers' => 'not-an-array', 'fallback' => false])] + $state);
            $this->fail('non-array sanitizers must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('Invalid isolated render payload', $exception->getMessage());
        }

        try {
            Sandbox::fromIsolationState($manager, ['output' => serialize(['sanitizers' => [], 'fallback' => 42])] + $state);
            $this->fail('an invalid fallback type must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('Invalid isolated render payload', $exception->getMessage());
        }
    }

    public function testStateRestoresTheLogChannelAndLevel(): void
    {
        $state = $this->sandbox()->logUsing('security', 'error')->isolationState();
        $manager = $this->app->make(\SytxLabs\BladeSandbox\SandboxManager::class);

        $restored = Sandbox::fromIsolationState($manager, $state);

        $this->assertSame('security', $restored->isolationState()['log_channel']);
    }

    public function testStateFromAnUnmodifiedNamedPolicyRebuildsFromTheOrigin(): void
    {
        \SytxLabs\BladeSandbox\Facades\BladeSandbox::definePolicy('closure-origin-policy', static fn (Sandbox $sandbox): Sandbox => $sandbox->directive('greet', static fn (): string => 'hi'));
        $manager = $this->app->make(\SytxLabs\BladeSandbox\SandboxManager::class);

        $state = $manager->policy('closure-origin-policy')->maxIterations(5)->isolationState();
        $this->assertNull($state['builder']);
        $this->assertSame('closure-origin-policy', $state['origin']);

        $restored = Sandbox::fromIsolationState($manager, $state);
        $this->assertSame(5, $restored->limits()->maxIterations);
        $this->assertSame('hi', $restored->render('@greet'));
    }
}
