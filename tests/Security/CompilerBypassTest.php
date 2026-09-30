<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentAttributeBag;
use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;

/**
 * Regression tests for bypasses found while attacking the sandbox (see SECURITY.md).
 */
final class CompilerBypassTest extends SecurityTestCase
{
    private function secretObject(): object
    {
        return new class
        {
            public function __toString(): string
            {
                $GLOBALS['__blade_sandbox_side_effect'] = true;

                return 'LEAKED';
            }
        };
    }

    #[DataProvider('phpTagSplicing')]
    public function testPhpTagsCannotBeSplicedTogether(string $template): void
    {
        $GLOBALS['__blade_sandbox_side_effect'] = false;

        try {
            $output = $this->sandbox()->render($template, ['o' => $this->secretObject()]);
            $this->assertStringNotContainsString('LEAKED', $output);
        } catch (SecurityViolationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($GLOBALS['__blade_sandbox_side_effect']);
    }

    /** @return iterable<string, array{string}> */
    public static function phpTagSplicing(): iterable
    {
        // A Blade comment / escaped echo / verbatim block between "<" and "?" must not create a PHP tag.
        yield 'comment between < and ?=' => ['<{{-- x --}}?= $o ?>'];
        yield 'comment between < and ?php' => ['<{{-- x --}}?php echo $o; ?>'];
        yield 'verbatim boundary' => ['<@verbatim?= $o ?>@endverbatim'];
        yield 'at escape boundary' => ['<@@?= $o ?>'];
        yield 'directive boundary' => ['<@if(true)?= $o ?>@endif'];
    }

    public function testSwitchDoesNotConvertObjectsImplicitly(): void
    {
        $this->assertBlocked('@switch($o) @case("x") y @endswitch', ['o' => $this->secretObject()], ForbiddenMethodException::class);
        $this->assertBlocked('@switch("x") @case($o) y @endswitch', ['o' => $this->secretObject()], ForbiddenMethodException::class);
        $this->assertSame(' two ', $this->sandbox()->render('@switch($v) @case(1) one @break @case(2) two @break @endswitch', ['v' => 2]));
    }

    public function testRuntimeHelpersDoNotCastObjectsToString(): void
    {
        $sandbox = $this->sandbox()->allowDirective('lang')->allowDirective('choice')->allowDirective('error');

        $this->assertBlocked('@lang($o)', ['o' => $this->secretObject()], ForbiddenMethodException::class, $sandbox);
        $this->assertBlocked('@choice($o, 1)', ['o' => $this->secretObject()], ForbiddenMethodException::class, $sandbox);
        $this->assertBlocked("@each('deployer::partials.header', [], \$o)", ['o' => $this->secretObject()], sandbox: $sandbox->allowView('deployer::partials.header'));
        $this->assertBlocked('@error($o) x @enderror', ['o' => $this->secretObject(), 'errors' => new ViewErrorBag()], ForbiddenMethodException::class, $sandbox);
    }

    public function testObjectsInAttributeBagsAreNotStringifiedImplicitly(): void
    {
        $this->createUsers();
        $sandbox = $this->sandbox()->allowComponent('deployer::button');

        $this->assertBlocked('<x-deployer::button :data-user="$u" />', ['u' => User::query()->first()], ForbiddenMethodException::class, $sandbox);
        $this->assertBlocked('<x-deployer::button :data-x="$o" />', ['o' => $this->secretObject()], ForbiddenMethodException::class, $sandbox);
    }

    public function testClassComponentAttributeBagValues(): void
    {
        $this->assertBlocked('<div {{ $bag }}></div>', ['bag' => new ComponentAttributeBag(['title' => $this->secretObject()])], ForbiddenMethodException::class);
    }

    public function testAttributeBagsCannotInjectAttributes(): void
    {
        View::addNamespace('attack', __DIR__.'/../Fixtures/views/attack');
        $sandbox = $this->sandbox()->allowComponent('attack::*');

        // Bound values are escaped for the bag (like Blade) but reach props unescaped.
        $html = $sandbox->render('<x-attack::plain :title="$t" :label="$t" />', ['t' => '" onmouseover="x']);
        $this->assertStringContainsString('title="&quot; onmouseover=&quot;x"', $html);
        $this->assertStringContainsString('>&quot; onmouseover=&quot;x</div>', $html);

        // Unescaped values that would break out of the attribute are rejected.
        $this->assertBlocked('<x-attack::merge :evil="$t" />', ['t' => 'x" wire:click="deleteEverything'], SecurityViolationException::class, $sandbox);
        $this->assertBlocked("<x-attack::plain title='x \" wire:click=\"deleteEverything' />", [], SecurityViolationException::class, $sandbox);
        $this->assertBlocked('<div {{ $bag }}></div>', ['bag' => new ComponentAttributeBag(['x wire:click' => 'y'])], SecurityViolationException::class);
        $this->assertBlocked('{{ $bag }}', ['bag' => new ComponentAttributeBag(['a' => '<button wire:click=deleteEverything>'])], SecurityViolationException::class);
    }
}
