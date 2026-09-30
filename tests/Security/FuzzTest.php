<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use ArgumentCountError;
use ArithmeticError;
use ArrayAccess;
use ArrayIterator;
use CompileError;
use Countable;
use Error;
use ErrorException;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;
use Traversable;
use TypeError;
use ValueError;

/**
 * Generates malicious templates from fragments plus random mutations and checks that no forbidden code
 * ever runs: the FuzzCanary object and fuzz_canary() record every call that must be impossible. Every
 * template goes through render(), the fallback, validate(), preview() and learn().
 *
 * Reproduce a failure with the reported seed; run more iterations with BLADE_SANDBOX_FUZZ_ITERATIONS.
 */
require_once __DIR__.'/../Fixtures/fuzz-canary.php';

final class FuzzTest extends TestCase
{
    private const FRAGMENTS = [
        // direct execution
        '{{ fuzz_canary() }}', '{!! fuzz_canary() !!}', '@php fuzz_canary(); @endphp', '@php(fuzz_canary())',
        '<?php fuzz_canary(); ?>', '<?= fuzz_canary() ?>', '<? fuzz_canary(); ?>', '<{{-- x --}}?php fuzz_canary(); ?>',
        '{{ \\fuzz_canary() }}', '{{ FUZZ_CANARY() }}', "{{ ('fuzz_canary')() }}", '{{ $f() }}', '{{ $s() }}',
        "{{ call_user_func('fuzz_canary') }}", "{{ array_map('fuzz_canary', [1]) }}", '{{ `id` }}', "{{ eval('fuzz_canary();') }}",
        "{{ include 'x.php' }}", '{{ (function () { fuzz_canary(); })() }}', '{{ (fn () => fuzz_canary())() }}',
        '{{ fuzz_canary(...) }}', '{{ strtoupper(...)("x") }}', '{{ trim(fuzz_canary()) }}', '{{ strtoupper($o) }}',
        // objects and magic methods
        '{{ $o }}', '{!! $o !!}', '{{ $o->boom() }}', '{{ $o?->boom() }}', '{{ $o->secret }}', '{{ $o->missing }}',
        '{{ $o->missing() }}', '{{ $o->__toString() }}', '{{ $o->__get("x") }}', '{{ $o::staticBoom() }}',
        '{{ \\SytxLabs\\BladeSandbox\\Tests\\Security\\FuzzCanary::staticBoom() }}', '{{ $cls::staticBoom() }}',
        '{{ [$o, "boom"]() }}', '{{ $o() }}', '{{ $o["x"] }}', '{{ $arr[0] }}', '{{ (string) $o }}', '{{ "$o" }}',
        '{{ "{$o}" }}', '{{ $o . "" }}', '{{ $o == "x" }}', '{{ json_encode($o) }}', '{{ serialize($o) }}',
        '{{ clone $o }}', '{{ new \\SytxLabs\\BladeSandbox\\Tests\\Security\\FuzzCanary() }}', '{{ $o instanceof Countable }}',
        '@foreach($o as $x){{ $x }}@endforeach', '@foreach($arr as $x){{ $x }}@endforeach', '@switch($o) @case("x") y @endswitch',
        '@var($v = $o) {{ $v }}', "@var\n\$w = \$f;\n@endvar{{ \$w() }}", '@json($o)', '@class([$o])', '@lang($o)',
        '{{ __($o) }}', '{{ route($o) }}', '{{ $o->safe(fuzz_canary()) }}', '{{ $o->safe($f) }}', '{{ [...$o] }}',
        '{{ list($a) = $o }}', '@each($name, $o, "x")', '@include($o)', '@include($name, ["deployment" => $o])',
        '@markdown($o)', "@markdown\n{{ \$o }}\n@endmarkdown", '{{ $o?->safe() }}', '{{ $o->safe() }}',
        // callables smuggled into allowed functions / methods
        "{{ array_map('fuzz_canary', [1]) }}", '{{ array_map($f, [1]) }}', '{{ array_map([$o, "boom"], [1]) }}',
        "{{ array_map(callback: 'fuzz_canary', array: [1]) }}", '{{ $items->map($f) }}', "{{ \$items->map('fuzz_canary') }}",
        '{{ $items->filter([$o, "boom"]) }}', '{{ $items->filter($o) }}', "{{ \$items->map('SytxLabs\\BladeSandbox\\Tests\\Security\\FuzzCanary::staticBoom') }}",
        '{{ $items->map(fn ($x) => fuzz_canary()) }}', '{{ array_map(...[$f, [1]]) }}',
        // HTML / attribute context tricks
        '<div {{ $o }}>', '<div class="{{ $o }}">', '<x-{{ $o }}>', '<{{ $s }} onclick="x">', '<a href="{{ $o }}">',
        '<div wire:click="{{ $s }}">', '<script>{{ $o }}</script>', '<!-- {{ $o }} -->', '<style>{{ $o }}</style>',
        '<x-deployer::button :type="$o">x</x-deployer::button>', '<x-dynamic-component :component="$o" />',
        // syntax noise
        '{{-- {{ fuzz_canary() }} --}}', '@verbatim {{ fuzz_canary() }} @endverbatim', '@{{ fuzz_canary() }}',
        "{{ 'x' }}", '{{ $undefined }}', '@if($o) y @endif', '@unless(fuzz_canary()) y @endunless', '{{ 1/0 }}',
    ];

