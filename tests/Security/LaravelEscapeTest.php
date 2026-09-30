<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;

final class LaravelEscapeTest extends SecurityTestCase
{
    #[DataProvider('payloads')]
    public function testContainerFacadesAndHelpersAreNotAvailable(string $template, string $exception): void
    {
        $this->assertBlocked($template, [], $exception);
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function payloads(): iterable
    {
        foreach (['app', 'resolve', 'config', 'request', 'auth', 'session', 'cache', 'logger', 'dispatch', 'event', 'bcrypt', 'encrypt', 'decrypt', 'storage_path', 'base_path', 'redirect', 'response', 'cookie', 'abort', 'view', 'dd', 'dump', 'value', 'tap', 'with', 'rescue', 'retry', 'data_get', 'optional', 'collect', 'now'] as $helper) {
            yield $helper.'()' => ['{{ '.$helper.'() }}', ForbiddenFunctionException::class];
        }

        yield 'app()->make' => ["{{ app()->make('config') }}", ForbiddenFunctionException::class];
        yield 'app(abstract)' => ["{{ app('db')->table('users')->delete() }}", ForbiddenFunctionException::class];
        yield 'config value' => ["{{ config('app.key') }}", ForbiddenFunctionException::class];

        foreach (['Cache::get("x")', 'DB::table("users")', 'Storage::get("x")', 'Artisan::call("down")', 'App::make("config")', 'Illuminate\Support\Facades\DB::select("select 1")', '\Illuminate\Container\Container::getInstance()', 'Auth::user()', 'Session::all()', 'Crypt::decrypt("x")', 'Blade::render("x")', 'Livewire::mount("x")', 'Str::of("x")'] as $call) {
            // Static calls are routed through the runtime and denied there (facades can never be allowed).
            yield $call => ['{{ '.$call.' }}', ForbiddenMethodException::class];
        }

        yield '__env factory' => ["{{ \$__env->make('secret')->render() }}", InvalidSandboxTemplateException::class];
        yield 'blade inject' => ["@inject('db', 'db')", ForbiddenDirectiveException::class];
        yield 'blade auth' => ['@auth x @endauth', ForbiddenDirectiveException::class];
        yield 'blade can' => ["@can('x') y @endcan", ForbiddenDirectiveException::class];
        yield 'blade env' => ["@env('local') y @endenv", ForbiddenDirectiveException::class];
        yield 'blade csrf (opt-in)' => ['@csrf', ForbiddenDirectiveException::class];
        yield 'trans() returning the translator' => ['{{ trans()->get("x") }}', ForbiddenFunctionException::class];
        yield 'blade use' => ["@use('Illuminate\\Support\\Facades\\DB')", ForbiddenDirectiveException::class];
        yield 'blade vite' => ["@vite('x')", ForbiddenDirectiveException::class];
    }

    public function testSharedApplicationInstanceIsNotExposed(): void
    {
        // Laravel shares $app with every view; the sandbox never passes it to templates.
        $this->assertSame('none', trim($this->sandbox()->render("{{ isset(\$app) ? 'app' : 'none' }}", ['app' => $this->app])));
    }

    public function testContainerObjectsPassedAsDataAreInert(): void
    {
        $this->assertBlocked("{{ \$container->make('config') }}", ['container' => $this->app], ForbiddenMethodException::class);
        $this->assertBlocked("{{ \$container['config'] }}", ['container' => $this->app], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $config }}', ['config' => $this->app['config']], ForbiddenMethodException::class);
    }

    public function testAllowedFunctionsWorkButTheirResultsStayGuarded(): void
    {
        Route::get('/deploy/{id}', static fn () => 'x')->name('deploy.show');

        $sandbox = $this->sandbox()->allowFunction('route')->allowFunction('strtoupper')->allowFunction('app');

        $this->assertSame('http://localhost/deploy/5', $sandbox->render("{{ route('deploy.show', 5) }}"));
        $this->assertSame('ABC', $sandbox->render("{{ strtoupper('abc') }}"));
        // Even an explicitly allowed app() only returns an object whose API is still denied.
        $this->assertBlocked("{{ app()->make('config') }}", [], ForbiddenMethodException::class, $sandbox);
    }

    public function testAllowedFunctionsCannotReceiveCallables(): void
    {
        $sandbox = $this->sandbox()->allowFunction('array_map')->allowFunction('usort')->allowFunction('array_filter');

        $this->assertBlocked("{{ implode(',', array_map('system', ['id'])) }}", [], ForbiddenFunctionException::class, $sandbox);
        $this->assertBlocked("{{ array_map('system', ['id']) }}", [], ForbiddenFunctionException::class, $sandbox);
        $this->assertBlocked("{{ array_filter(['id'], 'system') }}", [], ForbiddenFunctionException::class, $sandbox);
        $this->assertBlocked("{{ array_map(['Illuminate\\Support\\Facades\\Artisan', 'call'], ['down']) }}", [], ForbiddenFunctionException::class, $sandbox);
        $this->assertBlocked('{{ array_map($fn, [1]) }}', ['fn' => static fn () => $GLOBALS['__blade_sandbox_side_effect'] = true], ForbiddenFunctionException::class, $sandbox);
    }

    public function testNamedArgumentsAreCheckedAgainstTheMatchingParameter(): void
    {
        $sandbox = $this->sandbox()->allowFunction('array_filter')->allowFunction('usort');

        $this->assertBlocked("{{ array_filter(array: ['id'], callback: 'system') }}", [], ForbiddenFunctionException::class, $sandbox);
        $this->assertBlocked("{{ \$c->first(callback: 'system') }}", ['c' => collect(['id'])], ForbiddenMethodException::class);
        $this->assertBlocked('{{ $c->first(default: $fn) }}', ['c' => collect([]), 'fn' => static fn () => $GLOBALS['__blade_sandbox_side_effect'] = true], ForbiddenMethodException::class);
        $this->assertBlocked("{{ \$c->get(key: 'x', unknownName: 'system') }}", ['c' => collect([])], ForbiddenMethodException::class);
    }
}
