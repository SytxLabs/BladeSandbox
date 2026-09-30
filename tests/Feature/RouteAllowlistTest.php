<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\Route;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenRouteException;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class RouteAllowlistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/shop/{id}', static fn () => 'x')->name('shop.show');
        Route::get('/admin', static fn () => 'x')->name('admin.index');
        Route::get('/shop/hidden', static fn () => 'x')->name('shop.hidden');
        Route::getRoutes()->refreshNameLookups();
    }

    public function testRouteIsDeniedByDefault(): void
    {
        $this->expectException(ForbiddenFunctionException::class);

        $this->sandbox()->render('{{ route("shop.show", 1) }}');
    }

    public function testAllowedRouteNames(): void
    {
        $sandbox = $this->sandbox()->allowRoutes('shop.*')->denyRoute('shop.hidden');

        $this->assertSame('http://localhost/shop/5', $sandbox->render('{{ route("shop.show", ["id" => 5]) }}'));
        $this->assertSame('/shop/5', $sandbox->render('{{ route(name: "shop.show", parameters: [5], absolute: false) }}'));

        foreach (['{{ route("admin.index") }}', '{{ route("shop.hidden") }}', '{{ route($r) }}'] as $template) {
            try {
                $sandbox->render($template, ['r' => 'admin.index']);
                $this->fail($template.' must be denied.');
            } catch (ForbiddenRouteException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAllowFunctionRouteKeepsAllowingEveryRouteButDenyRulesWin(): void
    {
        $sandbox = $this->sandbox()->allowFunction('route');
        $this->assertSame('http://localhost/admin', $sandbox->render('{{ route("admin.index") }}'));

        $this->expectException(ForbiddenRouteException::class);
        $sandbox->denyRoute('admin.*')->render('{{ route("admin.index") }}');
    }

    public function testValidationAndConfig(): void
    {
        $sandbox = PolicyConfiguration::apply($this->sandbox(), ['routes' => ['shop.*'], 'deny_routes' => ['shop.hidden']]);

        $result = $sandbox->validate("{{ route('shop.show', 1) }}\n{{ route('admin.index') }}\n{{ route('shop.hidden') }}");
        $this->assertSame([2, 3], array_map(static fn ($v) => $v->line, $result->violations()));
        $this->assertSame('route', $result->violations()[0]->capability);
    }
}
