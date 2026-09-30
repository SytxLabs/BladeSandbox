<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use SytxLabs\BladeSandbox\Events\SecurityViolationDetected;
use SytxLabs\BladeSandbox\Events\TemplateRenderFailed;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Fallback\BladeStripper;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Sanitizers\HtmlSanitizer;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class FallbackTest extends TestCase
{
    private const TEMPLATE = "<h1>{{ \$title }}</h1>\n{{-- note --}}\n@if(\$a)<p>{{ system('id') }}</p>@endif\n<x-alert type=\"x\">Body</x-alert>";

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('blade-sandbox.fallback_report', false);
    }

    public function testWithoutFallbackTheExceptionIsThrown(): void
    {
        $this->expectException(ForbiddenFunctionException::class);

        $this->sandbox()->render(self::TEMPLATE, ['title' => 'T', 'a' => true]);
    }

    public function testSourceModeReturnsTheUnrenderedTemplate(): void
    {
        $html = $this->sandbox()->renderFallback()->render(self::TEMPLATE, ['title' => 'T', 'a' => true]);

        $this->assertSame(self::TEMPLATE, $html);
    }

    public function testStripModeRemovesBladeSyntax(): void
    {
        $html = $this->sandbox()->renderFallback('strip')->render(self::TEMPLATE, ['title' => 'T', 'a' => true]);

        $this->assertSame("<h1></h1>\n\n<p></p>\nBody", $html);
    }

    public function testEscapedAndEmptyModes(): void
    {
        $this->assertSame(
            '<pre class="blade-sandbox-fallback">&lt;b&gt;{{ system(&#039;id&#039;) }}&lt;/b&gt;</pre>',
            $this->sandbox()->renderFallback('escaped')->render("<b>{{ system('id') }}</b>"),
        );
        $this->assertSame('', $this->sandbox()->renderFallback('empty')->render("{{ system('id') }}"));
    }

    public function testSuccessfulRendersAreUnaffected(): void
    {
        $this->assertSame('<h1>ok</h1>', $this->sandbox()->renderFallback()->render('<h1>{{ $t }}</h1>', ['t' => 'ok']));
    }

    public function testFallbackNeverEvaluatesPhp(): void
    {
        $html = $this->sandbox()->renderFallback()->render("a<?php echo 'x'; ?>b@php echo 1; @endphp{{ exec('id') }}");

        $this->assertStringNotContainsString('<?php', $html);
        $this->assertStringNotContainsString('@php', $html);
        $this->assertStringStartsWith('ab', $html);
    }

    public function testJavascriptFrameworkAttributesAreRemovedUnlessAllowed(): void
    {
        $template = '<div x-data="{ open: false }" @click="open = true" :class="c" wire:click="delete" class="box">{{ system("id") }}</div>';

        $html = $this->sandbox()->renderFallback()->render($template);
        $this->assertSame('<div class="box">{{ system("id") }}</div>', $html);

        if (! $this->livewireInstalled()) {
            $withAlpine = $this->sandbox()->allowAlpine()->renderFallback()->render($template);
            $this->assertStringContainsString('x-data=', $withAlpine);
            $this->assertStringNotContainsString('wire:click', $withAlpine);
        }
    }

    public function testTheOutputSanitizerRunsOnFallbackOutput(): void
    {
        $html = $this->sandbox()
            ->renderFallback()
            ->sanitizeWith(new HtmlSanitizer())
            ->render('<p onclick="x()">hi</p><script>alert(1)</script>{{ exec("id") }}');

        $this->assertSame('<p>hi</p>{{ exec("id") }}', $html);
    }

    public function testRejectingSanitizerFallsBackToEscapedOutput(): void
    {
        $html = $this->sandbox()
            ->renderFallback()
            ->sanitizeWith(new HtmlSanitizer(rejectUnsafe: true))
            ->render('<script>alert(1)</script>{{ exec("id") }}');

        $this->assertStringStartsWith('<pre class="blade-sandbox-fallback">&lt;script&gt;', $html);
    }

    public function testViewFallbackUsesTheViewSource(): void
    {
        $html = $this->sandbox()->allowView('deployer::partials.header')->renderFallback('strip')->renderView('deployer::partials.header');

        $this->assertSame("<header></header>\n", $html);
    }

    public function testMissingViewFallsBackToEmptySource(): void
    {
        $this->assertSame('', $this->sandbox()->allowView('deployer::nope')->renderFallback()->renderView('deployer::nope'));
    }

    public function testCustomFallbacks(): void
    {
        $this->app->make(SandboxManager::class)->extendFallback('notice', new NoticeFallback());

        $this->assertStringStartsWith('<p>Template [inline:', $this->sandbox()->renderFallback('notice')->render('{{ exec("x") }}'));
        $this->assertSame('closure:ForbiddenFunctionException', $this->sandbox()
            ->renderFallback(static fn (string $source, string $view, Throwable $e): string => 'closure:'.class_basename($e))
            ->render('{{ exec("x") }}'));
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sandbox()->renderFallback('nope');
    }

    public function testEventsAreDispatchedForFallbacks(): void
    {
        Event::fake([TemplateRenderFailed::class, SecurityViolationDetected::class]);

        $this->sandbox()->renderFallback('empty')->render('{{ exec("x") }}');

        Event::assertDispatched(SecurityViolationDetected::class);
        Event::assertDispatched(TemplateRenderFailed::class, static fn (TemplateRenderFailed $event): bool => $event->fallback === 'empty');
    }

    public function testConfigFallback(): void
    {
        $sandbox = PolicyConfiguration::apply($this->sandbox(), ['fallback' => 'escaped']);

        $this->assertStringStartsWith('<pre', $sandbox->render('{{ exec("x") }}'));
    }

    public function testStripperHandlesNestedDirectiveArguments(): void
    {
        $this->assertSame(
            '<ul><li>x</li></ul>@',
            BladeStripper::strip('<ul>@foreach($items->filter(fn ($i) => ($i > 1)) as $item)<li>x</li>@endforeach</ul>@@'),
        );
        $this->assertSame('{{ raw }}', BladeStripper::strip('@verbatim{{ raw }}@endverbatim'));
        $this->assertSame('mail@example.com', BladeStripper::strip('mail@example.com'));
    }
}

final class NoticeFallback implements FallbackRenderer
{
    public function render(string $source, string $view, Throwable $exception): string
    {
        return '<p>Template ['.$view.'] is currently unavailable.</p>';
    }
}
