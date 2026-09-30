<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayObject;
use SytxLabs\BladeSandbox\Contracts\MarkdownConverter;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class MarkdownTest extends TestCase
{
    public function testMarkdownIsOptIn(): void
    {
        $this->expectException(ForbiddenDirectiveException::class);

        $this->sandbox()->render('@markdown($text)', ['text' => '# Hi']);
    }

    public function testDirectiveWithValue(): void
    {
        $html = $this->sandbox()->allowMarkdown()->render('@markdown($text)', ['text' => "# Title\n\n**bold** and [link](https://example.com)"]);

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com">link</a>', $html);
    }

    public function testBlockFormRendersBladeInside(): void
    {
        $template = <<<'BLADE'
            @markdown
            ## Hello {{ $name }}

            | a | b |
            |---|---|
            | {{ $n }} | 2 |
            @endmarkdown
            BLADE;

        $html = $this->sandbox()->allowMarkdown()->render($template, ['name' => 'Ada', 'n' => 1]);

        $this->assertStringContainsString('<h2>Hello Ada</h2>', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
    }

    public function testRawHtmlAndUnsafeLinksAreNeutralised(): void
    {
        $html = $this->sandbox()->allowMarkdown()->render('@markdown($text)', ['text' => "<script>alert(1)</script>\n\n[x](javascript:alert(1)) <img src=x onerror=alert(1)>"]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        // Static HTML written inside a block is Markdown text, so it is escaped as well.
        $block = $this->sandbox()->allowMarkdown()->render("@markdown\n<b>hi</b>\n@endmarkdown");
        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $block);
    }

    public function testEscapedInsideAttributeValuesAndRejectedInsideTags(): void
    {
        $this->assertStringContainsString('&lt;p&gt;', $this->sandbox()->allowMarkdown()->render('<div title="@markdown($t)"></div>', ['t' => 'x']));

        $this->expectException(InvalidSandboxTemplateException::class);
        $this->sandbox()->allowMarkdown()->render("<div @markdown\nx\n@endmarkdown></div>");
    }

    public function testValuesGoThroughTheStringPolicy(): void
    {
        $this->expectException(ForbiddenMethodException::class);

        $this->sandbox()->allowMarkdown()->render('@markdown($o)', ['o' => new ArrayObject()]);
    }

    public function testUnbalancedBlocksAndCustomConverterAndConfig(): void
    {
        try {
            $this->sandbox()->allowMarkdown()->render('x @endmarkdown');
            $this->fail('An unopened block must fail.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('markdown', $e->getMessage());
        }

        $this->app->make(SandboxManager::class)->useMarkdownConverter(new class implements MarkdownConverter
        {
            public function convert(string $markdown): string
            {
                return '<md>'.htmlspecialchars(trim($markdown)).'</md>';
            }
        });

        $sandbox = PolicyConfiguration::apply($this->sandbox(), ['directives' => ['markdown']]);
        $this->assertSame('<md>a &amp; b</md>', $sandbox->render('@markdown($t)', ['t' => 'a & b']));
    }
}
