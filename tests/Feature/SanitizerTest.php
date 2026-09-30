<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;
use SytxLabs\BladeSandbox\Exceptions\UnsafeOutputException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Sanitizers\DefaultSanitizer;
use SytxLabs\BladeSandbox\Sanitizers\FileSanitizerHtmlSanitizer;
use SytxLabs\BladeSandbox\Sanitizers\HtmlSanitizer;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class SanitizerTest extends TestCase
{
    #[DataProvider('xssPayloads')]
    public function testBuiltInSanitizerRemovesActiveContent(string $payload): void
    {
        $html = (new HtmlSanitizer())->sanitize($payload, 'test');
        $lower = strtolower($html);

        foreach (['<script', 'javascript:', 'vbscript:', 'onerror', 'onload', 'onclick', 'onmouseover', '<iframe', '<object', '<embed', '<svg', '<math',
            '<style', '<template', '<base', '<form', '<button', '<link', 'http-equiv', 'x-data', 'x-init', '@click', 'wire:click', 'data:text', 'data:image/svg', 'srcdoc', '<!--'] as $needle) {
            $this->assertStringNotContainsString($needle, $lower, $payload.' => '.$html);
        }
    }

    #[DataProvider('xssPayloads')]
    public function testStrictModeRejectsActiveContent(string $payload): void
    {
        $this->expectException(UnsafeOutputException::class);
        (new HtmlSanitizer(rejectUnsafe: true))->sanitize($payload, 'test');
    }

    /** @return iterable<string, array{string}> */
    public static function xssPayloads(): iterable
    {
        yield 'script' => ['<p>a</p><script>alert(1)</script>'];
        yield 'uppercase script' => ['<SCRIPT SRC=//evil.example/x.js></SCRIPT>'];
        yield 'event handler' => ['<img src="x.png" onerror="alert(1)">'];
        yield 'event handler uppercase' => ['<div ONMOUSEOVER="alert(1)">x</div>'];
        yield 'javascript url' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'obfuscated javascript url' => ['<a href="  java&#x09;script&#58;alert(1)">x</a>'];
        yield 'entity encoded javascript' => ['<a href="&#106;avascript:alert(1)">x</a>'];
        yield 'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'];
        yield 'data html url' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'];
        yield 'data svg image' => ['<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">'];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'object' => ['<object data="x.swf"></object>'];
        yield 'embed' => ['<embed src="x.swf">'];
        yield 'svg onload' => ['<svg onload="alert(1)"><circle/></svg>'];
        yield 'svg script' => ['<svg><script>alert(1)</script></svg>'];
        yield 'math' => ['<math><mtext><img src=x onerror=alert(1)></mtext></math>'];
        yield 'style element' => ['<style>body{background:url(javascript:alert(1))}</style>'];
        yield 'template' => ['<template><img src=x onerror=alert(1)></template>'];
        yield 'noscript mxss' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'];
        yield 'base href' => ['<base href="javascript:alert(1)//">'];
        yield 'form action' => ['<form action="javascript:alert(1)"><button>x</button></form>'];
        yield 'formaction' => ['<button formaction="javascript:alert(1)">x</button>'];
        yield 'link import' => ['<link rel="stylesheet" href="https://evil.example/x.css">'];
        yield 'alpine' => ['<div x-data="{}" x-init="alert(1)" @click="alert(1)">x</div>'];
        yield 'livewire' => ['<button wire:click="deleteEverything">x</button>'];
        yield 'srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'];
        yield 'comment' => ['<!--[if IE]><script>alert(1)</script><![endif]-->'];
        yield 'nested unknown tags' => ['<custom-el onclick="alert(1)"><script>alert(1)</script></custom-el>'];
    }

    public function testSafeMarkupIsKept(): void
    {
        $html = '<h1 class="title">Über uns</h1><p>Hello <strong>world</strong> &amp; <a href="https://example.com" target="_blank">link</a>, <a href="/x?y=1#z">rel</a>, <a href="mailto:a@b.c">m</a></p>'
            .'<img src="data:image/png;base64,iVBORw0KGgo=" alt="dot"><ul data-id="1" aria-label="list"><li>1</li></ul><table><tr><td colspan="2">c</td></tr></table>';

        $clean = (new HtmlSanitizer())->sanitize($html, 'test');

        $this->assertStringContainsString('<h1 class="title">Über uns</h1>', $clean);
        $this->assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener noreferrer">link</a>', $clean);
        $this->assertStringContainsString('<a href="/x?y=1#z">rel</a>', $clean);
        $this->assertStringContainsString('href="mailto:a@b.c"', $clean);
        $this->assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $clean);
        $this->assertStringContainsString('<ul data-id="1" aria-label="list">', $clean);
        $this->assertStringContainsString('<td colspan="2">c</td>', $clean);
        $this->assertStringContainsString('&amp;', $clean);
        $this->assertSame('', (new HtmlSanitizer(rejectUnsafe: true))->sanitize('', 'test'));
        (new HtmlSanitizer(rejectUnsafe: true))->sanitize($html, 'test');
    }

    public function testFullDocumentsKeepTheirStructure(): void
    {
        $clean = (new HtmlSanitizer())->sanitize("<!DOCTYPE html>\n<html lang=\"de\"><head><title>Seite</title><script>x()</script></head><body onload=\"x()\"><p>Grüße</p></body></html>", 'test');

        $this->assertStringStartsWith('<!DOCTYPE html>', $clean);
        $this->assertStringContainsString('<html lang="de">', $clean);
        $this->assertStringContainsString('<title>Seite</title>', $clean);
        $this->assertStringContainsString('<body><p>Grüße</p></body>', $clean);
        $this->assertStringNotContainsString('script', $clean);
    }

    public function testOptions(): void
    {
        $sanitizer = new HtmlSanitizer(extraTags: ['custom-el'], extraAttributes: ['button' => ['type'], '*' => ['onclick', 'wire:key']], allowStyle: true, allowDataAttributes: false);

        $clean = $sanitizer->sanitize('<custom-el wire:key="1" onclick="x()" style="color: red" data-x="1">a</custom-el><p style="background:url(//evil)">b</p>', 'test');

        $this->assertSame('<custom-el wire:key="1" style="color: red">a</custom-el><p>b</p>', $clean);
    }

    public function testPerPageSanitizing(): void
    {
        // allowAlpine(): with Livewire installed the compiler itself already rejects JavaScript attributes.
        $sandbox = $this->sandbox()->allowAlpine()->allowRawEcho()->allowViewNamespace('deployer')
            ->sanitizeWith('html')
            ->sanitizeWith(static fn (string $html, string $view): string => '['.$view.']', 'deployer::emails.*')
            ->withoutSanitizer('deployer::emails.*');

        $this->assertSame('<p>x</p><img src="x">', $sandbox->render('<p>x</p>{!! $evil !!}', ['evil' => '<img src=x onerror=alert(1)>']));

        $sandbox->sanitizeWith(static fn (string $html, string $view): string => 'page:'.$view, 'deployer::emails.*');
        $this->assertSame('page:deployer::emails.deploy', $sandbox->allowDto(DeploymentDTO::class)->renderView('deployer::emails.deploy', ['deployment' => new DeploymentDTO()]));
        $this->assertSame('SECRET ADMIN VIEW', trim($sandbox->renderView('deployer::admin.secret')));

        // Without a sanitizer the raw output is untouched.
        $this->assertSame('<p onclick="x()">x</p>', $this->sandbox()->allowAlpine()->render('<p onclick="x()">x</p>'));
    }

    public function testSanitizerAppliesToBoundNamespacesAndThePageOutputOnly(): void
    {
        BladeSandbox::sandboxNamespace('deployer', $this->sandbox()->allowViewNamespace('deployer')->allowDto(DeploymentDTO::class)
            ->sanitizeWith(new class implements OutputSanitizer
            {
                public int $calls = 0;

                public function sanitize(string $html, string $view): string
                {
                    $this->calls++;

                    return strtoupper($html).$this->calls;
                }
            }));

        // One call for the page (deployer::test), not for its include.
        $this->assertSame("<H1>API</H1>\n<HEADER>RUNNING</HEADER>\n1", view('deployer::test', ['deployment' => new DeploymentDTO()])->render());
    }

    public function testStrictModeAuditsAndThrows(): void
    {
        $this->expectException(UnsafeOutputException::class);
        $this->expectExceptionMessage('element:script');
        $this->sandbox()->allowAlpine()->sanitizeWith('html-strict')->render('<script>x</script>');
    }

    public function testFileSanitizerAdapter(): void
    {
        $clean = (new FileSanitizerHtmlSanitizer())->sanitize('<div onclick="x()"><script>alert(1)</script>ok</div>', 'test');
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('ok', $clean);

        $this->expectException(UnsafeOutputException::class);
        (new FileSanitizerHtmlSanitizer(rejectUnsafe: true))->sanitize('<a href="javascript:alert(1)">x</a><script>alert(1)</script>', 'test');
    }

    public function testDefaultPrefersFileSanitizerAndFallsBackToTheBuiltInOne(): void
    {
        $this->assertInstanceOf(FileSanitizerHtmlSanitizer::class, (new DefaultSanitizer())->sanitizer());
        $this->assertInstanceOf(HtmlSanitizer::class, (new DefaultSanitizer(useFileSanitizer: false))->sanitizer());

        $this->assertStringNotContainsString('<script', $this->sandbox()->allowAlpine()->sanitizeWith()->render('<p>a</p><script>x()</script>'));
    }

    public function testConfigPolicyOptions(): void
    {
        config()->set('blade-sandbox.policies.cms', ['sanitizer' => 'html', 'view_sanitizers' => ['deployer::emails.*' => 'html-strict']]);
        $sandbox = $this->app->make(SandboxManager::class)->policy('cms')->allowAlpine();

        $this->assertSame('<p>a</p>', $sandbox->render('<p onclick="x()">a</p>'));
        $this->assertInstanceOf(HtmlSanitizer::class, $sandbox->sanitizerFor('deployer::emails.deploy'));

        $this->expectException(InvalidArgumentException::class);
        $this->sandbox()->sanitizeWith('does-not-exist');
    }

    public function testSanitizerClassMustImplementTheContract(): void
    {
        config()->set('blade-sandbox.sanitizers.bad', stdClass::class);

        try {
            $this->sandbox()->sanitizeWith('bad');
            $this->fail('a sanitizer class not implementing OutputSanitizer must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must implement', $exception->getMessage());
        }
    }

    public function testWithoutSanitizerWithNoArgumentsClearsEveryRule(): void
    {
        $sandbox = $this->sandbox()->allowAlpine()->sanitizeWith()->sanitizeWith(view: 'deployer::emails.deploy')->withoutSanitizer();

        $this->assertStringContainsString('<script', $sandbox->render('<script>x()</script>'));
    }

    public function testHtmlSanitizerUnwrapsUnknownElementsKeepingTheirChildren(): void
    {
        $this->assertSame('<p>hi <b>x</b></p>', (new HtmlSanitizer())->sanitize('<p>hi <custom-tag><b>x</b></custom-tag></p>', 'inline'));
    }
}
