<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayObject;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use InvalidArgumentException;
use RuntimeException;
use SytxLabs\BladeSandbox\Contracts\TextConverter;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Preview\PreviewResult;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;
use SytxLabs\BladeSandbox\Text\HtmlToText;
use SytxLabs\BladeSandbox\Validation\ValidationResult;
use TypeError;

final class HelpersPreviewTextTest extends TestCase
{
    public function testSafeHelpers(): void
    {
        $sandbox = $this->sandbox()->allowSafeHelpers();

        $this->assertSame(
            'HELLO|1,234.50|Hello world...|my-page',
            $sandbox->render('{{ strtoupper("hello") }}|{{ number_format(1234.5, 2) }}|{{ \Illuminate\Support\Str::limit("Hello world again", 11) }}|{{ \Illuminate\Support\Str::slug("My Page") }}'),
        );
        $this->assertSame(date('Y'), $sandbox->render('{{ now()->format("Y") }}'));

        if (class_exists(Number::class) && extension_loaded('intl')) {
            $this->assertSame('1,234.5', $sandbox->render('{{ \Illuminate\Support\Number::format(1234.5) }}'));
        }
    }

    public function testSafeHelpersExcludeDangerousFunctions(): void
    {
        foreach (['{{ str_repeat("x", 10) }}', '{{ implode(",", [1]) }}', '{{ sprintf("%s", 1) }}', '{{ str_pad("x", 5) }}'] as $template) {
            try {
                $this->sandbox()->allowSafeHelpers()->render($template);
                $this->fail($template.' must not be part of the safe helpers.');
            } catch (ForbiddenFunctionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ForbiddenMethodException::class);
        $this->sandbox()->allowSafeHelpers()->render('{{ \Illuminate\Support\Str::padLeft("x", 5) }}');
    }

    public function testSafeHelpersDoNotStringifyObjects(): void
    {
        $this->expectException(TypeError::class);

        $this->sandbox()->allowSafeHelpers()->render('{{ strtoupper($o) }}', ['o' => new HtmlString('x')]);
    }

    public function testCustomHelperSetsAndConfig(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $manager->helpers('money', static fn (Sandbox $sandbox) => $sandbox->allowFunction('number_format'));

        $sandbox = PolicyConfiguration::apply($this->sandbox(), ['helpers' => ['money']]);
        $this->assertSame('1.00', $sandbox->render('{{ number_format(1, 2) }}'));

        $this->expectException(InvalidArgumentException::class);
        $this->sandbox()->allowHelpers('unknown');
    }

    public function testPreviewPasses(): void
    {
        $result = $this->sandbox()->preview('<b>{{ $name }}</b>', ['name' => 'Ada']);

        $this->assertTrue($result->passes());
        $this->assertSame('<b>Ada</b>', $result->html());
        $this->assertSame([], $result->errors());
    }

    public function testPreviewReportsErrorsWithLinesWithoutThrowing(): void
    {
        $result = $this->sandbox()->preview("<p>ok</p>\n\n{{ exec('id') }}", [], 'editor');

        $this->assertTrue($result->fails());
        $this->assertNull($result->html);
        $this->assertSame(3, $result->errors()[0]['line']);
        $this->assertSame('function', $result->errors()[0]['capability']);
        $this->assertCount(1, $result->errors());
        $this->assertSame('editor', $result->toArray()['view']);
    }

    public function testPreviewRuntimeErrorOnly(): void
    {
        $result = $this->sandbox()->preview('{{ $user->secret() }}', ['user' => new ArrayObject()]);

        $this->assertTrue($result->validation->passes());
        $this->assertTrue($result->fails());
        $this->assertNotNull($result->error);
    }

    public function testPreviewView(): void
    {
        $result = $this->sandbox()->allowView('deployer::partials.header')->previewView('deployer::partials.header', ['deployment' => (object) ['status' => 'up']]);

        $this->assertSame("<header>up</header>\n", $result->html());
    }

    public function testRenderText(): void
    {
        $html = <<<'HTML'
            <html><head><title>T</title><style>p{}</style></head><body>
            <h1>Welcome   {{ $name }}</h1>
            <p>Line one<br>Line two &amp; more</p>
            <ul><li>First</li><li>Second</li></ul>
            <ol><li>A</li><li>B</li></ol>
            <p><a href="https://example.com/x">Open</a> <a href="mailto:a@b.c">a@b.c</a> <a href="javascript:x">js</a></p>
            <table><tr><th>Name</th><th>Qty</th></tr><tr><td>Tea</td><td>2</td></tr></table>
            <hr><img src="x.png" alt="Logo"><noscript>no</noscript>
            </body></html>
            HTML;

        $this->assertSame(
            "Welcome Ada\n\nLine one\nLine two & more\n\n- First\n- Second\n\n1. A\n2. B\n\nOpen (https://example.com/x) a@b.c js\n\nName | Qty\nTea | 2\n\n---\n\nLogo",
            $this->sandbox()->renderText($html, ['name' => 'Ada']),
        );
    }

    public function testCustomTextConverter(): void
    {
        $this->app->make(SandboxManager::class)->useTextConverter(new class implements TextConverter
        {
            public function convert(string $html): string
            {
                return strtoupper(strip_tags($html));
            }
        });

        $this->assertSame('HI', $this->sandbox()->renderText('<b>hi</b>'));
        $this->assertSame("a\nb", (new HtmlToText())->convert('<p>a</p><p>b</p>') === "a\n\nb" ? "a\nb" : 'mismatch');
    }

    public function testRenderViewTextConvertsARenderedViewToPlainText(): void
    {
        $this->assertSame('ok', $this->sandbox()->allowView('deployer::partials.header')->renderViewText('deployer::partials.header', ['deployment' => (object) ['status' => 'ok']]));
    }

    public function testPreviewResultReportsRuntimeErrorsOnce(): void
    {
        $error = (new SecurityViolationException('boom', 'function', 'system'))->atLine(4);
        $result = new PreviewResult('inline', null, new ValidationResult(), $error);

        $this->assertTrue($result->fails());
        $this->assertSame('', $result->html());
        $this->assertSame([['message' => 'boom', 'line' => 4, 'capability' => 'function']], $result->errors());
        $this->assertSame([['message' => 'x', 'line' => null, 'capability' => null]], (new PreviewResult('v', null, new ValidationResult(), new RuntimeException('x')))->errors());
    }

    public function testHtmlToTextHandlesEmptyInputAndPreformattedBlocks(): void
    {
        $converter = new HtmlToText();

        $this->assertSame('', $converter->convert("  \n"));
        $this->assertStringContainsString('a', $converter->convert("<pre>a\n  b</pre>"));
    }
}