    private const GLUE = ['', ' ', "\n", '<div>', '</div>', '"', "'", '<', '>', '@', '{{', '}}', '?>', '<?', '{!!', '!!}', '{{--', '--}}', '(', ')', '[', ']', ';', '$', '\\'];

    #[DataProvider('seeds')]
    public function testNoGeneratedTemplateExecutesForbiddenCode(int $seed): void
    {
        mt_srand($seed);
        $iterations = (int) (getenv('BLADE_SANDBOX_FUZZ_ITERATIONS') ?: 120);

        for ($i = 0; $i < $iterations; $i++) {
            $template = $this->generate();
            $this->check($template, $seed, $i);
        }

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 20260927] as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    public function testTheOracleDetectsExecutedCode(): void
    {
        FuzzCanary::$tripped = [];
        $GLOBALS['__fuzz_canary'] = false;

        parent::sandbox()->allowMethod(FuzzCanary::class, 'boom')->allowFunction('fuzz_canary')
            ->render('{{ $o->boom() }}{{ fuzz_canary() }}', ['o' => new FuzzCanary()]);

        $this->assertSame(['boom'], FuzzCanary::$tripped);
        $this->assertTrue($GLOBALS['__fuzz_canary']);
    }

    public function testEveryFragmentOnItsOwn(): void
    {
        foreach (self::FRAGMENTS as $index => $fragment) {
            $this->check($fragment, 0, $index);
        }

        $this->addToAssertionCount(1);
    }

    private function generate(): string
    {
        $template = '';
        $count = mt_rand(1, 4);
        for ($i = 0; $i < $count; $i++) {
            $template .= self::FRAGMENTS[mt_rand(0, count(self::FRAGMENTS) - 1)].self::GLUE[mt_rand(0, count(self::GLUE) - 1)];
        }

        // Random mutations: insert glue, delete or duplicate a character.
        $mutations = mt_rand(0, 3);
        for ($i = 0; $i < $mutations && $template !== ''; $i++) {
            $position = mt_rand(0, strlen($template) - 1);
            $template = match (mt_rand(0, 2)) {
                0 => substr($template, 0, $position).self::GLUE[mt_rand(0, count(self::GLUE) - 1)].substr($template, $position),
                1 => substr($template, 0, $position).substr($template, $position + 1),
                default => substr($template, 0, $position).$template[$position].substr($template, $position),
            };
        }

        return $template;
    }

