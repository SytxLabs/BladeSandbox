<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayAccess;
use ArrayObject;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Stringable;
use Illuminate\View\ComponentAttributeBag;
use stdClass;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class ObjectConversionTest extends TestCase
{
    public function testOffsetOnNullOrScalarContainerReturnsNull(): void
    {
        $this->assertSame('', $this->sandbox()->render('{{ $n[0] }}', ['n' => 5]));
    }

    public function testOffsetOnAResourceIsRejected(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $this->sandbox()->render('{{ $r[0] }}', ['r' => $resource]);
            $this->fail('indexing a resource must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('as an array', $exception->getMessage());
        } finally {
            fclose($resource);
        }
    }

    public function testQuietOffsetOnAnAllowedArrayAccessObject(): void
    {
        $sandbox = $this->sandbox()->allowArrayAccess(ArrayObject::class)->allowIteration(ArrayObject::class);
        $bag = new ArrayObject(['a' => 1]);

        $this->assertSame('none', trim($sandbox->render("{{ \$bag['missing'] ?? 'none' }}", ['bag' => $bag])));
        $this->assertSame('1', trim($sandbox->render("{{ \$bag['a'] ?? 'none' }}", ['bag' => $bag])));
    }

    public function testIteratingAScalarOrAPlainObjectIsHandled(): void
    {
        try {
            $this->sandbox()->render('@foreach($n as $x){{ $x }}@endforeach', ['n' => 5]);
            $this->fail('iterating a scalar must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('foreach()', $exception->getMessage());
        }

        $this->assertSame('1', trim($this->sandbox()->render('@foreach($o as $v){{ $v }}@endforeach', ['o' => (object) ['a' => 1]])));
    }

    public function testStringConversionOfAResourceIsRejected(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $this->sandbox()->render('{{ $r }}', ['r' => $resource]);
            $this->fail('converting a resource to string must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('Cannot convert', $exception->getMessage());
        } finally {
            fclose($resource);
        }
    }

    public function testStringConversionOfAnAllowedStringableClass(): void
    {
        $sandbox = $this->sandbox()->allowStringConversion(Stringable::class);
        $this->assertSame('hi', trim($sandbox->render('{{ $s }}', ['s' => new Stringable('hi')])));
    }

    public function testToArrayCastPassesArraysThroughAndConvertsScalarsAndDtos(): void
    {
        $this->assertSame('a', trim($this->sandbox()->render('@foreach((array) $x as $v){{ $v }}@endforeach', ['x' => ['a']])));
        $this->assertSame('5', trim($this->sandbox()->render('@foreach((array) $x as $v){{ $v }}@endforeach', ['x' => 5])));

        $dto = new TestDTO(['name' => 'api', 'status' => 'ok']);
        $sandbox = $this->sandbox()->allowDto(TestDTO::class);
        $this->assertSame('api,ok,', $sandbox->render('@foreach((array) $x as $v){{ $v }},@endforeach', ['x' => $dto]));

        $this->assertSame('1', trim($this->sandbox()->render('@foreach((array) $x as $v){{ $v }}@endforeach', ['x' => (object) ['a' => 1]])));
    }

    public function testDestructuringANonArrayItemIsRejected(): void
    {
        try {
            $this->sandbox()->render('@foreach($items as [$a, $b]){{ $a }}@endforeach', ['items' => [5]]);
            $this->fail('destructuring a scalar must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('destructured', $exception->getMessage());
        }
    }

    public function testSpreadingAnAssociativeIterableKeepsStringKeys(): void
    {
        $json = $this->sandbox()->render('@json([...$x])', ['x' => ['a' => 1]]);

        $this->assertSame(['a' => 1], json_decode($json, true));
    }

    public function testComparisonOperatorsCoverAllCases(): void
    {
        $sandbox = $this->sandbox();
        $this->assertSame('1', trim($sandbox->render('{{ (int) ($a == $b) }}', ['a' => 1, 'b' => 1])));
        $this->assertSame('1', trim($sandbox->render('{{ (int) ($a != $b) }}', ['a' => 1, 'b' => 2])));
        $this->assertSame('1', trim($sandbox->render('{{ (int) ($a <= $b) }}', ['a' => 1, 'b' => 1])));
        $this->assertSame('0', trim($sandbox->render('{{ $a <=> $b }}', ['a' => 1, 'b' => 1])));
    }

    public function testVariadicParametersAreCheckedPastTheDeclaredPositions(): void
    {
        $sandbox = $this->sandbox()->allowFunction('array_merge');

        $html = $sandbox->allowFunction('json_encode')->render('{{ json_encode(array_merge($a, $b)) }}', ['a' => ['a', 'b', 'x'], 'b' => ['c']]);
        $this->assertSame(['a', 'b', 'x', 'c'], json_decode(html_entity_decode($html, ENT_QUOTES), true));
    }

    public function testCallingAMethodOnANonObjectIsRejected(): void
    {
        try {
            $this->sandbox()->render('{{ $n->foo() }}', ['n' => 5]);
            $this->fail('calling a method on a non-object must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('Call to a member function', $exception->getMessage());
        }
    }

    public function testStaticCallOnAnUnknownClassIsRejected(): void
    {
        try {
            $this->sandbox()->render('{{ NoSuchClassXyz::foo() }}');
            $this->fail('a static call on a non-existent class must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('NoSuchClassXyz::foo()', $exception->getMessage());
        }

        try {
            $this->sandbox()->allowStaticMethod('AnotherMissingClassXyz', 'foo')->render('{{ AnotherMissingClassXyz::foo() }}');
            $this->fail('a static call on a permitted but non-existent class must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('unknown class', $exception->getMessage());
        }
    }

    public function testGlobalConstantsCanBeAllowed(): void
    {
        $this->assertSame(PHP_EOL, $this->sandbox()->allowConstant('PHP_EOL')->render('{{ PHP_EOL }}'));
    }

    public function testArrayAccessOnAnAllowedButUnrelatedClassIsStillDenied(): void
    {
        $sandbox = $this->sandbox()->allowArrayAccess(ArrayObject::class);

        try {
            $sandbox->render('{{ $x[0] }}', ['x' => new UnrelatedArrayAccess()]);
            $this->fail('an unrelated ArrayAccess class must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('offsetGet', $exception->getMessage());
        }
    }

    public function testExplicitlyAllowedMagicMethodNamesAreStillNeverCallable(): void
    {
        try {
            $this->sandbox()->render('{{ $o->__undeclaredMagic() }}', ['o' => new stdClass()]);
            $this->fail('an undeclared dunder-prefixed method call must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('__undeclaredMagic', $exception->getMessage());
        }
    }

    public function testReadingAPropertyOnNullOrAnArrayOrAResource(): void
    {
        try {
            $this->sandbox()->render('{{ $n->x }}', ['n' => null]);
            $this->fail('reading a property on null must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('on null', $exception->getMessage());
        }

        $this->assertSame('0', trim($this->sandbox()->render('{{ isset($arr->x) ? 1 : 0 }}', ['arr' => ['a' => 1]])));

        try {
            $this->sandbox()->render('{{ $arr->x }}', ['arr' => ['a' => 1]]);
            $this->fail('reading a property on an array must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('on array', $exception->getMessage());
        }
    }

    public function testInvalidLoopPropertyAndQuietPropertyOnAnAllowedObject(): void
    {
        try {
            $this->sandbox()->render('@foreach([1] as $x){{ $loop->bogus }}@endforeach');
            $this->fail('an invalid $loop property must be rejected');
        } catch (SandboxException) {
            $this->addToAssertionCount(1);
        }

        $sandbox = $this->sandbox()->allowProperty(NullablePropertyHolder::class, 'value');
        $this->assertSame('none', trim($sandbox->render("{{ \$o->value ?? 'none' }}", ['o' => new NullablePropertyHolder()])));
    }

    public function testTransChoiceFunctionAcceptsNonNumericCounts(): void
    {
        $sandbox = $this->sandbox()->allowFunction('trans_choice');
        $this->assertSame('missing.key', trim($sandbox->render("{{ trans_choice('missing.key', '3') }}")));
    }

    public function testLooselyComparingDeeplyNestedArraysIsRejected(): void
    {
        $nested = 1;
        for ($i = 0; $i < 35; $i++) {
            $nested = [$nested];
        }

        try {
            $this->sandbox()->render('@if($a == $b) x @endif', ['a' => $nested, 'b' => $nested]);
            $this->fail('deeply nested comparison must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('nested too deeply', $exception->getMessage());
        }
    }

    public function testArraysCannotBeConvertedToStrings(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Array to string conversion.');
        $this->sandbox()->render('{{ $a }}', ['a' => [1]]);
    }

    public function testAttributeBagsAreCheckedWhenConvertedToStrings(): void
    {
        $sandbox = $this->sandbox()->allowStringConversion(ComponentAttributeBag::class)->allowRawEcho();
        $this->assertSame('class="a"', $sandbox->render('{!! $bag !!}', ['bag' => new ComponentAttributeBag(['class' => 'a'])]));

        $htmlable = new class implements Htmlable
        {
            public function toHtml(): string
            {
                return 'x';
            }
        };
        $bag = new ComponentAttributeBag(['title' => $htmlable]);
        try {
            $sandbox->render('{!! $bag !!}', ['bag' => $bag]);
            $this->fail('Expected the Htmlable attribute to be denied.');
        } catch (SecurityViolationException $exception) {
            $this->assertSame('method', $exception->capability());
        }

        $this->assertSame('title="x"', $sandbox->render('{!! $bag !!}', ['bag' => new ComponentAttributeBag(['title' => new HtmlString('x')])]));
    }

    public function testArraysCanBeComparedLoosely(): void
    {
        $this->assertSame('same', trim($this->sandbox()->render('@if($a == $b) same @endif', ['a' => [1, [2]], 'b' => [1, [2]]])));
    }
}

final class UnrelatedArrayAccess implements ArrayAccess
{
    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

final class NullablePropertyHolder
{
    public ?string $value = null;
}
