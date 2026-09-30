<?php

declare(strict_types=1);

/*
 * Child process for IsolatedRenderingTest: boots a Testbench application with the package, the test
 * configuration and the fixture views, then renders the job read from STDIN.
 */

use Orchestra\Testbench\Foundation\Application;
use SytxLabs\BladeSandbox\BladeSandboxServiceProvider;
use SytxLabs\BladeSandbox\Isolation\IsolatedRenderWorker;
use SytxLabs\BladeSandbox\SandboxManager;

require __DIR__.'/../bootstrap.php'; // autoloader and the fixture shims (throw_if_debug(), Livewire\Wireable without Livewire)

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('blade-sandbox.cache_path', sys_get_temp_dir().'/blade-sandbox-tests/isolated');
$app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
$app->register(BladeSandboxServiceProvider::class);
$app['view']->addNamespace('deployer', __DIR__.'/views/deployer');

fwrite(STDOUT, (new IsolatedRenderWorker($app->make(SandboxManager::class)))->run((string) stream_get_contents(STDIN)).PHP_EOL);
