<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayObject;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use stdClass;
use SytxLabs\BladeSandbox\Events\SandboxLimitExceeded;
use SytxLabs\BladeSandbox\Events\SecurityViolationDetected;
use SytxLabs\BladeSandbox\Events\TemplateRendered;
use SytxLabs\BladeSandbox\Events\TemplateRenderFailed;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Testing\InteractsWithBladeSandbox;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class EventsAndTestingTest extends TestCase
{
    use InteractsWithBladeSandbox;

    public function testRenderedEvent(): void
    {
        Event::fake([TemplateRendered::class]);

        $this->sandbox()->allowView('deployer::partials.header')->renderView('deployer::partials.header', ['deployment' => (object) ['status' => 'ok']]);

        Event::assertDispatched(TemplateRendered::class, static fn (TemplateRendered $event): bool => $event->view === 'deployer::partials.header'
            && $event->bytes === strlen("<header>ok</header>\n") && ! $event->fromCache);
    }

    public function testViolationAndFailureEvents(): void
    {
        Event::fake([SecurityViolationDetected::class, TemplateRenderFailed::class]);

        try {
            $this->sandbox()->render('{{ exec("id") }}');
        } catch (ForbiddenFunctionException) {
        }

        Event::assertDispatched(SecurityViolationDetected::class, static fn (SecurityViolationDetected $event): bool => $event->violation instanceof ForbiddenFunctionException);
        Event::assertDispatched(TemplateRenderFailed::class, static fn (TemplateRenderFailed $event): bool => $event->fallback === null);
    }

    public function testLimitEvent(): void
    {
        Event::fake([SandboxLimitExceeded::class]);

        try {
            $this->sandbox()->maxIterations(5)->render('@for($i = 0; $i < 100; $i++) x @endfor');
        } catch (Throwable) {
        }

        Event::assertDispatched(SandboxLimitExceeded::class);
    }

    public function testFakeRecordsAndAsserts(): void
    {
        BladeSandbox::fake();

        $this->sandbox()->render('ok');
        try {
            $this->sandbox()->render('{{ $o->secret() }}', ['o' => new ArrayObject()]);
        } catch (ForbiddenMethodException) {
        }

        BladeSandbox::assertRendered('inline:*');
        BladeSandbox::assertViolation(ForbiddenMethodException::class);
        BladeSandbox::assertNotRendered('deployer::test');

        $this->expectException(AssertionFailedError::class);
        BladeSandbox::assertNoViolations();
    }

    public function testFakeCountsAndNothingRendered(): void
    {
        $recorder = BladeSandbox::fake();
        $recorder->assertNothingRendered();

        $this->sandbox()->render('a');
        $this->sandbox()->render('a');

        $recorder->assertRendered('inline:'.substr(hash('sha256', 'a'), 0, 12), 2);
        $this->assertCount(2, $recorder->events());
    }

    public function testFallbackAssertion(): void
    {
        $this->app['config']->set('blade-sandbox.fallback_report', false);
        BladeSandbox::fake();

        $this->sandbox()->renderFallback('empty')->render('{{ exec("x") }}');

        BladeSandbox::assertFallbackUsed();
    }

    public function testAssertionsNeedFake(): void
    {
        $this->expectException(RuntimeException::class);

        BladeSandbox::assertRendered('x');
    }

    public function testInteractsTrait(): void
    {
        $sandbox = $this->sandbox()->allowView('deployer::partials.footer')->allowMethod(\SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO::class, 'getLabel');

        $this->assertSandboxRenders($sandbox, '{{ $a }}', '1', ['a' => 1]);
        $this->assertSandboxRendersView($sandbox, 'deployer::partials.footer', "<footer>api: running</footer>\n", ['deployment' => new \SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO()]);
        $this->assertSandboxDenies($sandbox, '{{ exec("id") }}', ForbiddenFunctionException::class);
        $this->assertSandboxTemplateValid($sandbox, '{{ $a }}');
        $this->assertSandboxTemplateInvalid($sandbox, '{{ exec("id") }}');

        $this->expectException(AssertionFailedError::class);
        $this->assertSandboxDenies($sandbox, '{{ 1 }}', ForbiddenFunctionException::class);
    }

    public function testLimitAndFailureAndNothingRenderedAssertionsOnTheRecorder(): void
    {
        $recorder = BladeSandbox::fake();

        try {
            $this->sandbox()->maxIterations(5)->render('@for($i = 0; $i < 100; $i++) x @endfor');
        } catch (Throwable) {
        }
        $recorder->assertLimitExceeded();

        try {
            $this->sandbox()->renderView('deployer::missing');
        } catch (Throwable) {
        }
        $recorder->assertFailed('deployer::missing');
    }

    public function testAssertNoViolationsPassesWhenNothingWentWrong(): void
    {
        $recorder = BladeSandbox::fake();
        $this->sandbox()->render('{{ 1 }}');

        $this->assertSame($recorder, $recorder->assertNoViolations());
    }

    public function testFacadeAssertNothingRendered(): void
    {
        BladeSandbox::fake();

        BladeSandbox::assertNothingRendered();
    }

    public function testFacadeHelpersNeedARealManager(): void
    {
        BladeSandbox::swap(new stdClass());

        $this->expectException(RuntimeException::class);
        BladeSandbox::fake();
    }
}
