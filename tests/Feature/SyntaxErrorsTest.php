<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class SyntaxErrorsTest extends TestCase
{
    #[DataProvider('malformedTemplates')]
    public function testMalformedTemplatesAreRejectedWithAHelpfulMessage(string $template, string $expectedMessage): void
    {
        try {
            $this->sandbox()->allowComponent('*')->allowView('*')->validate($template)->throw();
            $this->fail('Expected a syntax error for: '.$template);
        } catch (SecurityViolationException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedTemplates(): iterable
    {
        yield 'component tag attributes never close' => ['<x-foo', 'unclosed component tag'];
        yield 'opened component never closed' => ['<x-foo>', 'unclosed component <x-foo>'];
        yield 'unclosed forelse' => ['@forelse($items as $i)', 'unclosed @forelse'];
        yield 'switch without an endswitch at all' => ['@switch($x)', 'unclosed @switch'];
        yield 'non-whitespace text before first case' => ["@switch(\$x)\nnot whitespace\n@case(1)x@break\n@endswitch", 'only whitespace is allowed'];
        yield 'a directive other than case/default right after switch' => ['@switch($x) @if(true) x @endif @case(1)y@break @endswitch', 'only @case or @default may follow'];
        yield 'endforelse without any forelse at all' => ['@endforelse', '@endforelse without an opening directive'];
        yield 'endforelse for a forelse that was never emptied' => ['@forelse($items as $i)x @endforelse', '@endforelse without matching'];
        yield 'multiple extends' => ["@extends('a')\n@extends('b')", 'multiple @extends'];
        yield 'unclosed verbatim' => ['@verbatim no end', 'unclosed @verbatim'];
        yield 'empty with no opening directive at all' => ['@empty', '@empty without an opening directive'];
        yield 'a second empty for the same forelse' => ['@forelse($items as $i)x @empty y @empty z @endforelse', '@empty without @forelse'];
        yield 'endcomponent without component' => ['@endcomponent', '@endcomponent without @component'];
        yield 'slot closing tag without opening' => ['</x-slot>', '</x-slot> without'];
        yield 'livewire tag not self closing' => ['<livewire:foo>x</livewire:foo>', 'self-closing'];
        yield 'unclosed component attribute echo' => ['<x-foo {{ $unclosed', 'unterminated echo in component tag'];
        yield 'unquoted attribute with dynamic content' => ['<div class=pre{{ $x }}post>', 'quote the attribute value'];
        yield 'unterminated component tag attribute value' => ['<x-foo class="unterminated', 'unterminated attribute value'];
        yield 'unterminated echo inside a component tag attribute value' => ['<x-foo class="pre-{{ $x">', 'unterminated echo in attribute'];
        yield 'markup directive inside a tag' => ['<div @include(\'x\')>', 'not inside tags'];
        yield 'escaped-output directive inside a tag' => ['<div @json($x)>', '@json is not allowed inside tags'];
        yield 'bare livewire closing tag' => ['</livewire:foo>', 'self-closing'];
        yield 'unbalanced parens for the directive-attribute class form' => ['<x-foo @class(', 'unbalanced parentheses'];
        yield 'unquoted component tag attribute with dynamic content' => ['<x-foo class=pre{{ $x }}post />', 'quote the attribute value'];
        yield 'invalid attribute value right before self close' => ['<x-foo attr=/>', 'invalid attribute value'];
        yield 'word directive at tag-name position' => ['<@checked($x)>', 'not allowed inside tag or attribute names'];
        yield 'invalid attribute token in a component tag' => ['<x-foo =bad />', 'invalid attribute in component tag'];
        yield 'bound attribute without a value' => ['<x-foo :bar />', 'needs a value'];
        yield 'dynamic component without a component attribute' => ['<x-dynamic-component />', 'missing "component" attribute'];
    }

    public function testBreakAndContinueWithoutAnOpenBlockAreSilentlyAccepted(): void
    {
        $this->assertTrue($this->sandbox()->validate('@break')->passes());
        $this->assertTrue($this->sandbox()->validate('@continue')->passes());
    }

    public function testDefaultAsTheFirstSwitchBranch(): void
    {
        $html = $this->sandbox()->render("@switch(\$x)\n@default\ny\n@endswitch", ['x' => 'anything']);
        $this->assertSame('y', trim($html));
    }

    public function testBreakWithANumericLevelInsideNestedLoops(): void
    {
        $html = $this->sandbox()->render('@foreach($a as $x)@foreach($b as $y){{ $x }}{{ $y }}@break(2) @endforeach @endforeach', ['a' => [1, 2], 'b' => [1, 2]]);
        $this->assertSame('11', $html);
    }

    public function testInlineSlotDirectiveWithTwoArguments(): void
    {
        $html = $this->sandbox()->allowView('deployer::components.card')
            ->render("@component('deployer::components.card', ['title' => 'T']) @slot('footer', 'Fixed') @endcomponent");
        $this->assertStringContainsString('Fixed', $html);
    }

    public function testEachCollectsTheLiteralEmptyViewAsAReference(): void
    {
        $result = $this->sandbox()->validate("@each('deployer::partials.footer', \$items, 'i', 'deployer::partials.empty-each')");
        $this->assertTrue($result->fails());
        $this->assertTrue(collect($result->violations())->contains(fn ($v) => $v->subject === 'deployer::partials.empty-each'));
    }

    public function testIncludeFirstWithADynamicNonLiteralArrayIsNotStaticallyChecked(): void
    {
        // Not a literal array: nothing to collect statically, but it must still compile and be checked at render time.
        $this->assertTrue($this->sandbox()->validate('@includeFirst($views)')->passes());
    }

    public function testCrlfAfterAnEchoIsPreserved(): void
    {
        $html = $this->sandbox()->render("{{ 1 }}\r\n{{ 2 }}");
        $this->assertStringContainsString("1\r\n2", $html);
    }

    public function testShortOpenTagLiteralInsideVerbatim(): void
    {
        $this->assertSame('<? x', trim($this->sandbox()->render('@verbatim<? x@endverbatim')));
    }

    public function testSelfClosingNamedSlotTag(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::card')->allowView('deployer::components.card')
            ->render('<x-deployer::card title="T"><x-slot:footer />Body</x-deployer::card>');
        $this->assertSame('<div class="card"><h2>T</h2>Body<footer></footer></div>', trim($html));
    }

    public function testDirectiveStyleClassAndStyleAttributesOnAComponentTag(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')
            ->render("<x-deployer::button @class(['active' => true, 'gone' => false]) @style(['color: red' => true])>x</x-deployer::button>");
        $this->assertStringContainsString('active', $html);
        $this->assertStringContainsString('color: red', $html);
    }

    public function testShorthandBoundAttributeOnAComponentTag(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')
            ->render('<x-deployer::button :$type>x</x-deployer::button>', ['type' => 'submit']);
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function testCommentCarryBoundarySplitAcrossADirective(): void
    {
        $html = $this->sandbox()->render('<!@if(true)@endif-- ok --> after');
        $this->assertSame('<!-- ok --> after', trim($html));
    }

    public function testCommentClosingSequenceCarryBoundarySplitAcrossADirective(): void
    {
        $html = $this->sandbox()->render('<!-- ab--@if(true)@endif>cd');
        $this->assertSame('<!-- ab-->cd', trim($html));
    }

    public function testPartialCommentOpenerBeforeAnEchoIsTreatedAsPlainText(): void
    {
        $this->assertSame('<!y', $this->sandbox()->render('<!{{ $x }}', ['x' => 'y']));
    }

    public function testBareAttributeImmediatelyFollowedByTagClose(): void
    {
        $this->assertSame('<input disabled>', trim($this->sandbox()->render('<input disabled>')));
    }

    public function testSpaceAroundTheEqualsSign(): void
    {
        $this->assertSame('<div attr = "x"></div>', trim($this->sandbox()->render('<div attr = "x"></div>')));
    }

    public function testEmptyAttributeValueBeforeTagClose(): void
    {
        $this->assertSame('<div attr=></div>', trim($this->sandbox()->render('<div attr=></div>')));
    }

    public function testSingleQuotedAttributeValue(): void
    {
        $this->assertSame("<div attr='x'></div>", trim($this->sandbox()->render("<div attr='x'></div>")));
    }

    public function testRawTextClosingTagCarryBoundarySplitAcrossADirective(): void
    {
        $html = $this->sandbox()->allowAlpine()->render('<script>abc<@if(true)@endif/script>after');
        $this->assertStringContainsString('abc', $html);
        $this->assertStringContainsString('after', $html);
    }

    public function testUnquotedStaticAttributeValue(): void
    {
        $this->assertSame('<div class=foo></div>', trim($this->sandbox()->render('<div class=foo></div>')));
    }

    public function testEchoDirectlyAfterAQuotedAttributeValueWithoutSpaceIsRejected(): void
    {
        $this->expectException(SecurityViolationException::class);
        $this->sandbox()->render('<div class="x"{{ $y }}>', ['y' => 'z']);
    }

    public function testTemplateEndingRightAfterAnEmptyDynamicAttributeName(): void
    {
        $this->expectException(SecurityViolationException::class);
        $this->sandbox()->render('<div {{ $bag }}', ['bag' => []]);
    }

    public function testDynamicAttributeNameThatIsEmptyButHasAValueIsRejected(): void
    {
        try {
            $this->sandbox()->render('<div {{ $x }}="y">', ['x' => 'data-foo']);
            $this->fail('an empty dynamic attribute name with a value must be rejected');
        } catch (SecurityViolationException $exception) {
            $this->assertStringContainsString('data-', $exception->getMessage());
        }
    }

    public function testDynamicAttributeNameWithoutTheRequiredPrefixIsRejected(): void
    {
        try {
            $this->sandbox()->render('<div bad-{{ $x }}="y">', ['x' => 'foo']);
            $this->fail('a dynamic attribute name suffix without a data-/aria- prefix must be rejected');
        } catch (SecurityViolationException $exception) {
            $this->assertStringContainsString('data-', $exception->getMessage());
        }
    }

    public function testBreakWithExplicitEmptyParenthesesIsTheSameAsNoArguments(): void
    {
        $html = $this->sandbox()->render('@foreach($a as $x){{ $x }}@break()@endforeach', ['a' => [1, 2]]);
        $this->assertSame('1', $html);
    }

    public function testTripleBraceEchoSyntax(): void
    {
        $this->assertSame('1', trim($this->sandbox()->render('{{{ 1 }}}')));
    }

    public function testEchoContentLargerThanTheScanWindowGrowsTheWindow(): void
    {
        $long = str_repeat('a', 5000);
        $this->assertSame($long, $this->sandbox()->render("{{ '".$long."' }}"));
    }

    public function testForeachByReferenceIsRejected(): void
    {
        try {
            $this->sandbox()->render('@foreach($a as &$x){{ $x }}@endforeach', ['a' => [1, 2]]);
            $this->fail('foreach by reference must be rejected');
        } catch (InvalidSandboxTemplateException $exception) {
            $this->assertStringContainsString('foreach by reference', $exception->getMessage());
        }
    }

    public function testListDestructuringByReferenceIsRejected(): void
    {
        try {
            $this->sandbox()->render('@foreach($pairs as [$x, &$y]){{ $x }}@endforeach', ['pairs' => [[1, 2]]]);
            $this->fail('by-reference destructuring must be rejected');
        } catch (InvalidSandboxTemplateException $exception) {
            $this->assertStringContainsString('reference', $exception->getMessage());
        }
    }

    public function testListDestructuringSkipsEmptySlots(): void
    {
        $html = $this->sandbox()->render('@foreach($pairs as [$x, , $z]){{ $x }}-{{ $z }};@endforeach', ['pairs' => [[1, 2, 3]]]);
        $this->assertSame('1-3;', $html);
    }

    public function testMultipleIssetTargetsAreCombinedWithBooleanAnd(): void
    {
        $this->assertSame('1', trim($this->sandbox()->render('{{ (int) isset($a->x, $b->y) }}', ['a' => (object) ['x' => 1], 'b' => (object) ['y' => 2]])));
        $this->assertSame('0', trim($this->sandbox()->render('{{ (int) isset($a->x, $b->y) }}', ['a' => (object) ['x' => 1], 'b' => (object) []])));
    }

    public function testNamedArgumentsToADirectiveAreRejected(): void
    {
        try {
            $this->sandbox()->allowView('deployer::partials.footer')->render("@include(view: 'deployer::partials.footer')");
            $this->fail('a named directive argument must be rejected');
        } catch (InvalidSandboxTemplateException $exception) {
            $this->assertStringContainsString('named, spread or by-reference', $exception->getMessage());
        }
    }

    public function testUnquotedComponentTagAttributeValueWithoutDynamicContent(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')->render('<x-deployer::button type=submit>x</x-deployer::button>');
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function testMixedLiteralAndEchoAttributeValueOnAComponentTag(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')
            ->render('<x-deployer::button type="pre-{{ $x }}-post">x</x-deployer::button>', ['x' => 'mid']);
        $this->assertStringContainsString('type="pre-mid-post"', $html);
    }
}
