<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Livewire;

use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Tests\TestCase;

/**
 * Attempts to smuggle wire:* / JavaScript attributes past the HTML context tracker.
 */
final class HtmlContextBypassTest extends TestCase
{
    #[DataProvider('payloads')]
    public function testWireAttributesCannotBeSmuggled(string $template): void
    {
        $sandbox = $this->sandbox()->allowLivewireDirective('click')->allowLivewireAction('save')->allowRawEcho();

        try {
            $output = $sandbox->render($template, [
                'tag' => 'a wire:click=deleteEverything',
                'suffix' => 'x wire:click=deleteEverything',
                'html' => '<button wire:click="deleteEverything">x</button>',
                'attr' => 'wire:click=deleteEverything',
            ]);
            $this->fail('Template was not rejected: '.$template.' => '.$output);
        } catch (SandboxException|SecurityViolationException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function payloads(): iterable
    {
        yield 'tag name built by directive' => ['<@if(true)button@endif wire:click="deleteEverything">x</button>'];
        yield 'tag name after comment' => ['<{{-- --}}button wire:click="deleteEverything">x</button>'];
        yield 'empty comment <!-->' => ['<!--> <button wire:click="deleteEverything">x</button> -->'];
        yield 'empty comment <!--->' => ['<!---> <button wire:click="deleteEverything">x</button> -->'];
        yield 'comment closed with --!>' => ['<!-- a --!> <button wire:click="deleteEverything">x</button> -->'];
        yield 'echo as tag name' => ['<{{ $tag }}>x</a>'];
        yield 'echo as attribute name suffix' => ['<a data-{{ $suffix }}>x</a>'];
        yield 'uppercase attribute' => ['<button WIRE:CLICK="deleteEverything">x</button>'];
        yield 'entity encoded action' => ['<button wire:click="delete&#69;verything">x</button>'];
        yield 'slash separated attribute' => ['<button/wire:click="deleteEverything">x</button>'];
        yield 'raw echo html' => ['{!! $html !!}'];
        yield 'echo in attribute position' => ['<div {{ $attr }}></div>'];
    }

    public function testLegitimateDynamicMarkupStillWorks(): void
    {
        $sandbox = $this->sandbox()->allowLivewireDirective('click')->allowLivewireAction('save')->allowRawEcho();

        $this->assertSame('<a data-id-5="1">x</a>', $sandbox->render('<a data-id-{{ $id }}="1">x</a>', ['id' => 5]));
        // Dynamic tag names are only possible when JavaScript surfaces are allowed (they could form <script>).
        $this->assertSame('<h2>x</h2>', $this->sandbox()->allowAlpine()->render('<h{{ $level }}>x</h2>', ['level' => 2]));
        $this->assertSame('<b>ok</b>', $sandbox->render('{!! $html !!}', ['html' => '<b>ok</b>']));
        $this->assertSame('<button wire:click="save(&#039;a&amp;b&#039;)">x</button>', $sandbox->render('<button wire:click="save(&#039;a&amp;b&#039;)">x</button>'));
    }

    #[DataProvider('contextDesyncPayloads')]
    public function testContextDesyncIsRejected(string $template): void
    {
        View::addNamespace('attack', __DIR__.'/../Fixtures/views/attack');
        $sandbox = $this->sandbox()->allowLivewireDirective('click')->allowLivewireAction('save')->allowRawEcho()
            ->allowViewNamespace('attack')->allowComponent('deployer::button');

        try {
            $output = $sandbox->render($template, ['x' => 1, 'open' => '<button']);
            $this->fail('Template was not rejected: '.$template.' => '.$output);
        } catch (SandboxException|SecurityViolationException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function contextDesyncPayloads(): iterable
    {
        // The browser only sees one branch; the tracker must not validate a different context.
        yield 'branch opens attribute value' => ['@if(false)<p title="@endif<button wire:click="deleteEverything">@if(false)"@endif'];
        yield 'branch opens tag' => ['@if($x)<div @else<span @endif data-x="1">'];
        yield 'loop body leaves tag open' => ['@foreach([1] as $i)<a @endforeach wire:click="save">'];
        yield 'switch case in different context' => ['@switch($x) @case(1)<p title=" @break @case(2) " @break @endswitch'];
        yield 'include that ends inside a tag' => ["@include('attack::open-tag') wire:click=\"deleteEverything\">x</button>"];
        yield 'section fragment ending inside a tag' => ["@section('a')<button @endsection"];
        yield 'component inside attribute value' => ['<div title="<x-deployer::button />">'];
        yield 'include inside a tag' => ["<div @include('attack::open-tag')>"];
        yield 'raw echo leaves tag open' => ['{!! $open !!} wire:click="deleteEverything">'];
        yield 'raw echo inside attribute' => ['<a title="{!! $open !!}">'];
        yield 'class directive inside value' => ["<a title=\"@class(['x wire:click=deleteEverything'])\">"];
    }

    public function testMarkupDirectivesInsideRawTextAreEscaped(): void
    {
        View::addNamespace('attack', __DIR__.'/../Fixtures/views/attack');

        $html = $this->sandbox()->allowViewNamespace('attack')->renderView('attack::child');

        $this->assertStringContainsString('<title>A &amp; B</title>', $html);
        $this->assertStringContainsString('<main><p>ok</p></main>', $html);

        // An included "</textarea>" cannot close the element and turn the following text into live markup.
        $html = $this->sandbox()->allowViewNamespace('attack')->render("<textarea>@include('attack::closer')<button wire:click=\"deleteEverything\">x</button></textarea>");
        $this->assertStringContainsString('<textarea>&lt;/textarea&gt;', $html);
    }

    public function testConsistentBranchesAreFine(): void
    {
        $html = $this->sandbox()->render('<input @if($a) disabled @endif class="x @if($b) active @endif"> @foreach([1, 2] as $i)<b>{{ $i }}</b>@endforeach', ['a' => true, 'b' => true]);

        $this->assertSame('<input  disabled  class="x  active "> <b>1</b><b>2</b>', $html);
    }
}
