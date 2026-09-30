<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use SytxLabs\BladeSandbox\Config\SandboxConfig;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapterFactory;

final class BladeSandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $current = $config->get('blade-sandbox', []);
            $config->set('blade-sandbox', SandboxConfig::resolve(is_array($current) ? $current : []));
        }

        $this->app->singleton(LivewireAdapter::class, static fn (): LivewireAdapter => LivewireAdapterFactory::detect());
        $this->app->singleton(SandboxManager::class, static fn (Application $app): SandboxManager => new SandboxManager($app, (array) $app->make('config')->get('blade-sandbox', []), $app->make(LivewireAdapter::class)));
        $this->app->alias(SandboxManager::class, 'blade-sandbox');
        $this->app->bind(Sandbox::class, static fn (Application $app): Sandbox => $app->make(SandboxManager::class)->make());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/blade-sandbox.php' => $this->app->configPath('blade-sandbox.php')], 'blade-sandbox-config');

            $this->commands([Console\ClearCommand::class, Console\CheckCommand::class, Console\HashCommand::class, Console\PolicyCommand::class, Console\RenderCommand::class]);

            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('SytxLabs Blade Sandbox', function (): array {
                    $config = (array) $this->app->make('config')->get('blade-sandbox', []);
                    $manager = $this->app->make(SandboxManager::class);
                    return [
                        'Version' => self::installedVersion('sytxlabs/blade-sandbox'),
                        'Author' => 'SytxLabs',
                        'Default policy' => (string) ($config['default'] ?? 'default'),
                        'Livewire' => $manager->livewire()->isInstalled() ? self::installedVersion('livewire/livewire') : 'not installed',
                        'Compiled templates' => ($config['cache']['driver'] ?? 'file').' ('.$manager->cacheStore()->directory().')',
                        'Bound namespaces' => implode(', ', array_keys((array) ($config['namespaces'] ?? []))) ?: 'none',
                        'Logging' => $manager->loggingDescription(),
                    ];
                });
            }
        }

        $manager = $this->app->make(SandboxManager::class);
        if ($manager->setting('livewire.guard_requests', true) && $manager->livewire()->isInstalled()) {
            $manager->registerLivewireRequestGuard();
        }

        $this->app->booted(static function () use ($manager): void {
            foreach ((array) $manager->setting('namespaces', []) as $namespace => $policy) {
                $manager->sandboxNamespace((string) $namespace, is_string($policy) ? $policy : null);
            }
            $manager->wrapEngines();
        });
    }

    private static function installedVersion(string $package): string
    {
        return (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) ? 'unknown' : (InstalledVersions::getPrettyVersion($package) ?? 'unknown');
    }
}