    protected function sandbox(): Sandbox
    {
        return parent::sandbox()
            ->allowFunction('strtoupper', 'trim', 'number_format', 'array_map')
            ->allowMethod(\Illuminate\Support\Collection::class, ['map', 'filter'])
            ->allowMethod(FuzzCanary::class, 'safe')
            ->allowProperty(FuzzCanary::class, 'safe')
            ->allowRawEcho()
            ->allowMarkdown()
            ->allowView('deployer::**')
            ->allowComponent('deployer::*');
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'o' => new FuzzCanary(),
            'arr' => [new FuzzCanary()],
            'items' => collect([1, 2]),
            'f' => 'fuzz_canary',
            's' => 'fuzz_canary',
            'cls' => FuzzCanary::class,
            'name' => 'deployer::partials.header',
            'deployment' => (object) ['status' => 'x', 'name' => 'y'],
        ];
    }

    private function check(string $template, int $seed, int $iteration): void
    {
        $steps = [
            'render' => fn () => $this->sandbox()->render($template, $this->data()),
            'fallback' => fn () => $this->sandbox()->renderFallback('strip')->render($template, $this->data()),
            'validate' => fn () => $this->sandbox()->validate($template),
            'preview' => fn () => $this->sandbox()->preview($template, $this->data()),
            'learn' => fn () => $this->sandbox()->learn($template, $this->data()),
        ];

        foreach ($steps as $step => $run) {
            FuzzCanary::$tripped = [];
            $GLOBALS['__fuzz_canary'] = false;
            $level = ob_get_level();

            try {
                $run();
            } catch (Throwable $exception) {
                $this->assertAcceptable($exception, $template, $step, $seed, $iteration);
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }

            $this->assertSame([], FuzzCanary::$tripped, self::report('forbidden object code ran', $template, $step, $seed, $iteration));
            $this->assertFalse($GLOBALS['__fuzz_canary'], self::report('fuzz_canary() ran', $template, $step, $seed, $iteration));
        }
    }

    private function assertAcceptable(Throwable $exception, string $template, string $step, int $seed, int $iteration): void
    {
        if ($exception instanceof ParseError || $exception instanceof CompileError) {
            $this->fail(self::report('the compiler produced invalid PHP: '.$exception->getMessage(), $template, $step, $seed, $iteration));
        }

        $expected = $exception instanceof SandboxException || $exception instanceof ErrorException
            || $exception instanceof TypeError || $exception instanceof ValueError || $exception instanceof ArithmeticError
            || $exception instanceof ArgumentCountError || $exception instanceof InvalidArgumentException;

        if (! $expected && $exception instanceof Error) {
            $this->fail(self::report('unexpected '.$exception::class.': '.$exception->getMessage(), $template, $step, $seed, $iteration));
        }
    }

    private static function report(string $problem, string $template, string $step, int $seed, int $iteration): string
    {
        return sprintf("%s during %s (seed %d, iteration %d):\n%s", $problem, $step, $seed, $iteration, $template);
    }
}

/**
 * Every method except safe() must be unreachable from templates.
 *
 * @implements IteratorAggregate<int, string>
 * @implements ArrayAccess<mixed, mixed>
 */
final class FuzzCanary implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<string> */
    public static array $tripped = [];

    public string $safe = 'safe';

    public string $secret = 'secret';

    public function __get(string $name): mixed
    {
        return self::trip('__get');
    }

    public function __isset(string $name): bool
    {
        return (bool) self::trip('__isset');
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return self::trip('__call');
    }

    /** @param array<int, mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::trip('__callStatic');
    }

    public function __invoke(): mixed
    {
        return self::trip('__invoke');
    }

    public function __toString(): string
    {
        return (string) self::trip('__toString');
    }

    public function safe(mixed $argument = null): string
    {
        return 'ok';
    }

    public function boom(): string
    {
        return (string) self::trip('boom');
    }

    public static function staticBoom(): string
    {
        return (string) self::trip('staticBoom');
    }

    public function getIterator(): Traversable
    {
        self::trip('getIterator');

        return new ArrayIterator([]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return (bool) self::trip('offsetExists');
    }

    public function offsetGet(mixed $offset): mixed
    {
        return self::trip('offsetGet');
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        self::trip('offsetSet');
    }

    public function offsetUnset(mixed $offset): void
    {
        self::trip('offsetUnset');
    }

    public function count(): int
    {
        return (int) self::trip('count');
    }

    public function jsonSerialize(): mixed
    {
        return self::trip('jsonSerialize');
    }

    private static function trip(string $what): string
    {
        self::$tripped[] = $what;

        return 'TRIPPED';
    }
}
