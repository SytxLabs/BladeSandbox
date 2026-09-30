<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SytxLabs\BladeSandbox\Compiler\ExpressionSandboxer;
use SytxLabs\BladeSandbox\Compiler\PhpAstValidator;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;

final class ExpressionSandboxerTest extends TestCase
{
    #[DataProvider('rewrites')]
    public function testRewrites(string $expression, string $expected): void
    {
        $this->assertSame($expected, (new ExpressionSandboxer())->expression($expression, 1));
    }

    /** @return iterable<string, array{string, string}> */
    public static function rewrites(): iterable
    {
        yield 'function' => ['strlen($a)', "\$__sandbox->fn('strlen', [\$a])"];
        yield 'named args' => ['f(x: 1)', "\$__sandbox->fn('f', ['x' => 1])"];
        yield 'method' => ['$a->b(1)', "\$__sandbox->call(\$a, 'b', [1])"];
        yield 'nullsafe chain' => ['$a?->b->c', "\$__sandbox->propNullsafe(\$__sandbox->propNullsafe(\$a, 'b'), 'c')"];
        yield 'property' => ['$a->b', "\$__sandbox->prop(\$a, 'b')"];
        yield 'offset' => ["\$a['b']", "\$__sandbox->offset(\$a, 'b')"];
        yield 'isset chain' => ["isset(\$a->b['c'])", "\$__sandbox->offsetQuiet(\$__sandbox->propQuiet(\$a ?? null, 'b'), 'c') !== null"];
        yield 'isset variable' => ['isset($a)', 'isset($a)'];
        yield 'empty chain' => ['empty($a->b)', "!\$__sandbox->propQuiet(\$a ?? null, 'b')"];
        yield 'coalesce' => ["\$a->b ?? 'x'", "\$__sandbox->propQuiet(\$a ?? null, 'b') ?? 'x'"];
        yield 'concat' => ["'a' . \$b", "'a' . \$__sandbox->str(\$b)"];
        yield 'interpolation' => ['"a{$b}c"', "'a' . \$__sandbox->str(\$b) . 'c'"];
        yield 'string cast' => ['(string) $a', '$__sandbox->str($a)'];
        yield 'array cast' => ['(array) $a', '$__sandbox->toArray($a)'];
        yield 'loose comparison' => ['$a == $b', "\$__sandbox->compare('==', \$a, \$b)"];
        yield 'strict comparison' => ['$a === $b', '$a === $b'];
        yield 'constant' => ['PHP_EOL', "\$__sandbox->constant('PHP_EOL')"];
        yield 'class constant' => ['Foo\Bar::BAZ', "\$__sandbox->classConst('Foo\\Bar', 'BAZ')"];
        yield 'class name' => ['Foo::class', "'Foo'"];
        yield 'static call' => ["\\SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\Status::from('x')", "\$__sandbox->staticCall('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\Status', 'from', ['x'])"];
        yield 'spread' => ['[...$a]', '[...$__sandbox->spread($a)]'];
        yield 'destructuring' => ['[$a, $b] = $c', '[$a, $b] = $__sandbox->destructure($c)'];
        yield 'booleans' => ['true && null', 'true && null'];
        yield 'match' => ["match(\$a) { 1 => 'x', default => 'y' }", "match (\$a) {\n    1 => 'x',\n    default => 'y',\n}"];
    }

    public function testForeachAndForHeads(): void
    {
        $sandboxer = new ExpressionSandboxer();

        $this->assertSame(
            ['subject' => '$__sandbox->iterate($__sandbox->prop($a, \'items\'), false)', 'key' => '$k', 'value' => '$v'],
            $sandboxer->foreachHead('$a->items as $k => $v', 1),
        );
        $this->assertSame('$i = 0; $__sandbox->compare(\'<\', $i, $__sandbox->call($c, \'count\', [])); $i++', $sandboxer->forHead('$i = 0; $i < $c->count(); $i++', 1));
    }

    public function testForeachTargetsMustBeVariables(): void
    {
        $this->expectException(InvalidSandboxTemplateException::class);
        (new ExpressionSandboxer())->foreachHead('$a as $obj->prop', 1);
    }

    public function testArgumentsMustNotBeNamedOrSpread(): void
    {
        $this->expectException(InvalidSandboxTemplateException::class);
        (new ExpressionSandboxer())->arguments('...$views', 1);
    }

    #[DataProvider('forbiddenCompiledCode')]
    public function testClosedWorldValidator(string $compiled): void
    {
        $this->expectException(InvalidSandboxTemplateException::class);
        (new PhpAstValidator())->validate($compiled);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenCompiledCode(): iterable
    {
        yield 'function call' => ['<?php system("id"); ?>'];
        yield 'dynamic function call' => ['<?php $f(); ?>'];
        yield 'method on other object' => ['<?php $x->delete(); ?>'];
        yield 'unknown runtime method' => ['<?php $__sandbox->evaluate("x"); ?>'];
        yield 'property fetch' => ['<?php echo $x->y; ?>'];
        yield 'offset' => ['<?php echo $x["y"]; ?>'];
        yield 'static call' => ['<?php Foo::bar(); ?>'];
        yield 'new' => ['<?php new Foo(); ?>'];
        yield 'include' => ['<?php include "x"; ?>'];
        yield 'eval' => ['<?php eval("x"); ?>'];
        yield 'function declaration' => ['<?php function x() {} ?>'];
        yield 'class declaration' => ['<?php class X {} ?>'];
        yield 'closure' => ['<?php $f = function () {}; ?>'];
        yield 'return' => ['<?php return 1; ?>'];
        yield 'global' => ['<?php global $x; ?>'];
        yield 'goto' => ['<?php goto a; a: ?>'];
        yield 'loose comparison' => ['<?php echo $a == $b; ?>'];
        yield 'string cast' => ['<?php echo (string) $a; ?>'];
        yield 'extract of arbitrary data' => ['<?php extract($data); ?>'];
        yield 'superglobal' => ['<?php echo $_SERVER; ?>'];
        yield 'variable variable' => ['<?php echo $$a; ?>'];
        yield 'interpolation' => ['<?php echo "{$a}"; ?>'];
        yield 'syntax error' => ['<?php if (1): ?>'];
    }

    public function testValidatorAcceptsGeneratedScaffolding(): void
    {
        (new PhpAstValidator())->validate('<p><?php echo $__sandbox->escape($a); ?></p><?php extract($__sandbox->props([], get_defined_vars())); foreach ($__sandbox->iterate($x) as $k => $v): endforeach; ?>');
        $this->addToAssertionCount(1);
    }

    public function testEmptyDirectiveArgumentsAreAnEmptyList(): void
    {
        $this->assertSame([], (new ExpressionSandboxer())->arguments('  ', 1));
    }

    #[DataProvider('malformedDirectiveCode')]
    public function testMalformedDirectiveCodeIsRejected(string $method, string $code, string $expected): void
    {
        try {
            (new ExpressionSandboxer())->{$method}($code, 3);
            $this->fail('Expected a syntax error for: '.$code);
        } catch (InvalidSandboxTemplateException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedDirectiveCode(): iterable
    {
        yield 'arguments that are not a single call' => ['arguments', '1) + f(2', 'malformed directive arguments'];
        yield 'no assignments' => ['assignments', '', '@var expects assignments'];
        yield 'not an assignment' => ['assignments', 'foo()', '@var expects assignments'];
        yield 'php close tag' => ['assignments', '$a = 1 ?> x', 'PHP tags are not allowed in @var'];
        yield 'first-class callable' => ['expression', 'strlen(...)', 'first-class callables'];
    }
}
