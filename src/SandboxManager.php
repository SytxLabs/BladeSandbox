<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use InvalidArgumentException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Cache\FileStore;
use SytxLabs\BladeSandbox\Cache\MemoryStore;
use SytxLabs\BladeSandbox\Compatibility\ComponentResolver;
use SytxLabs\BladeSandbox\Compatibility\IlluminateViewFinderAdapter;
use SytxLabs\BladeSandbox\Compatibility\ViewFinderAdapter;
use SytxLabs\BladeSandbox\Compiler\CompiledTemplateCache;
use SytxLabs\BladeSandbox\Compiler\ExpressionSandboxer;
use SytxLabs\BladeSandbox\Compiler\PhpAstValidator;
use SytxLabs\BladeSandbox\Compiler\SandboxBladeCompiler;
use SytxLabs\BladeSandbox\Config\SandboxConfig;
use SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore;
use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use SytxLabs\BladeSandbox\Contracts\MarkdownConverter;
use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;
use SytxLabs\BladeSandbox\Contracts\PolicyResolver;
use SytxLabs\BladeSandbox\Contracts\SandboxedLivewireComponent;
use SytxLabs\BladeSandbox\Contracts\TemplateLoader;
use SytxLabs\BladeSandbox\Contracts\TextConverter;
use SytxLabs\BladeSandbox\Contracts\ViolationLimiter;
use SytxLabs\BladeSandbox\Fallback\CallbackFallback;
use SytxLabs\BladeSandbox\Fallback\EmptyFallback;
use SytxLabs\BladeSandbox\Fallback\EscapedFallback;
use SytxLabs\BladeSandbox\Fallback\SourceFallback;
use SytxLabs\BladeSandbox\Fallback\StripBladeFallback;
use SytxLabs\BladeSandbox\Guards\LivewireGuard;
use SytxLabs\BladeSandbox\Helpers\SafeHelpers;
use SytxLabs\BladeSandbox\Integrity\IntegrityManifest;
use SytxLabs\BladeSandbox\Isolation\IsolatedRenderer;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Policy\PolicyBuilder;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;
use SytxLabs\BladeSandbox\Policy\ValueObjectDefaults;
use SytxLabs\BladeSandbox\Runtime\ComponentRenderer;
use SytxLabs\BladeSandbox\Runtime\SandboxAwareEngine;
use SytxLabs\BladeSandbox\Runtime\SandboxContext;
use SytxLabs\BladeSandbox\Runtime\SandboxLimits;
use SytxLabs\BladeSandbox\Runtime\SandboxRuntime;
use SytxLabs\BladeSandbox\Runtime\SandboxViewRenderer;
use SytxLabs\BladeSandbox\Security\AuthorLockout;
use SytxLabs\BladeSandbox\Support\ViewFiles;
use SytxLabs\BladeSandbox\Templates\ArrayTemplateLoader;
use SytxLabs\BladeSandbox\Templates\CallbackTemplateLoader;
use SytxLabs\BladeSandbox\Templates\EloquentTemplateLoader;
use SytxLabs\BladeSandbox\Templates\TemplateSources;
use SytxLabs\BladeSandbox\Testing\SandboxRecorder;
use SytxLabs\BladeSandbox\Text\CommonMarkConverter;
use SytxLabs\BladeSandbox\Text\HtmlToText;
use SytxLabs\BladeSandbox\Validation\TemplateValidator;
use Throwable;

