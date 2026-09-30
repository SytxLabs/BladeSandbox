<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;

final class PhpExecutionTest extends SecurityTestCase
{
    #[DataProvider('payloads')]
    public function testPhpExecutionIsBlocked(string $template, string $exception): void
    {
        $this->assertBlocked($template, ['x' => new stdClass(), 'f' => 'system', 'callable' => 'system', 'a' => 1, 'b' => 2, 'class' => 'X'], $exception);
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function payloads(): iterable
    {
        yield '@php block' => ["@php\n    system('id');\n@endphp", ForbiddenDirectiveException::class];
        yield '@php inline' => ["@php(system('id'))", ForbiddenDirectiveException::class];
        yield 'raw php tag' => ["<?php system('id'); ?>", InvalidSandboxTemplateException::class];
        yield 'short echo tag' => ["<?= system('id') ?>", InvalidSandboxTemplateException::class];
        yield 'system' => ["{{ system('id') }}", ForbiddenFunctionException::class];
        yield 'exec' => ["{{ exec('id') }}", ForbiddenFunctionException::class];
        yield 'shell_exec' => ["{{ shell_exec('id') }}", ForbiddenFunctionException::class];
        yield 'passthru' => ["{{ passthru('id') }}", ForbiddenFunctionException::class];
        yield 'proc_open' => ["{{ proc_open('id', [], []) }}", ForbiddenFunctionException::class];
        yield 'popen' => ["{{ popen('id', 'r') }}", ForbiddenFunctionException::class];
        yield 'namespaced system' => ["{{ \\system('id') }}", ForbiddenFunctionException::class];
        yield 'uppercase system' => ["{{ SYSTEM('id') }}", ForbiddenFunctionException::class];
        yield 'call_user_func' => ["{{ call_user_func('system', 'id') }}", ForbiddenFunctionException::class];
        yield 'array_map callback' => ["{{ array_map('system', ['id']) }}", ForbiddenFunctionException::class];
        yield 'eval' => ["{{ eval('system(\"id\");') }}", InvalidSandboxTemplateException::class];
        yield 'assert' => ["{{ assert('system(\"id\")') }}", ForbiddenFunctionException::class];
        yield 'create_function style' => ["{{ create_function('', 'system(1);') }}", ForbiddenFunctionException::class];
        yield 'include' => ["{{ include('/etc/passwd') }}", InvalidSandboxTemplateException::class];
        yield 'require' => ["{{ require('/etc/passwd') }}", InvalidSandboxTemplateException::class];
        yield 'include_once' => ["{{ include_once '/etc/passwd' }}", InvalidSandboxTemplateException::class];
        yield 'backticks' => ['{{ `id` }}', InvalidSandboxTemplateException::class];
        yield 'variable function' => ['{{ $f("id") }}', InvalidSandboxTemplateException::class];
        yield 'string callable' => ["{{ 'system'('id') }}", InvalidSandboxTemplateException::class];
        yield 'variable variable' => ['{{ ${"f"}("id") }}', InvalidSandboxTemplateException::class];
        yield 'closure' => ['{{ (function () { system("id"); })() }}', InvalidSandboxTemplateException::class];
        yield 'arrow function' => ['{{ (fn () => system("id"))() }}', InvalidSandboxTemplateException::class];
        yield 'static closure' => ['{{ (static fn () => 1)() }}', InvalidSandboxTemplateException::class];
        yield 'first class callable' => ['{{ system(...) }}', InvalidSandboxTemplateException::class];
        yield 'variable first class callable' => ['{{ $callable(...) }}', InvalidSandboxTemplateException::class];
        yield 'method first class callable' => ['{{ $x->format(...) }}', InvalidSandboxTemplateException::class];
        yield 'invoke callable via call_user_func_array' => ["{{ call_user_func_array(\$callable, ['id']) }}", ForbiddenFunctionException::class];
        yield 'new' => ['{{ new ReflectionClass("App") }}', InvalidSandboxTemplateException::class];
        yield 'anonymous class' => ['{{ new class { } }}', InvalidSandboxTemplateException::class];
        yield 'reflection function' => ["{{ (new ReflectionFunction('system'))->invoke('id') }}", InvalidSandboxTemplateException::class];
        yield 'exit' => ['{{ exit(1) }}', InvalidSandboxTemplateException::class];
        yield 'die' => ['{{ die() }}', InvalidSandboxTemplateException::class];
        yield 'print' => ['{{ print("<script>") }}', InvalidSandboxTemplateException::class];
        yield 'yield' => ['{{ yield 1 }}', InvalidSandboxTemplateException::class];
        yield 'throw' => ['{{ throw new Exception() }}', InvalidSandboxTemplateException::class];
        yield 'magic constant' => ['{{ __FILE__ }}', InvalidSandboxTemplateException::class];
        yield 'magic dir' => ['{{ __DIR__ }}', InvalidSandboxTemplateException::class];
        yield 'error suppression' => ['{{ @system("id") }}', InvalidSandboxTemplateException::class];
        yield 'statement injection' => ["{{ 1); system('id'); (1 }}", InvalidSandboxTemplateException::class];
        yield 'close tag injection' => ["{{ 1 ?><?php system('id') }}", InvalidSandboxTemplateException::class];
        yield 'reserved runtime variable' => ["{{ \$__sandbox->fn('system', ['id']) }}", InvalidSandboxTemplateException::class];
        yield 'reserved data variable' => ['{{ $__data }}', InvalidSandboxTemplateException::class];
        yield 'reserved env variable' => ["{{ \$__env->make('secret') }}", InvalidSandboxTemplateException::class];
        yield 'this' => ['{{ $this }}', InvalidSandboxTemplateException::class];
        yield 'globals' => ["{{ \$GLOBALS['x'] }}", InvalidSandboxTemplateException::class];
        yield 'server superglobal' => ["{{ \$_SERVER['PATH'] }}", InvalidSandboxTemplateException::class];
        yield 'env superglobal' => ['{{ $_ENV }}', InvalidSandboxTemplateException::class];
        yield 'getenv' => ["{{ getenv('APP_KEY') }}", ForbiddenFunctionException::class];
        yield 'env helper' => ["{{ env('APP_KEY') }}", ForbiddenFunctionException::class];
        yield 'file_get_contents' => ["{{ file_get_contents('/etc/passwd') }}", ForbiddenFunctionException::class];
        yield 'file_put_contents' => ["{{ file_put_contents('/tmp/x', 'y') }}", ForbiddenFunctionException::class];
        yield 'unlink' => ["{{ unlink('/tmp/x') }}", ForbiddenFunctionException::class];
        yield 'phpinfo' => ['{{ phpinfo() }}', ForbiddenFunctionException::class];
        yield 'ini_set' => ["{{ ini_set('display_errors', '1') }}", ForbiddenFunctionException::class];
        yield 'constant function' => ["{{ constant('PHP_OS') }}", ForbiddenFunctionException::class];
        yield 'global constant' => ['{{ PHP_OS }}', ForbiddenPropertyException::class];
        yield 'class constant' => ['{{ \Illuminate\Foundation\Application::VERSION }}', ForbiddenPropertyException::class];
        yield 'relative class constant' => ['{{ static::X }}', InvalidSandboxTemplateException::class];
        yield 'dynamic class constant' => ['{{ $class::X }}', InvalidSandboxTemplateException::class];
        yield 'serialize' => ['{{ serialize($x) }}', ForbiddenFunctionException::class];
        yield 'unserialize' => ["{{ unserialize('O:1:\"A\":0:{}') }}", ForbiddenFunctionException::class];
        yield 'pipe operator' => ["{{ 'id' |> 'system' }}", InvalidSandboxTemplateException::class];
        yield 'object cast' => ['{{ (object) [] }}', InvalidSandboxTemplateException::class];
        yield 'clone' => ['{{ clone $x }}', InvalidSandboxTemplateException::class];
        yield 'reference assignment' => ['{{ $a = &$b }}', InvalidSandboxTemplateException::class];
        yield 'unbalanced blade' => ['@if(true) x', InvalidSandboxTemplateException::class];
        yield 'text between switch and case' => ["@switch(1) {{ system('id') }} @case(1) @endswitch", InvalidSandboxTemplateException::class];
        yield 'php inside switch head' => ["@switch(1) system('id'); @case(1) @endswitch", InvalidSandboxTemplateException::class];
    }

    public function testBlockIsStructuralNotAStringBlacklist(): void
    {
        // Harmless text that contains dangerous words renders fine.
        $this->assertSame('system(&#039;id&#039;) eval exec', $this->sandbox()->render('{{ $t }} eval exec', ['t' => "system('id')"]));
        $this->assertSame('system is fine as text', $this->sandbox()->render('system is fine as text'));
    }
}
