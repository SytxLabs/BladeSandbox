<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Support\Macros;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class MacroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::macro('sandboxShout', function (): Collection {
            /** @var Collection<int, string> $this */
            return $this->map(static fn (string $value): string => strtoupper($value));
        });
        Collection::macro('sandboxApply', function (mixed $callback): Collection {
            /** @var Collection<int, mixed> $this */
            return $this->map($callback);
        });
        Str::macro('sandboxInitials', static fn (string $name): string => implode('', array_map(
            static fn (string $part): string => mb_substr($part, 0, 1),
            preg_split('/\s+/', trim($name)) ?: [],
        )));
    }

    protected function tearDown(): void
    {
        Sandbox::flushMacros();

        parent::tearDown();
    }

    public function testInstanceMacrosAreDeniedByDefault(): void
    {
        $this->expectException(ForbiddenMethodException::class);
        $this->expectExceptionMessage('macro not allowed');

        $this->sandbox()->render('{{ $items->sandboxShout()->implode(",") }}', ['items' => collect(['a', 'b'])]);
    }

    public function testAllowedInstanceMacro(): void
    {
        $html = $this->sandbox()
            ->allowMacro(Collection::class, 'sandboxShout')
            ->allowMethod(Collection::class, 'implode')
            ->render('{{ $items->sandboxShout()->implode(",") }}', ['items' => collect(['a', 'b'])]);

        $this->assertSame('A,B', $html);
    }

    public function testWildcardAllowsEveryMacroOfTheClassOnly(): void
    {
        $sandbox = $this->sandbox()->allowMacro(Collection::class);

        Collection::macro('sandboxLater', fn (): int => 42);
        $this->assertSame('42', $sandbox->render('{{ $items->sandboxLater() }}', ['items' => collect()]));

        $this->expectException(ForbiddenMethodException::class);
        $sandbox->render('{{ \Illuminate\Support\Str::sandboxInitials("Ada Lovelace") }}');
    }

    public function testMacroArgumentsAreStillCheckedForCallables(): void
    {
        $this->expectException(SecurityViolationException::class);

        $this->sandbox()
            ->allowMacro(Collection::class, 'sandboxApply')
            ->render('{{ $items->sandboxApply("system")->implode(",") }}', ['items' => collect(['id'])]);
    }

    public function testStaticMacros(): void
    {
        $sandbox = $this->sandbox()->allowMacro(Str::class, 'sandboxInitials');

        $this->assertSame('AL', $sandbox->render('{{ \Illuminate\Support\Str::sandboxInitials($name) }}', ['name' => 'Ada Lovelace']));
        $this->assertTrue($sandbox->validate('{{ \Illuminate\Support\Str::sandboxInitials($name) }}')->passes());
    }

    public function testStaticMethodRuleDoesNotCoverStaticMacros(): void
    {
        $this->expectException(ForbiddenMethodException::class);

        $this->sandbox()
            ->allowStaticMethod(Str::class, 'sandboxInitials')
            ->render('{{ \Illuminate\Support\Str::sandboxInitials("Ada") }}');
    }

    public function testMacroRuleDoesNotAllowDeclaredMethods(): void
    {
        $this->expectException(ForbiddenMethodException::class);

        $this->sandbox()->allowMacro(Str::class, 'upper')->render('{{ \Illuminate\Support\Str::upper("x") }}');
    }

    public function testMagicMethodsCannotBeAllowedAsMacros(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sandbox()->allowMacro(Collection::class, '__call');
    }

    public function testWildcardMethodRulesDoNotReachCall(): void
    {
        $proxy = new MagicProxy();

        $this->assertSame('declared', $this->sandbox()->allowMethod(MagicProxy::class, '*')->render('{{ $p->declared() }}', ['p' => $proxy]));

        try {
            $this->sandbox()->allowMethod(MagicProxy::class, '*')->render('{{ $p->anything() }}', ['p' => $proxy]);
            $this->fail('A "*" rule must not reach __call().');
        } catch (ForbiddenMethodException $exception) {
            $this->assertStringContainsString('not a declared public method', $exception->getMessage());
        }

        // An explicitly named method is still allowed to reach __call().
        $this->assertSame('magic:anything', $this->sandbox()->allowMethod(MagicProxy::class, 'anything')->render('{{ $p->anything() }}', ['p' => $proxy]));
    }

    public function testClassNamespaceDoesNotReachCall(): void
    {
        $this->expectException(ForbiddenMethodException::class);

        $this->sandbox()
            ->allowClassNamespace(__NAMESPACE__)
            ->render('{{ $p->anything() }}', ['p' => new MagicProxy()]);
    }

    public function testSandboxApiIsMacroable(): void
    {
        Sandbox::macro('sandboxCollections', function (): Sandbox {
            /** @var Sandbox $this */
            return $this->allowMacro(Collection::class, 'sandboxShout')->allowFunction('strtoupper');
        });

        $html = $this->sandbox()
            ->sandboxCollections()
            ->allowMethod(Collection::class, 'implode')
            ->render('{{ $items->sandboxShout()->implode(",") }}-{{ strtoupper("x") }}', ['items' => collect(['a'])]);

        $this->assertSame('A-X', $html);
    }

    public function testConfigPresetsAndMacros(): void
    {
        Sandbox::macro('sandboxPreset', function (): Sandbox {
            /** @var Sandbox $this */
            return $this->allowFunction('strtoupper');
        });

        $sandbox = PolicyConfiguration::apply($this->sandbox(), [
            'presets' => ['sandboxPreset'],
            'methods' => [Collection::class => ['implode']],
            'macros' => [Collection::class => ['sandboxShout'], Str::class => '*'],
        ]);

        $this->assertSame(
            'A|AL|X',
            $sandbox->render('{{ $items->sandboxShout()->implode(",") }}|{{ \Illuminate\Support\Str::sandboxInitials("Ada Lovelace") }}|{{ strtoupper("x") }}', ['items' => collect(['a'])]),
        );
    }

    public function testUnknownPresetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown sandbox preset');

        PolicyConfiguration::apply($this->sandbox(), ['presets' => ['doesNotExist']]);
    }

    public function testMacrosChangeThePolicyFingerprint(): void
    {
        $this->assertNotSame(
            $this->sandbox()->policy()->fingerprint(),
            $this->sandbox()->allowMacro(Collection::class, 'sandboxShout')->policy()->fingerprint(),
        );
    }

    public function testMacrosHasHandlesNonStandardAndThrowingHasMacro(): void
    {
        $this->assertFalse(Macros::has(InstanceHasMacro::class, 'x'));
        $this->assertFalse(Macros::has(ThrowingHasMacro::class, 'x'));
    }

    public function testMacrosParametersReturnsNullForAnUnknownMacroName(): void
    {
        $this->assertNull(Macros::parameters(Collection::class, 'noSuchMacroXyz'));
    }

    public function testMacrosCallableHandlesNonArrayOrNonStaticMacrosProperty(): void
    {
        $this->assertNull(Macros::parameters(InstanceMacrosProperty::class, 'x'));
        $this->assertNull(Macros::parameters(NonArrayMacrosProperty::class, 'x'));
    }

    public function testMacroLookupsIgnoreUnknownMacros(): void
    {
        $this->assertFalse(Macros::has(stdClass::class, 'nothing'));
        $this->assertNull(Macros::parameters(Collection::class, 'definitelyNotAMacro'));
    }

    public function testMacroParametersOfClassesWithoutMacrosAreUnknown(): void
    {
        $this->assertNull(Macros::parameters(stdClass::class, 'nothing'));
    }
}

final class InstanceHasMacro
{
    public function hasMacro(string $name): bool
    {
        return true;
    }
}

final class ThrowingHasMacro
{
    public static function hasMacro(string $name): bool
    {
        throw new RuntimeException('boom');
    }
}

final class InstanceMacrosProperty
{
    /** @var array<string, callable> */
    public array $macros = [];

    public static function hasMacro(string $name): bool
    {
        return true;
    }
}

final class NonArrayMacrosProperty
{
    public static string $macros = 'not-an-array';

    public static function hasMacro(string $name): bool
    {
        return true;
    }
}

final class MagicProxy
{
    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): string
    {
        return 'magic:'.$name;
    }

    public function declared(): string
    {
        return 'declared';
    }
}