/** Entry point (behind the BladeSandbox facade): creates sandboxes, holds the shared compiler / cache services, binds view namespaces to sandboxes and wires the Livewire request guard. */
final class SandboxManager
{
    /** @var array<string, Sandbox> view namespace => sandbox */
    private array $namespaces = [];
    /** @var array<class-string, Sandbox> Livewire component class => sandbox */
    private array $livewireComponents = [];
    private ?SandboxBladeCompiler $compiler = null;
    private ?CompiledTemplateCache $cache = null;
    private ?CompiledTemplateStore $store = null;
    private ?TemplateSources $sources = null;
    /** @var array<string, Closure(array<string, mixed>, SandboxManager): TemplateLoader> */
    private array $loaderDrivers = [];
    /** @var array<string, FallbackRenderer> */
    private array $fallbacks = [];
    /** @var array<string, Closure(Sandbox): mixed> */
    private array $helperSets = [];
    /** @var list<Closure(Sandbox, string): mixed> */
    private array $outputCacheVaries = [];
    private ?TextConverter $textConverter = null;
    private ?MarkdownConverter $markdownConverter = null;
    private ?ViolationLimiter $violationLimiter = null;
    private ?SandboxRecorder $recorder = null;
    /** @var class-string<PolicyResolver>|(Closure(?Request, SandboxManager): (Sandbox|string|null))|PolicyResolver|null */
    private Closure|PolicyResolver|string|null $policyResolver = null;
    /** @var list<string> policy names currently being resolved (inheritance cycle detection) */
    private array $resolving = [];
    /** @var array<string, Closure(array<string, mixed>, SandboxManager): CompiledTemplateStore> */
    private array $cacheDrivers = [];
    private ?SandboxViewRenderer $renderer = null;
    private ?ComponentRenderer $components = null;
    private ?ViewFinderAdapter $finder = null;
    private ?TemplateValidator $validator = null;
    private bool $enginesWrapped = false;
    /** @var array<string, Sandbox> */
    private array $policies = [];
    /** @var array<string, mixed> the resolved configuration the manager was created with (fallback for setting()) */
    private array $config;
    /** @var array<string, array<string, mixed>|(Closure(Sandbox): mixed)> policies defined in code (definePolicy()) */
    private array $codePolicies = [];
    /** @var array<string, list<array<string, mixed>|(Closure(Sandbox): mixed)>> additions to policies (extendPolicy()) */
    private array $policyExtensions = [];
    private LoggerInterface|string|null $logger = null;
    private ?string $logLevel = null;

    public function __construct(private readonly Container $container, array $config, private readonly LivewireAdapter $livewire)
    {
        $this->config = SandboxConfig::resolve($config);
    }

    public function make(): Sandbox
    {
        $builder = new PolicyBuilder();
        if ($this->setting('value_objects', true)) {
            ValueObjectDefaults::apply($builder);
        }
        $settings = ['limits' => (array) $this->setting('limits', [])];
        foreach (SandboxConfig::LIMIT_KEYS as $key) {
            $settings[$key] = $this->setting($key);
        }
        return (new Sandbox($this, $builder, SandboxLimits::fromConfig($settings)))->debug($this->loggingEnabled());
    }

    /** A new sandbox configured from config("blade-sandbox.policies.{$name}"). */
    public function policy(string $name): Sandbox
    {
        if (!$this->hasPolicy($name)) {
            throw new InvalidArgumentException("Blade sandbox policy [{$name}] is not defined.");
        }
        $definition = $this->codePolicies[$name] ?? $this->setting('policies.'.$name);
        if (in_array($name, $this->resolving, true)) {
            throw new InvalidArgumentException('Circular blade sandbox policy inheritance: '.implode(' → ', [...$this->resolving, $name]).'.');
        }
        $this->resolving[] = $name;

        try {
            $sandbox = $this->applyPolicyDefinition($this->make(), is_array($definition) || $definition instanceof Closure ? $definition : []);
            foreach ($this->policyExtensions[$name] ?? [] as $extension) {
                $sandbox = $this->applyPolicyDefinition($sandbox, $extension);
            }
            return $sandbox->originatesFrom($name);
        } finally {
            array_pop($this->resolving);
        }
    }

    /** Whether a policy with this name is defined in config or code (definePolicy() / extendPolicy()). */
    public function hasPolicy(string $name): bool
    {
        return isset($this->codePolicies[$name]) || isset($this->policyExtensions[$name]) || is_array($this->setting('policies.'.$name));
    }

    /**
     * Define (or replace) a policy in code, e.g. from a package's service provider: a policy array like in config/blade-sandbox.php or a closure that configures the sandbox. A code policy wins over a config policy of the same name.
     *
     * @param array<string, mixed>|(Closure(Sandbox): mixed) $definition
     */
    public function definePolicy(string $name, array|Closure $definition): self
    {
        $this->codePolicies[$name] = $definition;
        $this->policies = [];

        return $this;
    }

    /**
     * Add to a policy from config or code, applied after its own definition (deny rules keep winning).
     *
     * @param array<string, mixed>|(Closure(Sandbox): mixed) ...$definitions
     */
    public function extendPolicy(string $name, array|Closure ...$definitions): self
    {
        foreach ($definitions as $definition) {
            $this->policyExtensions[$name][] = $definition;
        }
        $this->policies = [];

        return $this;
    }

    /**
     * Override configuration values from code (e.g. a CMS package's service provider). The overrides are
     * deep-merged into config("blade-sandbox") like the application's config file.
     *
     * @param array<string, mixed> $overrides
     */
    public function configure(array $overrides): self
    {
        $overrides = SandboxConfig::normalize($overrides);
        $this->config = SandboxConfig::merge($this->config, $overrides);

        if ($this->container->bound('config')) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $repository = $this->container->make('config');
            $current = $repository->get('blade-sandbox', []);
            $repository->set('blade-sandbox', SandboxConfig::merge(SandboxConfig::normalize(is_array($current) ? $current : []), $overrides));
        }

