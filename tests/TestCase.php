<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SytxLabs\BladeSandbox\BladeSandboxServiceProvider;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        $providers = [];
        if (class_exists(LivewireServiceProvider::class)) {
            $providers[] = LivewireServiceProvider::class;
        }
        $providers[] = BladeSandboxServiceProvider::class;

        return $providers;
    }

    protected function getPackageAliases($app): array
    {
        return ['BladeSandbox' => BladeSandbox::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views/app']);
        $app['config']->set('blade-sandbox.cache_path', sys_get_temp_dir().'/blade-sandbox-tests/'.getmypid());
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
    }

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('deployer', __DIR__.'/Fixtures/views/deployer');
    }

    protected function createUsers(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });

        User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'hash']);
    }

    protected function userCount(): int
    {
        return User::query()->count();
    }

    protected function sandbox(): Sandbox
    {
        return $this->app->make(SandboxManager::class)->make();
    }

    protected function livewireInstalled(): bool
    {
        return class_exists(Livewire::class);
    }
}
