<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Livewire;

use Closure;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenLivewireActionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenLivewireDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\Livewire\ActionsComponent;
use SytxLabs\BladeSandbox\Tests\Fixtures\Livewire\EvilTemplateComponent;
use SytxLabs\BladeSandbox\Tests\Fixtures\Livewire\TestComponent;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class LivewireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->livewireInstalled()) {
            $this->markTestSkipped('Livewire is not installed.');
        }

        ActionsComponent::$deleted = false;
    }

    public function testLivewireViewFromSandboxedNamespaceWithDto(): void
    {
        BladeSandbox::sandboxNamespace('deployer', BladeSandbox::make()->allowView('deployer::livewire')->allowDto(TestDTO::class));

        Livewire::test(TestComponent::class)
            ->assertSee('<h1>api</h1>', false)
            ->assertSee('api: running');
    }

    public function testLivewireViewDeniesDtoThatIsNotAllowed(): void
    {
        BladeSandbox::sandboxNamespace('deployer', BladeSandbox::make()->allowView('deployer::livewire'));

        $this->assertViolation(SecurityViolationException::class, static fn () => Livewire::test(TestComponent::class));
    }

    public function testDtoSurvivesLivewireRoundTrips(): void
    {
        BladeSandbox::sandboxNamespace('deployer', BladeSandbox::make()->allowView('deployer::livewire')->allowDto(TestDTO::class));

        Livewire::test(TestComponent::class)
            ->call('$refresh')
            ->assertSee('api: running');

        $payload = (new TestDTO(['name' => 'a', 'status' => 'b']))->toLivewire();
        $this->assertSame('a: b', TestDTO::fromLivewire($payload)->getLabel());
    }

    public function testAllowedWireDirectivesRenderAndAllowedActionsRun(): void
    {
        Livewire::test(ActionsComponent::class)
            ->assertSee('wire:click="save"', false)
            ->call('save')
            ->assertSet('saved', 1)
            ->set('title', 'new')
            ->assertSet('title', 'new')
            ->dispatch('refresh-title')
            ->assertSet('title', 'refreshed');
    }

    public function testServerSideGuardBlocksActionsNotInThePolicy(): void
    {
        $this->expectException(ForbiddenLivewireActionException::class);

        try {
            // A browser can send any method name, whatever the template rendered.
            Livewire::test(ActionsComponent::class)->call('deleteEverything');
        } finally {
            $this->assertFalse(ActionsComponent::$deleted);
        }
    }

    public function testServerSideGuardBlocksPropertyUpdatesNotInThePolicy(): void
    {
        $this->expectException(ForbiddenLivewireActionException::class);

        Livewire::test(ActionsComponent::class)->set('secret', 'stolen');
    }

    public function testServerSideGuardBlocksMagicSetAndToggle(): void
    {
        foreach ([['$set', 'secret', 'x'], ['$toggle', 'secret']] as $call) {
            try {
                Livewire::test(ActionsComponent::class)->call(...$call);
                $this->fail('magic action must be denied');
            } catch (ForbiddenLivewireActionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testServerSideGuardBlocksEventsNotInThePolicy(): void
    {
        $this->expectException(ForbiddenLivewireActionException::class);

        try {
            Livewire::test(ActionsComponent::class)->dispatch('nuke');
        } finally {
            $this->assertFalse(ActionsComponent::$deleted);
        }
    }

    public function testTemplateCannotEmitForbiddenActions(): void
    {
        $this->assertViolation(ForbiddenLivewireActionException::class, static fn () => Livewire::test(EvilTemplateComponent::class));
        $this->assertFalse(ActionsComponent::$deleted);
    }

    #[DataProvider('templatePayloads')]
    public function testTemplateLivewirePolicy(string $template, string $exception): void
    {
        $sandbox = BladeSandbox::make()
            ->allowLivewireDirective('click')
            ->allowLivewireDirective('model')
            ->allowLivewireDirective('show')
            ->allowLivewireAction('save')
            ->allowLivewireModel('title')
            ->allowComponent('deployer::button');

        try {
            $sandbox->render($template, ['action' => 'deleteEverything', 'attr' => 'wire:click=deleteEverything']);
            $this->fail('Template must be rejected: '.$template);
        } catch (SecurityViolationException $violation) {
            $this->assertInstanceOf($exception, $violation, $violation->getMessage());
        }
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function templatePayloads(): iterable
    {
        yield 'unknown action' => ['<button wire:click="deleteEverything">x</button>', ForbiddenLivewireActionException::class];
        yield 'directive not allowed' => ['<form wire:submit="save">x</form>', ForbiddenLivewireDirectiveException::class];
        yield 'dynamic action' => ['<button wire:click="{{ $action }}">x</button>', ForbiddenLivewireDirectiveException::class];
        yield 'js expression action' => ['<button wire:click="$wire.deleteEverything()">x</button>', ForbiddenLivewireActionException::class];
        yield 'chained actions' => ['<button wire:click="save; deleteEverything">x</button>', ForbiddenLivewireActionException::class];
        yield 'dynamic parameters' => ['<button wire:click="save($event)">x</button>', ForbiddenLivewireActionException::class];
        yield 'magic set on forbidden property' => ["<button wire:click=\"\$set('secret', 1)\">x</button>", ForbiddenLivewireActionException::class];
        yield 'dispatch forbidden event' => ["<button wire:click=\"\$dispatch('nuke')\">x</button>", ForbiddenLivewireActionException::class];
        yield 'parent call' => ['<button wire:click="$parent.deleteEverything()">x</button>', ForbiddenLivewireActionException::class];
        yield 'model of forbidden property' => ['<input wire:model="secret">', ForbiddenLivewireActionException::class];
        yield 'internal directive' => ['<div wire:snapshot="{}"></div>', ForbiddenLivewireDirectiveException::class];
        yield 'wire:show evaluates js' => ['<div wire:show="$wire.deleteEverything()"></div>', ForbiddenLivewireDirectiveException::class];
        yield 'alpine bridge' => ['<div x-on:click="$wire.deleteEverything()"></div>', ForbiddenLivewireDirectiveException::class];
        yield 'alpine shorthand' => ['<div @click="$wire.deleteEverything()"></div>', ForbiddenLivewireDirectiveException::class];
        yield 'inline handler' => ['<div onclick="Livewire.first().deleteEverything()"></div>', ForbiddenLivewireDirectiveException::class];
        yield 'script tag' => ['<script>Livewire.first().call("deleteEverything")</script>', ForbiddenLivewireDirectiveException::class];
        yield 'attribute injected by echo' => ['<button {{ $attr }}>x</button>', ForbiddenLivewireActionException::class];
        yield 'attribute on component tag' => ['<x-deployer::button wire:click="deleteEverything" />', ForbiddenLivewireActionException::class];
        yield 'bound wire attribute on component' => ['<x-deployer::button :wire:click="$action" />', ForbiddenLivewireDirectiveException::class];
        yield 'nested livewire component' => ['<livewire:actions-component />', ForbiddenLivewireDirectiveException::class];
    }

    public function testAllowedTemplateFeatures(): void
    {
        $sandbox = BladeSandbox::make()
            ->allowLivewireDirective('click')->allowLivewireDirective('model')->allowLivewireDirective('loading')->allowLivewireDirective('target')->allowLivewireDirective('key')
            ->allowLivewireAction('save')->allowLivewireModel('title')->allowLivewireEvent('saved');

        $html = $sandbox->render(<<<'BLADE'
<button wire:click.prevent="save(1, 'draft')">Save</button>
<button wire:click="$dispatch('saved')">Notify</button>
<input wire:model.live.debounce.500ms="title">
<span wire:loading wire:target="save">...</span>
<button wire:key="{{ $id }}">x</button>
BLADE, ['id' => 7]);

        $this->assertStringContainsString('wire:click.prevent="save(1, \'draft\')"', $html);
        $this->assertStringContainsString('wire:key="7"', $html);
    }

    public function testEmptyWireDirectiveNameIsRejected(): void
    {
        $this->expectException(ForbiddenLivewireDirectiveException::class);
        BladeSandbox::render('<div wire:></div>');
    }

    public function testPlainWireDirectivesAreAllowedOnceOptedIn(): void
    {
        $html = BladeSandbox::make()->allowLivewireDirective('loading')->render('<div wire:loading></div>');
        $this->assertStringContainsString('wire:loading', $html);
    }

    public function testWireModelRejectsAnInvalidPropertyPath(): void
    {
        $this->expectException(ForbiddenLivewireDirectiveException::class);
        BladeSandbox::make()->allowLivewireDirective('model')->render('<input wire:model="foo!bar">');
    }

    public function testWirePollWithoutAValueImplicitlyChecksRefresh(): void
    {
        $html = BladeSandbox::make()->allowLivewireDirective('poll')->allowLivewireAction('$refresh')->render('<div wire:poll></div>');
        $this->assertStringContainsString('wire:poll', $html);
    }

    public function testBareActionDirectiveWithNoValueNeedsNoActionPermission(): void
    {
        $html = BladeSandbox::make()->allowLivewireDirective('click')->render('<button wire:click></button>');
        $this->assertStringContainsString('wire:click', $html);
    }

    public function testMagicSetWithANonStringPropertyIsRejected(): void
    {
        $this->expectException(ForbiddenLivewireActionException::class);
        BladeSandbox::make()->allowLivewireDirective('click')->render('<button wire:click="$set(1, 2)"></button>');
    }

    public function testExpressionWireDirectiveIsAcceptedOnceAlpineIsEnabled(): void
    {
        $html = BladeSandbox::make()->allowAlpine()->allowLivewireDirective('show')->render('<div wire:show="x"></div>');
        $this->assertStringContainsString('wire:show', $html);
    }

    public function testMagicSetWithAnAllowedPropertyIsAccepted(): void
    {
        $html = BladeSandbox::make()->allowLivewireDirective('click')->allowLivewireModel('title')
            ->render('<button wire:click="$set(\'title\', \'x\')"></button>');
        $this->assertStringContainsString('wire:click', $html);
    }

    public function testUploadActionsAreCheckedAgainstTheModelPolicy(): void
    {
        $sandbox = BladeSandbox::make()->allowLivewireDirective('click');

        try {
            $sandbox->render('<button wire:click="_startUpload(\'file\')"></button>');
            $this->fail('an upload action for a disallowed model must be rejected');
        } catch (ForbiddenLivewireActionException) {
            $this->addToAssertionCount(1);
        }

        $html = $sandbox->allowLivewireModel('file')->render('<button wire:click="_startUpload(\'file\')"></button>');
        $this->assertStringContainsString('wire:click', $html);
    }

    public function testLazyLoadActionsAreAlwaysAccepted(): void
    {
        $html = BladeSandbox::make()->allowLivewireDirective('click')->render('<button wire:click="__lazyLoad()"></button>');
        $this->assertStringContainsString('wire:click', $html);
    }

    public function testMagicDispatchWithAnAllowedEventIsAccepted(): void
    {
        $html = BladeSandbox::make()->allowLivewireDirective('click')->allowLivewireEvent('saved')->render('<button wire:click="$dispatch(\'saved\')"></button>');
        $this->assertStringContainsString('wire:click', $html);
    }

    public function testAlpineCanBeEnabledExplicitly(): void
    {
        $html = BladeSandbox::make()->allowAlpine()->render('<div x-data="{ open: false }" @click="open = !open"></div>');

        $this->assertStringContainsString('x-data', $html);
    }

    /**
     * Rendering errors inside Livewire's own Blade views are wrapped in a ViewException.
     *
     * @param class-string<Throwable> $expected
     */
    private function assertViolation(string $expected, Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected '.$expected);
        } catch (Throwable $thrown) {
            $current = $thrown;
            while ($current !== null && ! $current instanceof $expected) {
                $current = $current->getPrevious();
            }
            $this->assertInstanceOf($expected, $current, get_class($thrown).': '.$thrown->getMessage());
        }
    }

    public function testUploadActionsNeedAPropertyName(): void
    {
        $this->expectException(ForbiddenLivewireActionException::class);
        Livewire::test(ActionsComponent::class)->call('_startUpload');
    }

    public function testAllowedComponentsCanBeMountedFromATemplate(): void
    {
        Livewire::component('actions-component', ActionsComponent::class);

        $html = $this->sandbox()->allowDirective('livewire')->allowLivewireComponent('actions-component')->render("@livewire('actions-component')");

        $this->assertStringContainsString('wire:id', $html);
    }
}
