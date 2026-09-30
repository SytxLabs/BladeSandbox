<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Fluent;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\BaseDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;
use SytxLabs\BladeSandbox\Tests\TestCase;

/**
 * Keeps the examples in README.md executable.
 */
final class ReadmeExamplesTest extends TestCase
{
    public function testIntroAndDeployerExample(): void
    {
        $deployment = new DeploymentDTO();

        $html = BladeSandbox::make()
            ->allowDto(DeploymentDTO::class)
            ->allowView('deployer::test')
            ->allowView('deployer::partials.*')
            ->renderView('deployer::test', ['deployment' => $deployment]);

        $this->assertStringContainsString('<h1>api</h1>', $html);
    }

    public function testBasicUsageAndContainerResolution(): void
    {
        $sandbox = BladeSandbox::make();
        $sandbox->allowView('deployer::emails.deploy');
        $this->assertSame("Deploy api\n", $sandbox->allowDto(BaseDTO::class)->renderView('deployer::emails.deploy', ['deployment' => new DeploymentDTO()]));

        $this->assertInstanceOf(Sandbox::class, $this->app->make(Sandbox::class));
        $this->assertNotSame($this->app->make(Sandbox::class), $this->app->make(Sandbox::class));
        $this->assertSame('<h1>Shaun</h1>', BladeSandbox::render('<h1>{{ $name }}</h1>', ['name' => 'Shaun']));
    }

    public function testFunctionsConstantsAndObjectsExample(): void
    {
        Route::get('/x', static fn () => 'x')->name('x');

        $sandbox = BladeSandbox::make()
            ->allowFunction('route')
            ->allowFunction('asset')
            ->allowConstant('PHP_EOL')
            ->allowClassConstant(ReadmeStatus::class, ['Active', 'Failed'])
            ->allowMethod(User::class, ['getName'])
            ->allowProperty(User::class, 'name')
            ->allowIteration(LengthAwarePaginator::class)
            ->allowArrayAccess(Fluent::class);

        $user = new User(['name' => 'Alice']);
        $this->assertSame('Alice Alice', $sandbox->render('{{ $user->name }} {{ $user->getName() }}', ['user' => $user]));
        $this->assertSame('http://localhost/x', $sandbox->render("{{ route('x') }}"));
        $this->assertSame('active', $sandbox->render('{{ \SytxLabs\BladeSandbox\Tests\Feature\ReadmeStatus::Active->value }}'));
        $this->assertSame('b', $sandbox->render("{{ \$f['a'] }}", ['f' => new Fluent(['a' => 'b'])]));
        $this->assertSame('12', $sandbox->render('@foreach($p as $i){{ $i }}@endforeach', ['p' => new LengthAwarePaginator([1, 2], 2, 10)]));

        $this->expectException(ForbiddenMethodException::class);
        $sandbox->render('{{ $user->delete() }}', ['user' => $user]);
    }

    public function testDirectiveExamples(): void
    {
        $sandbox = BladeSandbox::make()
            ->directive('money', fn (int $cents, string $currency = 'EUR') => number_format($cents / 100, 2).' '.$currency);

        $this->assertSame('12.50 EUR', $sandbox->render('@money($order->totalCents)', ['order' => (object) ['totalCents' => 1250]]));

        $this->expectException(ForbiddenDirectiveException::class);
        BladeSandbox::make()->denyDirective('include')->render("@include('x')");
    }

    public function testPolicyInspectionExample(): void
    {
        $sandbox = BladeSandbox::make()->allowView('deployer::test');
        $policy = $sandbox->policy();

        $this->assertTrue($policy->allowsView('deployer::test'));
        $this->assertFalse($policy->allowsMethod(new User(), 'delete'));
        $this->assertSame(64, strlen($policy->fingerprint()));
    }

    public function testNamespaceBindingExample(): void
    {
        BladeSandbox::sandboxNamespace('deployer', BladeSandbox::make()
            ->allowViewNamespace('deployer')
            ->allowDto(DeploymentDTO::class));

        $this->assertStringContainsString('<h1>api</h1>', view('deployer::test', ['deployment' => new DeploymentDTO()])->render());
    }

    public function testLimitsAndDebugAreFluent(): void
    {
        $sandbox = BladeSandbox::make()->maxDepth(8)->maxOutputBytes(1000)->debug();

        $this->assertTrue($sandbox->isDebugging());
        $this->assertSame('x', $sandbox->render('x'));
    }
}

enum ReadmeStatus: string
{
    case Active = 'active';
    case Failed = 'failed';
}