        $this->policies = [];
        if (array_key_exists('cache', $overrides) || array_key_exists('cache_path', $overrides)) {
            $this->store = null;
            $this->cache = null;
        }
        if (array_key_exists('loaders', $overrides) && $this->sources !== null) {
            foreach ((array) $overrides['loaders'] as $namespace => $definition) {
                $this->sources->addLoader((string) $namespace, $this->createLoader((string) $namespace, $definition));
            }
        }
        $this->renderer = null;

        return $this;
    }

    /** A configuration value: the live config("blade-sandbox.*") (so runtime and package overrides apply), falling back to the configuration the manager was created with. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $fallback = Arr::get($this->config, $key, $default);
        return $this->container->bound('config') ? $this->container->make('config')->get('blade-sandbox.'.$key, $fallback) : $fallback;
    }

    /**
     * @param array<string, mixed>|(Closure(Sandbox): mixed) $definition
     */
    private function applyPolicyDefinition(Sandbox $sandbox, array|Closure $definition): Sandbox
    {
        if ($definition instanceof Closure) {
            $result = $definition($sandbox);
            return $result instanceof Sandbox ? $result : $sandbox;
        }
        return PolicyConfiguration::apply($sandbox, $definition);
    }

    public function default(): Sandbox
    {
        $name = $this->setting('default', 'default');
        return $this->policies[$name] ??= $this->hasPolicy($name) ? $this->policy($name) : $this->make();
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->forRequest()->render($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function renderView(string $view, array $data = []): string
    {
        return $this->forRequest()->renderView($view, $data);
    }

    //#region Policy per tenant / user

    /**
     * Choose the sandbox per request (tenant, role, author, ...). The resolver returns a Sandbox, a policy name from config or null (= default policy).
     * Used by BladeSandbox::render(), renderView() and forRequest(); config key "policy_resolver" accepts a PolicyResolver class.
     *
     * @param class-string<PolicyResolver>|(Closure(?Request, SandboxManager): (Sandbox|string|null))|PolicyResolver|null $resolver
     */
    public function resolvePolicyUsing(Closure|PolicyResolver|string|null $resolver): self
    {
        $this->policyResolver = $resolver;
        return $this;
    }

    /** The sandbox the policy resolver chooses for the request (default: the current request). @noinspection PhpUnhandledExceptionInspection*/
    public function forRequest(?Request $request = null): Sandbox
    {
        $resolver = $this->policyResolver ?? $this->setting('policy_resolver');
        if ($resolver === null || $resolver === '') {
            return $this->default();
        }
        if ($request === null && $this->container->bound('request')) {
            $current = $this->container->make('request');
            $request = $current instanceof Request ? $current : null;
        }
        if (is_string($resolver)) {
            $instance = $this->container->make($resolver);
            if (!$instance instanceof PolicyResolver) {
                throw new InvalidArgumentException('The blade sandbox policy resolver ['.$resolver.'] must implement '.PolicyResolver::class.'.');
            }
            $resolver = $instance;
        }
        $resolved = $resolver instanceof PolicyResolver ? $resolver->resolve($request) : $resolver($request, $this);
        return match (true) {
            $resolved instanceof Sandbox => $resolved,
            is_string($resolved) && $resolved !== '' => $this->policy($resolved),
            default => $this->default(),
        };
    }
    //#endregion Policy per tenant / user

    //#region Namespace binding

    /**
     * Render every view of a namespace (e.g. "plugin") through the given sandbox, regardless of how it is rendered (view(), @include from trusted templates, Mailables, Livewire render(), ...).
     */
    public function sandboxNamespace(string $namespace, Sandbox|string|null $sandbox = null): Sandbox
    {
        $sandbox = match (true) {
            $sandbox instanceof Sandbox => $sandbox,
            is_string($sandbox) => $this->policy($sandbox),
            default => $this->default(),
        };
        $this->namespaces[$namespace] = $sandbox;
        $this->wrapEngines();
        return $sandbox;
    }

    /** @return array<string, Sandbox> */
    public function sandboxedNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * @return array{sandbox: Sandbox, view: string}|null
     */
    public function bindingForPath(string $path): ?array
    {
        if ($this->namespaces === []) {
            return null;
        }
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        foreach ($this->namespaces as $namespace => $sandbox) {
            foreach ($this->finder()->namespaceDirectories($namespace) as $directory) {
                $root = realpath($directory);
                if ($root === false || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $relative = substr($real, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
                foreach ($this->finder()->extensions() as $extension) {
                    if (str_ends_with($relative, '.'.$extension)) {
                        $relative = substr($relative, 0, -strlen($extension) - 1);
                        break;
                    }
                }
                return ['sandbox' => $sandbox, 'view' => $namespace.'::'.str_replace(DIRECTORY_SEPARATOR, '.', $relative)];
            }
        }

        return null;
    }

    /** Decorate every registered view engine with the namespace-aware sandbox engine (idempotent). */
    public function wrapEngines(): void
    {
        if ($this->enginesWrapped || $this->namespaces === [] || !$this->container->bound('view.engine.resolver')) {
            return;
        }

        $resolver = $this->container->make('view.engine.resolver');
        $factory = $this->viewFactory();
        $engines = $factory instanceof Factory ? array_unique(array_values($factory->getExtensions())) : ['blade', 'php', 'file'];

        foreach ($engines as $name) {
            try {
                $inner = $resolver->resolve($name);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($inner instanceof SandboxAwareEngine) {
                continue;
            }
            $resolver->register($name, fn () => new SandboxAwareEngine($inner, $this));
        }

        $this->enginesWrapped = true;
    }
    //#endregion Namespace binding

    //#region Livewire

    /** Guard incoming Livewire requests (actions, updates, events) of a component class with a sandbox policy. */
    public function guardLivewireComponent(string $component, Sandbox $sandbox): void
    {
        $this->livewireComponents[$component] = $sandbox;
    }

    public function sandboxForLivewireComponent(object $component): ?Sandbox
    {
        if ($component instanceof SandboxedLivewireComponent) {
            return $component->sandbox();
        }

        foreach ($this->livewireComponents as $class => $sandbox) {
            if ($component instanceof $class) {
                return $sandbox;
            }
        }

        return null;
    }

    /** Called by the Livewire adapters before an action / magic action / event call runs. */
    public function guardLivewireCall(object $component, string $method, array $parameters): void
    {
        $sandbox = $this->sandboxForLivewireComponent($component);
        if ($sandbox !== null) {
            $this->livewireGuard($sandbox)->assertCall($method, $parameters);
        }
    }

    /** Called by the Livewire adapters before a property update (wire:model) is applied. */
    public function guardLivewireUpdate(object $component, string $path): void
    {
        $sandbox = $this->sandboxForLivewireComponent($component);
        if ($sandbox !== null) {
            $this->livewireGuard($sandbox)->assertModel($path);
        }
    }

    public function registerLivewireRequestGuard(): void
    {
        $this->livewire->registerRequestGuard(
            fn (object $component, string $method, array $parameters) => $this->guardLivewireCall($component, $method, $parameters),
            fn (object $component, string $path, mixed $value) => $this->guardLivewireUpdate($component, $path),
        );
    }

    private function livewireGuard(Sandbox $sandbox): LivewireGuard
    {
        return (new LivewireGuard($sandbox->policy(), $this->auditLogger($sandbox->isDebugging(), $sandbox->logger(), $sandbox->logLevel()), $this->livewire))->forTemplate('livewire-request');
    }

    public function livewire(): LivewireAdapter
    {
        return $this->livewire;
    }
    //#endregion Livewire

    //#region Services

    public function runtime(SecurityPolicy $policy, bool $debug, SandboxLimits $limits = new SandboxLimits(), ?IntegrityManifest $integrity = null, LoggerInterface|string|null $logger = null, ?string $logLevel = null): SandboxRuntime
    {
        return new SandboxRuntime($policy, new SandboxContext($limits), $this->renderer(), $this->components ??= new ComponentRenderer(new ComponentResolver($this->bladeCompiler())), $this->auditLogger($debug, $logger, $logLevel), $this->livewire, $integrity);
    }

    /** A named output sanitizer from config("blade-sandbox.sanitizers"): a class name or [class, constructor arguments]. Instances are resolved through the container. */
    public function sanitizer(string $name): OutputSanitizer
    {
        $definition = $this->setting('sanitizers.'.$name);
        if (is_string($definition)) {
            $definition = [$definition, []];
        }
        if (!is_array($definition) || !is_string($definition[0] ?? null)) {
            throw new InvalidArgumentException("Blade sandbox sanitizer [{$name}] is not defined.");
        }
        $sanitizer = $this->container->make($definition[0], (array) ($definition[1] ?? []));
        if (!$sanitizer instanceof OutputSanitizer) {
            throw new InvalidArgumentException("Blade sandbox sanitizer [{$name}] must implement ".OutputSanitizer::class.'.');
        }
        return $sanitizer;
    }

    public function validator(): TemplateValidator
    {
        return $this->validator ??= new TemplateValidator($this->compiler(), $this->sources(), new ComponentResolver($this->bladeCompiler()), $this->files());
    }

    public function viewFiles(): ViewFiles
    {
        return new ViewFiles($this->finder());
    }

    /** The key integrity manifests are signed with: config("blade-sandbox.integrity.key"), else the APP_KEY. */
    public function integrityKey(): ?string
    {
        $key = $this->setting('integrity.key');
        if (!is_string($key) || $key === '') {
            $key = $this->container->bound('config') ? $this->container->make('config')->get('app.key') : null;
        }
        if (!is_string($key) || $key === '') {
            return null;
        }
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }

    /** @param array<string, mixed>|IntegrityManifest|string $manifest manifest, path to a JSON manifest or its decoded array  */
    public function manifest(array|IntegrityManifest|string $manifest, bool $requireSignature = true): IntegrityManifest
    {
        return match (true) {
            $manifest instanceof IntegrityManifest => $manifest,
            is_string($manifest) => IntegrityManifest::load($manifest, $this->integrityKey(), $requireSignature),
            default => IntegrityManifest::fromArray($manifest, $this->integrityKey(), $requireSignature),
        };
    }

    /** The violation logger: the sandbox's own logger (logUsing()), else the manager's (useLogger()), else config "logging" (a LoggerInterface class in "logger", a log channel in "channel" / "logger"). */
    public function auditLogger(bool $enabled, LoggerInterface|string|null $logger = null, ?string $level = null): AuditLogger
    {
        $logger ??= $this->logger;
        if (!($logger instanceof LoggerInterface)) {
            $logger ??= $this->setting('logging.logger') ?? $this->setting('logging.channel') ?? $this->setting('audit.channel');
            if (is_string($logger) && $logger !== '' && class_exists($logger)) {
                $instance = $this->container->make($logger);
                if (!$instance instanceof LoggerInterface) {
                    throw new InvalidArgumentException('The blade sandbox logger ['.$logger.'] must implement '.LoggerInterface::class.'.');
                }
                $logger = $instance;
            } elseif ($this->container->bound('log')) {
                $log = $this->container->make('log');
                if (is_string($logger) && $logger !== '' && method_exists($log, 'channel')) {
                    $log = $log->channel($logger);
                }
                $logger = $log instanceof LoggerInterface ? $log : null;
            } else {
                $logger = null;
            }
        }
        return new AuditLogger($enabled ? $logger : null, $enabled, self::logLevel($level ?? $this->logLevel ?? $this->setting('logging.level')));
    }

    /** Log security violations to this logger (a PSR-3 logger, a LoggerInterface class or a log channel name); enables logging for sandboxes created from now on. null restores config "logging". */
    public function useLogger(LoggerInterface|string|null $logger, ?string $level = null): self
    {
        $this->logger = $logger;
        $this->logLevel = $level !== null ? self::logLevel($level) : null;
        $this->policies = [];

        return $this;
    }

    /** Whether new sandboxes log violations (config logging.enabled / legacy audit.enabled, or useLogger()). */
    public function loggingEnabled(): bool
    {
        return $this->logger !== null || $this->setting('logging.enabled', false) || $this->setting('audit.enabled', false);
    }

    /** Description for `php artisan about`. */
    public function loggingDescription(): string
    {
        if (!$this->loggingEnabled()) {
            return 'off';
        }
        $target = $this->logger ?? $this->setting('logging.logger') ?? $this->setting('logging.channel') ?? $this->setting('audit.channel');
        return match (true) {
            $target instanceof LoggerInterface => $target::class,
            is_string($target) && $target !== '' => $target,
            default => 'default channel',
        }.' ('.self::logLevel($this->logLevel ?? $this->setting('logging.level')).')';
    }

    private static function logLevel(mixed $level): string
    {
        $level = is_string($level) && $level !== '' ? strtolower($level) : LogLevel::WARNING;
        if (!in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG], true)) {
            throw new InvalidArgumentException('Invalid blade sandbox log level ['.$level.'].');
        }
        return $level;
    }

    public function compiler(): SandboxBladeCompiler
    {
        return $this->compiler ??= new SandboxBladeCompiler(new ExpressionSandboxer(), new PhpAstValidator(), $this->livewire, fn (): array => array_keys($this->bladeCompiler()->getCustomDirectives()));
    }

    public function cache(): CompiledTemplateCache
    {
        return $this->cache ??= new CompiledTemplateCache($this->compiler(), $this->cacheStore());
    }

    public function cacheStore(): CompiledTemplateStore
    {
        return $this->store ??= $this->createCacheStore();
    }

    /** Switch the compiled-template storage at runtime: a directory (for the "file" driver) or a store. Affects every sandbox of this manager from the next render on. */
    public function useCache(CompiledTemplateStore|string $store): self
    {
        $this->store = is_string($store) ? new FileStore($this->files(), $store) : $store;
        $this->cache = null;
        $this->renderer = null;
        return $this;
    }

    /**
     * Register a custom cache driver usable as `'cache' => ['driver' => $driver]`.
     *
     * @param Closure(array<string, mixed>, SandboxManager): CompiledTemplateStore $factory receives the "cache" config
     */
    public function extendCache(string $driver, Closure $factory): self
    {
        $this->cacheDrivers[$driver] = $factory;
        if ($this->store !== null && ($this->cacheConfig()['driver'] ?? 'file') === $driver) {
            $this->useCache($this->createCacheStore());
        }
        return $this;
    }

    public function renderer(): SandboxViewRenderer
    {
        return $this->renderer ??= new SandboxViewRenderer($this->sources(), $this->cache(), $this->files(), array_values((array) $this->setting('hidden_variables', ['app', '__env', '_instance', '__livewire'])));
    }

    /** File views (view finder) plus namespaces served by template loaders. */
    public function sources(): TemplateSources
    {
        if ($this->sources === null) {
            $this->sources = new TemplateSources($this->finder(), $this->files());
            foreach ((array) $this->setting('loaders', []) as $namespace => $definition) {
                $this->sources->addLoader((string) $namespace, $this->createLoader((string) $namespace, $definition));
            }
        }

        return $this->sources;
    }

    /**
     * Serve the view namespace from a template loader (database, API, ...): "cms::page" then resolves through $loader in renderView(), @include, @extends and components.
     * The views still have to be allowed (allowViewNamespace('cms') / allowView('cms::**')).
     *
     * @param (Closure(string): ?string)|TemplateLoader $loader
     */
    public function loader(string $namespace, Closure|TemplateLoader $loader): self
    {
        $this->sources()->addLoader($namespace, $loader instanceof Closure ? new CallbackTemplateLoader($loader) : $loader);
        return $this;
    }

    /**
     * Register a loader driver usable in config: 'loaders' => ['cms' => ['driver' => $driver, ...]].
     *
     * @param Closure(array<string, mixed>, SandboxManager): TemplateLoader $factory
     */
    public function extendLoader(string $driver, Closure $factory): self
    {
        $this->loaderDrivers[$driver] = $factory;
        return $this;
    }

    private function createLoader(string $namespace, mixed $definition): TemplateLoader
    {
        if ($definition instanceof TemplateLoader) {
            return $definition;
        }
        if (is_string($definition)) {
            $definition = ['driver' => $definition];
        }
        if (!is_array($definition)) {
            throw new InvalidArgumentException('Invalid template loader configuration for namespace ['.$namespace.'].');
        }
        $driver = (string) ($definition['driver'] ?? 'eloquent');
        if (isset($this->loaderDrivers[$driver])) {
            return ($this->loaderDrivers[$driver])($definition, $this);
        }
        if ($driver === 'eloquent') {
            return new EloquentTemplateLoader((string) ($definition['model'] ?? ''), (string) ($definition['name'] ?? 'name'), (string) ($definition['content'] ?? 'content'));
        }
        if ($driver === 'array') {
            return new ArrayTemplateLoader(array_map('strval', (array) ($definition['templates'] ?? [])));
        }
        if (class_exists($driver) && is_subclass_of($driver, TemplateLoader::class)) {
            $loader = $this->container->make($driver, ['config' => $definition]);
            if ($loader instanceof TemplateLoader) {
                return $loader;
            }
        }
        throw new InvalidArgumentException('Unknown template loader driver ['.$driver.'] for namespace ['.$namespace.'].');
    }
    //#endregion Services

    //#region Events & testing

    /** Dispatches a sandbox event through Laravel's event dispatcher (if bound) and to the test recorder. */
    public function dispatch(object $event): void
    {
        $this->recorder?->record($event);
        if ($this->container->bound('events')) {
            $this->container->make('events')->dispatch($event);
        }
    }

    /** Start recording sandbox events for assertions (BladeSandbox::fake()). */
    public function record(): SandboxRecorder
    {
        return $this->recorder = new SandboxRecorder();
    }

    public function recorder(): ?SandboxRecorder
    {
        return $this->recorder;
    }
    //#endregion Events & testing

    //#region Fallbacks

    /**
     * Register a fallback mode usable with renderFallback('name') and 'fallback' => 'name' in config.
     *
     * @param (Closure(string, string, Throwable): string)|FallbackRenderer $renderer
     */
    public function extendFallback(string $name, Closure|FallbackRenderer $renderer): self
    {
        $this->fallbacks[$name] = $renderer instanceof Closure ? new CallbackFallback($renderer) : $renderer;
        return $this;
    }

    public function fallback(string $name): FallbackRenderer
    {
        return $this->fallbacks[$name] ?? match ($name) {
            'source' => new SourceFallback(),
            'strip' => new StripBladeFallback(),
            'escaped' => new EscapedFallback(),
            'empty' => new EmptyFallback(),
            default => throw new InvalidArgumentException('Unknown sandbox fallback ['.$name.']. Use source, strip, escaped, empty or register one with extendFallback().'),
        };
    }

    /** Report an exception whose render was replaced by fallback output (config "fallback_report"). */
    public function reportFallback(Throwable $exception): void
    {
        if ($this->setting('fallback_report', true) && $this->container->bound(ExceptionHandler::class)) {
            $this->container->make(ExceptionHandler::class)->report($exception);
        }
    }
    //#endregion Fallbacks

    //#region Helper sets

    /**
     * Register a named helper set applied with $sandbox->allowHelpers('name') or 'helpers' => ['name'].
     *
     * @param Closure(Sandbox): mixed $apply
     */
    public function helpers(string $name, Closure $apply): self
    {
        $this->helperSets[$name] = $apply;
        return $this;
    }

    public function applyHelpers(string $name, Sandbox $sandbox): void
    {
        if (isset($this->helperSets[$name])) {
            ($this->helperSets[$name])($sandbox);
            return;
        }

        if ($name !== 'safe') {
            throw new InvalidArgumentException('Unknown sandbox helper set ['.$name.']. Register it with BladeSandbox::helpers().');
        }
        (new SafeHelpers())($sandbox);
    }
    //#endregion Helper sets

    //#region Text

    public function textConverter(): TextConverter
    {
        return $this->textConverter ??= new HtmlToText((int) $this->setting('text_word_wrap', 0));
    }
    //#endregion Text

    //#region Isolated rendering

    public function isolationSetting(string $key, mixed $default = null): mixed
    {
        return $this->setting('isolation.'.$key, $default);
    }

    /** The child process command: config isolation.command or `php artisan blade-sandbox:render`. */
    public function isolatedRenderer(): IsolatedRenderer
    {
        $command = $this->isolationSetting('command');
        if (!is_array($command) || $command === []) {
            $artisan = $this->container instanceof Application ? $this->container->basePath('artisan') : 'artisan';
            $command = [PHP_BINARY, $artisan, 'blade-sandbox:render'];
        }
        return new IsolatedRenderer(array_values(array_map('strval', $command)));
    }
    //#endregion Isolated rendering

    //#region Author lockout

    /** Whether violations of sandboxes tagged with forAuthor() are counted (config author_lockout.enabled). */
    public function lockoutEnabled(): bool
    {
        return $this->violationLimiter !== null || $this->setting('author_lockout.enabled', false);
    }

    public function lockout(): ViolationLimiter
    {
        if ($this->violationLimiter !== null) {
            return $this->violationLimiter;
        }
        $config = (array) $this->setting('author_lockout', []);
        if (!$this->container->bound('cache')) {
            throw new InvalidArgumentException('The author lockout needs Laravel\'s cache (illuminate/cache).');
        }
        return $this->violationLimiter = new AuthorLockout($this->container->make('cache')->store(is_string($config['store'] ?? null) ? $config['store'] : null), (int) ($config['max_violations'] ?? 5), (int) ($config['decay_minutes'] ?? 60), is_string($config['prefix'] ?? null) ? $config['prefix'] : 'blade-sandbox:lockout:');
    }

    /** Replace the violation counter (also enables counting for forAuthor() sandboxes). */
    public function useViolationLimiter(ViolationLimiter $limiter): self
    {
        $this->violationLimiter = $limiter;
        return $this;
    }

    public function markdownConverter(): MarkdownConverter
    {
        return $this->markdownConverter ??= new CommonMarkConverter();
    }

    /** Replace the @markdown converter (it must escape raw HTML and drop unsafe links). */
    public function useMarkdownConverter(MarkdownConverter $converter): self
    {
        $this->markdownConverter = $converter;
        return $this;
    }

    public function useTextConverter(TextConverter $converter): self
    {
        $this->textConverter = $converter;
        return $this;
    }
    //#endregion Author lockout

    //#region Output cache

    /** The Laravel cache store for rendered output (config "output_cache.store"; null = default store). */
    public function outputCacheStore(?string $store = null): Repository
    {
        if (!$this->container->bound('cache')) {
            throw new InvalidArgumentException('The sandbox output cache needs Laravel\'s cache (illuminate/cache).');
        }
        $configured = $this->setting('output_cache.store');
        return $this->container->make('cache')->store($store ?? (is_string($configured) ? $configured : null));
    }

    public function outputCachePrefix(): string
    {
        $prefix = $this->setting('output_cache.prefix');
        return is_string($prefix) && $prefix !== '' ? $prefix : 'blade-sandbox:output:';
    }

    /**
     * Add a value every output cache key varies by (locale, tenant, A/B bucket, ...).
     *
     * @param Closure(Sandbox, string): mixed $vary receives the sandbox and the view name
     */
    public function varyOutputCacheBy(Closure $vary): self
    {
        $this->outputCacheVaries[] = $vary;
        return $this;
    }

    /** @return list<mixed> */
    public function outputCacheVary(Sandbox $sandbox, string $view): array
    {
        return array_map(static fn (Closure $vary): mixed => $vary($sandbox, $view), $this->outputCacheVaries);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function finder(): ViewFinderAdapter
    {
        return $this->finder ??= new IlluminateViewFinderAdapter($this->viewFactory());
    }

    public function viewFactory(): FactoryContract
    {
        return $this->container->make('view');
    }

    private function bladeCompiler(): BladeCompiler
    {
        return $this->container->make('blade.compiler');
    }

    private function files(): Filesystem
    {
        return $this->container->bound('files') ? $this->container->make('files') : new Filesystem();
    }

    /** @return array<string, mixed> */
    private function cacheConfig(): array
    {
        $cache = $this->setting('cache');
        return is_array($cache) ? $cache : [];
    }

    private function createCacheStore(): CompiledTemplateStore
    {
        $config = $this->cacheConfig();
        $driver = is_string($config['driver'] ?? null) && $config['driver'] !== '' ? $config['driver'] : 'file';
        $path = is_string($config['path'] ?? null) && $config['path'] !== '' ? $config['path'] : null;
        return isset($this->cacheDrivers[$driver]) ? ($this->cacheDrivers[$driver])($config, $this) : match ($driver) {
            'file' => new FileStore($this->files(), $path ?? $this->defaultCachePath()),
            'disk' => new FileStore($this->files(), $this->diskCachePath(is_string($config['disk'] ?? null) ? $config['disk'] : null, $path ?? 'blade-sandbox')),
            'memory' => new MemoryStore($this->files(), $path),
            default => $this->customCacheStore($driver, $config),
        };
    }

    private function customCacheStore(string $driver, array $config): CompiledTemplateStore
    {
        if (!class_exists($driver) || !is_subclass_of($driver, CompiledTemplateStore::class)) {
            throw new InvalidArgumentException('Unknown blade-sandbox cache driver "'.$driver.'". Use file, disk, memory, a driver registered with extendCache() or a CompiledTemplateStore class.');
        }
        $store = $this->container->make($driver, ['config' => $config]);
        if (!$store instanceof CompiledTemplateStore) {
            throw new InvalidArgumentException('The blade-sandbox cache driver "'.$driver.'" must implement '.CompiledTemplateStore::class.'.');
        }
        return $store;
    }

    /** Compiled templates are included, so only disks on the local filesystem can hold them. */
    private function diskCachePath(?string $disk, string $directory): string
    {
        if (!$this->container->bound('filesystem')) {
            throw new InvalidArgumentException('The blade-sandbox "disk" cache driver needs illuminate/filesystem\'s FilesystemManager.');
        }
        $filesystem = $this->container->make('filesystem')->disk($disk);
        if (!$filesystem instanceof FilesystemAdapter || !($filesystem->getAdapter() instanceof LocalFilesystemAdapter)) {
            throw new InvalidArgumentException('The blade-sandbox cache disk "'.($disk ?? 'default').'" must use the local driver (compiled templates are PHP files that get included).');
        }
        return $filesystem->path(trim($directory, '/\\'));
    }

    private function defaultCachePath(): string
    {
        $path = $this->setting('cache_path');
        if (is_string($path) && $path !== '') {
            return $path;
        }
        $compiled = $this->container->bound('config') ? $this->container->make('config')->get('view.compiled') : null;
        return rtrim(is_string($compiled) && $compiled !== '' ? $compiled : sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'blade-sandbox';
    }
    //#endregion Output cache
}
