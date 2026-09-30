<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class RenderingTest extends TestCase
{
    public function testRawTemplateThroughFacade(): void
    {
        $this->assertSame('<h1>Shaun</h1>', BladeSandbox::render('<h1>{{ $name }}</h1>', ['name' => 'Shaun']));
    }

    public function testOutputIsEscaped(): void
    {
        $this->assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', $this->sandbox()->render('{{ $v }}', ['v' => "<script>alert('x')</script>"]));
    }

    public function testRawEchoIsDeniedByDefaultAndCanBeEnabled(): void
    {
        try {
            $this->sandbox()->render('{!! $v !!}', ['v' => '<b>x</b>']);
            $this->fail('raw echo must be denied');
        } catch (ForbiddenDirectiveException) {
        }

        $this->assertSame('<b>x</b>', $this->sandbox()->allowRawEcho()->render('{!! $v !!}', ['v' => '<b>x</b>']));
    }

    public function testControlStructures(): void
    {
        $template = <<<'BLADE'
@if($a > 1)big @elseif($a === 1)one @else small @endif|@unless($b)not-b @endunless|@isset($c)c @endisset|@empty($d)empty @endempty|@for($i = 0; $i < 3; $i++){{ $i }}@endfor|@php_is_text
BLADE;
        $this->assertSame('one |not-b |c |empty |012|@php_is_text', $this->sandbox()->render($template, ['a' => 1, 'b' => false, 'c' => 'x', 'd' => []]));
    }

    public function testLoopsAndLoopVariable(): void
    {
        $template = '@foreach($items as $key => $item){{ $loop->iteration }}/{{ $loop->count }}:{{ $key }}={{ $item }}@if($loop->last)!@endif @endforeach';
        $this->assertSame('1/2:a=x 2/2:b=y! ', $this->sandbox()->render($template, ['items' => ['a' => 'x', 'b' => 'y']]));
    }

    public function testNestedLoopsExposeParent(): void
    {
        $template = '@foreach($rows as $row)@foreach($row as $cell){{ $loop->parent->iteration }}{{ $loop->depth }}{{ $cell }};@endforeach @endforeach';
        $this->assertSame('12a;12b; 22c; ', $this->sandbox()->render($template, ['rows' => [['a', 'b'], ['c']]]));
    }

    public function testForelseAndWhileAndSwitch(): void
    {
        $this->assertSame('none', trim($this->sandbox()->render('@forelse($items as $i){{ $i }}@empty none @endforelse', ['items' => []])));
        $this->assertSame('ab', trim($this->sandbox()->render('@forelse($items as $i){{ $i }}@empty none @endforelse', ['items' => ['a', 'b']])));
        $this->assertSame('3210', $this->sandbox()->render('@while($n >= 0){{ $n-- }}@endwhile', ['n' => 3]));

        $switch = "@switch(\$x)\n  @case(1)\n one\n  @break\n  @case(2)\ntwo\n @break\n  @default\nother\n@endswitch";
        $this->assertSame('two', trim($this->sandbox()->render($switch, ['x' => 2])));
        $this->assertSame('other', trim($this->sandbox()->render($switch, ['x' => 9])));
    }

    public function testBreakAndContinue(): void
    {
        $template = '@foreach($items as $i)@continue($i === 2)@break($i === 4){{ $i }}@endforeach';
        $this->assertSame('13', $this->sandbox()->render($template, ['items' => [1, 2, 3, 4, 5]]));
    }

    public function testCommentsVerbatimAndEscapedEchoes(): void
    {
        $this->assertSame('a  b', $this->sandbox()->render('a {{-- {{ system("id") }} --}} b'));
        $this->assertSame('{{ $x }}', $this->sandbox()->render('@{{ $x }}'));
        $this->assertSame(' {{ $x }} @if ', $this->sandbox()->render('@verbatim {{ $x }} @if @endverbatim'));
        $this->assertSame('mail me: user@example.com @if', $this->sandbox()->render('mail me: user@example.com @@if'));
    }

    public function testUnknownDirectivesAreLeftAsText(): void
    {
        $this->assertSame('@something(1) @click', $this->sandbox()->render('@something(1) @click'));
    }

    public function testApplicationDirectivesAreBlocked(): void
    {
        Blade::directive('dangerous', static fn () => '<?php system("id"); ?>');
        Blade::if('disk', static fn () => true);

        foreach (['@dangerous', '@disk("local") x @enddisk'] as $template) {
            try {
                $this->sandbox()->render($template);
                $this->fail('application directive must be blocked: '.$template);
            } catch (ForbiddenDirectiveException $exception) {
                $this->assertStringContainsString('application directives', $exception->getMessage());
            }
        }
    }

    public function testSandboxDirectivesReceiveSandboxedArguments(): void
    {
        $sandbox = $this->sandbox()->directive('money', static fn (int $cents, string $currency = 'EUR'): string => number_format($cents / 100, 2).' '.$currency.' <b>');

        $this->assertSame('12.34 EUR &lt;b&gt;', $sandbox->render('@money($amount)', ['amount' => 1234]));
        $this->assertSame('<i>ok</i>', $this->sandbox()->directive('html', static fn () => new HtmlString('<i>ok</i>'))->render('@html'));
    }

    public function testJsonClassStyleAndConditionalAttributes(): void
    {
        $json = $this->sandbox()->render('@json($data)', ['data' => ['a' => '<b>']]);
        $this->assertStringNotContainsString('<', $json);
        $this->assertSame(['a' => '<b>'], json_decode($json, true));
        $this->assertSame('class="p-4 active"', $this->sandbox()->render("@class(['p-4', 'active' => \$on, 'off' => ! \$on])", ['on' => true]));
        $this->assertSame('style="color: red; font-weight: bold;"', $this->sandbox()->render("@style(['color: red', 'font-weight: bold' => true])"));
        $this->assertSame('<input checked disabled >', $this->sandbox()->render('<input @checked($a) @disabled(true) @readonly(false)>', ['a' => 1]));
    }

    public function testOnce(): void
    {
        $this->assertSame(1, substr_count($this->sandbox()->render('@foreach([1, 2, 3] as $i)@once<b>x</b>@endonce @endforeach'), '<b>x</b>'));
    }

    public function testShortOpenTagsInTextAreNeutralised(): void
    {
        $this->assertSame('<?xml version="1.0"?><a/>', $this->sandbox()->render('<?xml version="1.0"?><a/>'));
    }

    public function testRawPhpTagsAreRejected(): void
    {
        foreach (['<?php echo 1; ?>', '<?= 1 ?>', '<?PHP echo 1 ?>', '@verbatim <?php echo 1; ?> @endverbatim'] as $template) {
            try {
                $this->sandbox()->render($template);
                $this->fail('raw PHP must be rejected: '.$template);
            } catch (InvalidSandboxTemplateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVariablesCanBeAssignedLocally(): void
    {
        $this->assertSame('6', $this->sandbox()->render('{{ $total = $a * 2 }}', ['a' => 3]));
        $this->assertSame('1-2', $this->sandbox()->render('@foreach($pairs as [$x, $y]){{ $x }}-{{ $y }}@endforeach', ['pairs' => [[1, 2]]]));
    }

    public function testStringInterpolationAndConcatenationAreMediated(): void
    {
        $this->assertSame('Hi Bob!', $this->sandbox()->render('{{ "Hi {$name}!" }}', ['name' => 'Bob']));
        $this->assertSame('Hi Bob', $this->sandbox()->render("{{ 'Hi ' . \$name }}", ['name' => 'Bob']));
    }

    public function testNullCoalescingAndNullsafe(): void
    {
        $this->assertSame('fallback', $this->sandbox()->render("{{ \$missing ?? 'fallback' }}"));
        $this->assertSame('fallback', $this->sandbox()->render("{{ \$user->profile->name ?? 'fallback' }}"));
        $this->assertSame('fallback', $this->sandbox()->render("{{ \$user?->profile->name ?? 'fallback' }}", ['user' => null]));
        $this->assertSame('x', $this->sandbox()->render("{{ \$data['a']['b'] ?? 'y' }}", ['data' => ['a' => ['b' => 'x']]]));
    }

    public function testNullsafePropertyAccessWithoutCoalesceReturnsNullOnNullObject(): void
    {
        // Without a surrounding isset()/empty()/?? the property fetch is not "quiet": it stays a plain
        // nullsafe access that short-circuits to null on a null base instead of being suppressed.
        $this->assertSame('', $this->sandbox()->render('{{ $user?->profile }}', ['user' => null]));
    }

    public function testTagNameEchoRejectsInvalidCharacters(): void
    {
        // Dynamic tag names are only compiled when Livewire's JavaScript-surface enforcement is relaxed.
        $sandbox = $this->sandbox()->allowAlpine();
        $this->assertSame('<h1>x</h1>', $sandbox->render('<h{{ $level }}>x</h{{ $level }}>', ['level' => '1']));

        try {
            $sandbox->render('<h{{ $level }}>x</h{{ $level }}>', ['level' => ';onload=x']);
            $this->fail('invalid dynamic tag name must be rejected');
        } catch (SecurityViolationException $exception) {
            $this->assertStringContainsString('Dynamic tag names', $exception->getMessage());
        }
    }

    public function testBareAttributeEchoOfAPlainValue(): void
    {
        $this->assertSame('<div data-x=&quot;a&amp;b&quot;></div>', trim($this->sandbox()->render('<div {{ $v }}></div>', ['v' => 'data-x="a&b"'])));
    }

    public function testSpreadAttributesAcceptsArraysAndBagsAndRejectsOtherValues(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')->render('<x-deployer::button {{ $attrs }}>x</x-deployer::button>', [
            'attrs' => ['data-id' => '5', 'disabled' => true],
        ]);
        $this->assertStringContainsString('data-id="5"', $html);
        $this->assertStringContainsString('disabled', $html);

        $bag = new \Illuminate\View\ComponentAttributeBag(['data-y' => '1']);
        $html = $this->sandbox()->allowComponent('deployer::button')->render('<x-deployer::button {{ $attrs }}>x</x-deployer::button>', ['attrs' => $bag]);
        $this->assertStringContainsString('data-y="1"', $html);

        try {
            $this->sandbox()->allowComponent('deployer::button')->render('<x-deployer::button {{ $attrs }}>x</x-deployer::button>', ['attrs' => 'nope']);
            $this->fail('non-array, non-bag spread must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('attribute bags and arrays', $exception->getMessage());
        }
    }

    public function testJsonEncodesNestedDtosAndRejectsExcessiveNesting(): void
    {
        $json = $this->sandbox()->allowDto(TestDTO::class)->render('@json($data)', ['data' => ['dto' => new TestDTO(['name' => 'a', 'status' => 'b'])]]);
        $this->assertSame(['dto' => ['name' => 'a', 'status' => 'b']], json_decode($json, true));

        $nested = [];
        $cursor = &$nested;
        for ($i = 0; $i < 70; $i++) {
            $cursor['n'] = [];
            $cursor = &$cursor['n'];
        }

        try {
            $this->sandbox()->render('@json($data)', ['data' => $nested]);
            $this->fail('excessively nested value must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('nested too deeply', $exception->getMessage());
        }
    }

    public function testCanAndCanAnyAcceptBackedEnumAbilities(): void
    {
        Gate::define('publish-post', static fn () => true);
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1]));

        $sandbox = $this->sandbox()->allowAuthDirectives();
        $this->assertSame('yes', trim($sandbox->render('@can($ability) yes @endcan', ['ability' => RenderingTestAbility::Publish])));
        $this->assertSame('yes', trim($sandbox->render('@canany([$ability]) yes @endcanany', ['ability' => RenderingTestAbility::Publish])));
    }

    public function testMethodFieldRendersOrRejectsInvalidMethods(): void
    {
        $sandbox = $this->sandbox()->allowDirective('method');
        $this->assertSame('<input type="hidden" name="_method" value="PUT">', $sandbox->render('@method('."'put'".')'));

        try {
            $sandbox->render('@method($m)', ['m' => 'not a method!']);
            $this->fail('invalid HTTP method must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('Invalid form method', $exception->getMessage());
        }
    }

    public function testLangAndChoiceAcceptNonStringNumberArguments(): void
    {
        $sandbox = $this->sandbox()->allowDirective('lang')->allowDirective('choice');
        $this->assertSame('missing.key', trim($sandbox->render('@lang($key)', ['key' => 'missing.key'])));
        // @choice accepts anything string-convertible as the count, not just int|float|Countable.
        $this->assertIsString($sandbox->render('@choice($key, $n)', ['key' => 'missing.key', 'n' => '3']));
    }

    public function testErrorDirectiveWithNoErrorsOrAnEmptyMessage(): void
    {
        $sandbox = $this->sandbox()->allowDirective('error');
        $this->assertSame('', trim($sandbox->render("@error('field') has error @enderror")));

        $bag = new \Illuminate\Support\MessageBag();
        $this->assertSame('', trim($sandbox->render("@error('field') has error @enderror", ['errors' => $bag])));
    }

    public function testLivewireDirectiveRejectsNonStringComponentNames(): void
    {
        try {
            $this->sandbox()->allowDirective('livewire')->render('@livewire($name)', ['name' => 123]);
            $this->fail('non-string Livewire component name must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('must be strings', $exception->getMessage());
        }
    }

    public function testAllowHtmlableRendersMarkupInsideAnAttributeValue(): void
    {
        // Attribute-value context still HTML-escapes the result; the policy only decides whether the
        // Htmlable's own toHtml() may be used at all instead of throwing.
        $sandbox = $this->sandbox()->allowHtmlable(TitleHtmlable::class);
        $html = $sandbox->render('<div title="{{ $h }}"></div>', ['h' => new TitleHtmlable()]);
        $this->assertSame('<div title="&lt;b&gt;x&lt;/b&gt;"></div>', trim($html));

        $this->expectException(ForbiddenMethodException::class);
        $this->sandbox()->render('<div title="{{ $h }}"></div>', ['h' => new TitleHtmlable()]);
    }

    public function testSandboxLevelAllowLivewireComponent(): void
    {
        $sandbox = $this->sandbox()->allowLivewireComponent('counter');

        $this->assertTrue($sandbox->policy()->allowsLivewireComponent('counter'));
    }

    public function testRuntimeRejectsDirectivesWithoutAHandler(): void
    {
        $this->expectException(ForbiddenDirectiveException::class);
        $this->sandbox()->runtime()->directive('nope', []);
    }

    public function testReservedAndInvalidVariableNamesAreDroppedFromTheData(): void
    {
        $this->assertSame('1', $this->sandbox()->render('{{ $x }}', ['x' => 1, '__env' => 'nope', 'app' => 'nope', 'not valid' => 'nope', 5 => 'nope']));
    }
}

enum RenderingTestAbility: string
{
    case Publish = 'publish-post';
}

final class TitleHtmlable implements \Illuminate\Contracts\Support\Htmlable
{
    public function toHtml(): string
    {
        return '<b>x</b>';
    }
}
