<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Http\Request;
use InvalidArgumentException;
use stdClass;
use SytxLabs\BladeSandbox\Contracts\PolicyResolver;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class PolicyResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('blade-sandbox.policies.admin', ['functions' => ['strtoupper']]);
    }

    public function testWithoutResolverTheDefaultPolicyIsUsed(): void
    {
        $this->expectException(ForbiddenFunctionException::class);

        BladeSandbox::render('{{ strtoupper("a") }}');
    }

    public function testClosureResolverChoosesByRequest(): void
    {
        BladeSandbox::resolvePolicyUsing(static fn (?Request $request): ?string => $request?->header('X-Role') === 'admin' ? 'admin' : null);

        $admin = Request::create('/', 'GET', server: ['HTTP_X_ROLE' => 'admin']);
        $this->assertSame('A', $this->app->make(SandboxManager::class)->forRequest($admin)->render('{{ strtoupper("a") }}'));

        $this->app->instance('request', $admin);
        $this->assertSame('A', BladeSandbox::render('{{ strtoupper("a") }}'));

        $this->app->instance('request', Request::create('/'));
        $this->expectException(ForbiddenFunctionException::class);
        BladeSandbox::render('{{ strtoupper("a") }}');
    }

    public function testResolverMayReturnASandbox(): void
    {
        BladeSandbox::resolvePolicyUsing(static fn (): Sandbox => BladeSandbox::make()->allowFunction('ucfirst'));

        $this->assertSame('Ab', BladeSandbox::render('{{ ucfirst("ab") }}'));
    }

    public function testResolverClassFromConfig(): void
    {
        $this->app['config']->set('blade-sandbox.policy_resolver', AdminResolver::class);
        $this->app->forgetInstance(SandboxManager::class);

        $this->assertSame('A', BladeSandbox::getFacadeRoot()->forRequest()->render('{{ strtoupper("a") }}'));

        $this->app['config']->set('blade-sandbox.policy_resolver', stdClass::class);
        $this->app->forgetInstance(SandboxManager::class);
        BladeSandbox::clearResolvedInstances();
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(SandboxManager::class)->forRequest();
    }
}

final class AdminResolver implements PolicyResolver
{
    public function resolve(?Request $request): Sandbox|string|null
    {
        return 'admin';
    }
}
