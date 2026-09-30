<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Traits\Macroable;
use Illuminate\View\Component;
use Illuminate\View\Factory;
use Illuminate\View\View;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SytxLabs\BladeSandbox\Compiler\SandboxBladeCompiler;
use SytxLabs\BladeSandbox\Contracts\FallbackRenderer;
use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;
use SytxLabs\BladeSandbox\Contracts\SandboxRenderer;
use SytxLabs\BladeSandbox\Events\AuthorLocked;
use SytxLabs\BladeSandbox\Events\SandboxLimitExceeded;
use SytxLabs\BladeSandbox\Events\SecurityViolationDetected;
use SytxLabs\BladeSandbox\Events\TemplateRendered;
use SytxLabs\BladeSandbox\Events\TemplateRenderFailed;
use SytxLabs\BladeSandbox\Exceptions\AuthorLockedException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Exceptions\TemplateIntegrityException;
use SytxLabs\BladeSandbox\Fallback\BladeStripper;
use SytxLabs\BladeSandbox\Fallback\CallbackFallback;
use SytxLabs\BladeSandbox\Fallback\EscapedFallback;
use SytxLabs\BladeSandbox\Guards\ViewGuard;
use SytxLabs\BladeSandbox\Integrity\IntegrityManifest;
use SytxLabs\BladeSandbox\Jobs\RenderTemplate;
use SytxLabs\BladeSandbox\Learning\LearningLog;
use SytxLabs\BladeSandbox\Learning\PolicySuggestion;
use SytxLabs\BladeSandbox\Policy\DirectivePolicy;
use SytxLabs\BladeSandbox\Policy\PolicyBuilder;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;
use SytxLabs\BladeSandbox\Preview\PreviewResult;
use SytxLabs\BladeSandbox\Runtime\SandboxLimits;
use SytxLabs\BladeSandbox\Runtime\SandboxRuntime;
use SytxLabs\BladeSandbox\Runtime\SandboxViewEngine;
use SytxLabs\BladeSandbox\Sanitizers\CallbackSanitizer;
use SytxLabs\BladeSandbox\Support\Patterns;
use SytxLabs\BladeSandbox\Validation\ValidationResult;
use SytxLabs\BladeSandbox\Validation\Violation;
use Throwable;

/**
 * A configurable sandbox: a security policy plus limits, able to render untrusted Blade.
 * All allow*() methods mutate this sandbox and return it for chaining. Every render takes an immutable snapshot of the policy, so changing a sandbox never affects a render in progress.
 * The API is macroable: `Sandbox::macro('cms', fn () => $this->allowView('cms::**')->allowDto(...))` defines a reusable preset (`$sandbox->cms()`, or `'presets' => ['cms']` in config/blade-sandbox.php).
 */
final class Sandbox implements SandboxRenderer
{
    use Macroable;

    private bool $debug = false;

    private LoggerInterface|string|null $logger = null;

    private ?string $logLevel = null;

    private ?IntegrityManifest $integrity = null;

    /** @var array{timeout: int, memory_limit: string}|null isolated rendering (see isolated()) */
    private ?array $isolation = null;

    /** Template author (user, tenant, ...) whose violations are counted by the author lockout. */
    private ?string $author = null;

    /** Name of the config policy this sandbox was created from (see originatesFrom()). */
    private ?string $origin = null;

    /** @var list<array{pattern: string|null, sanitizer: OutputSanitizer}> */
    private array $sanitizers = [];

    private FallbackRenderer|false|string $fallback = false;

    /** @var array{ttl: DateInterval|DateTimeInterface|int|null, store: string|null, key: (Closure(string, array<string, mixed>): mixed)|null}|null */
    private ?array $outputCache = null;

    public function __construct(private readonly SandboxManager $manager, private PolicyBuilder $builder = new PolicyBuilder(), private SandboxLimits $limits = new SandboxLimits())
    {
    }

    public function __clone()
    {
        $this->builder = clone $this->builder;
    }

    //#region Inheritance

    /** Inherit every rule of other sandboxes or config policies (by name): allow and deny rules are united, deny rules keep winning, and this sandbox's limits, sanitizers and fallback stay its own. */
    public function extend(self|string ...$parents): self
    {
        foreach ($parents as $parent) {
            $this->builder->merge((is_string($parent) ? $this->manager->policy($parent) : $parent)->builder);
        }

        return $this;
    }

    /** @internal records the config policy this sandbox was created from (used by isolated rendering) */
    public function originatesFrom(?string $policy): self
    {
        $this->origin = $policy;

        return $this;
    }

    public function origin(): ?string
    {
        return $this->origin;
    }
    //#endregion Inheritance

    //#region Isolated rendering

    /**
     * Render in a separate PHP process with a hard wall-clock timeout and memory limit (defaults from config "isolation").
     * This also stops slow or blocking calls into application code that the cooperative limits cannot interrupt. Sanitizer, fallback, output cache, events and the author lockout still run in this process;
     * the child only performs the sandboxed render.
     *
     * The child must be able to rebuild the policy: either the sandbox's rules are serializable (no closures) or the sandbox is an unmodified config policy (BladeSandbox::policy('name')).
     * Template loaders, macros and helper sets must be registered in service providers or config.
     */
    public function isolated(?int $timeoutSeconds = null, ?string $memoryLimit = null): self
    {
        /** @noinspection ToStringCallInspection */
        $this->isolation = [
            'timeout' => max(1, $timeoutSeconds ?? (int) $this->manager->isolationSetting('timeout', 10)),
            'memory_limit' => $memoryLimit ?? (string) $this->manager->isolationSetting('memory_limit', '256M'),
        ];

        return $this;
    }

    public function withoutIsolation(): self
    {
        $this->isolation = null;

        return $this;
    }

    /**
     * Everything the child process needs to rebuild this sandbox.
     *
     * @internal
     *
     * @param bool $withOutput also transfer sanitizers, fallback, isolation and author (queued renders run the complete pipeline; closures there cannot be transferred and are rejected)
     *
     * @return array{builder: string|null, origin: string|null, limits: string, integrity: string|null, debug: bool, log_channel?: string|null, log_level?: string|null, output?: string, output_isolation?: array{timeout: int, memory_limit: string}|null, output_author?: string|null}
     */
    public function isolationState(bool $withOutput = false): array
    {
        try {
            $builder = serialize($this->builder);
        } catch (Throwable) {
            $builder = null;
        }
        if ($builder === null && ($this->origin === null || $this->manager->policy($this->origin)->policy()->fingerprint() !== $this->policy()->fingerprint())) {
            throw new SandboxException('Isolated rendering cannot transfer this sandbox: it uses closures (component factories or directive handlers). Define it as a policy in config/blade-sandbox.php and use BladeSandbox::policy(\'name\') unmodified, or register the handlers there.');
        }
        $state = [
            'builder' => $builder,
            'origin' => $this->origin,
            'limits' => serialize($this->limits),
            'integrity' => $this->integrity !== null ? serialize($this->integrity) : null,
            'debug' => $this->debug,
            'log_channel' => is_string($this->logger) ? $this->logger : null,
            'log_level' => $this->logLevel,
        ];

        if ($withOutput) {
            $state['output_isolation'] = $this->isolation;
            $state['output_author'] = $this->author;
            try {
                $state['output'] = serialize(['sanitizers' => $this->sanitizers, 'fallback' => $this->fallback]);
            } catch (Throwable) {
                throw new SandboxException('This sandbox cannot be queued: its output sanitizer or fallback is a closure. Use a named sanitizer (config "sanitizers") or an OutputSanitizer / FallbackRenderer class.');
            }
        }

        return $state;
    }

    /**
     * @internal rebuilds a sandbox from isolationState() in the child process
     *
     * @param array{builder: string|null, origin: string|null, limits: string, integrity: string|null, debug: bool, log_channel?: string|null, log_level?: string|null, output?: string, output_isolation?: array{timeout: int, memory_limit: string}|null, output_author?: string|null} $state
     */
    public static function fromIsolationState(SandboxManager $manager, array $state): self
    {
        $limits = unserialize($state['limits'], ['allowed_classes' => [SandboxLimits::class]]);
        $limits = $limits instanceof SandboxLimits ? $limits : new SandboxLimits();

        if ($state['builder'] !== null) {
            $builder = unserialize($state['builder'], ['allowed_classes' => [PolicyBuilder::class]]);
            if (!$builder instanceof PolicyBuilder) {
                throw new SandboxException('Invalid isolated render payload.');
            }
            $sandbox = new self($manager, $builder, $limits);
        } else {
            $sandbox = $manager->policy((string) $state['origin']);
            $sandbox->limits = $limits;
        }

        $integrity = $state['integrity'] !== null ? unserialize($state['integrity'], ['allowed_classes' => [IntegrityManifest::class]]) : null;
        $sandbox->integrity = $integrity instanceof IntegrityManifest ? $integrity : null;
        $sandbox->debug = $state['debug'];
        if (is_string($state['log_channel'] ?? null)) {
            $sandbox->logger = $state['log_channel'];
        }
        if (is_string($state['log_level'] ?? null)) {
            $sandbox->logLevel = $state['log_level'];
        }

        if (isset($state['output'])) {
            $output = unserialize($state['output'], ['allowed_classes' => true]);
            if (!is_array($output) || !is_array($output['sanitizers'] ?? null)) {
                throw new SandboxException('Invalid isolated render payload.');
            }
            $sanitizers = [];
            foreach ($output['sanitizers'] as $entry) {
                if (!is_array($entry) || !($entry['sanitizer'] ?? null) instanceof OutputSanitizer || !(is_string($entry['pattern'] ?? null) || ($entry['pattern'] ?? null) === null)) {
                    throw new SandboxException('Invalid isolated render payload.');
                }
                $sanitizers[] = ['pattern' => $entry['pattern'], 'sanitizer' => $entry['sanitizer']];
            }
            $fallback = $output['fallback'] ?? false;
            if (!$fallback instanceof FallbackRenderer && !is_string($fallback) && $fallback !== false) {
                throw new SandboxException('Invalid isolated render payload.');
            }
            $sandbox->sanitizers = $sanitizers;
            $sandbox->fallback = $fallback;
            $sandbox->isolation = is_array($state['output_isolation'] ?? null) ? $state['output_isolation'] : null;
            $sandbox->author = is_string($state['output_author'] ?? null) ? $state['output_author'] : null;
        }

        return $sandbox;
    }
    //#endregion Isolated rendering

    //#region Queued rendering

    /**
     * Render a template string on the queue. $then receives the HTML: an invokable class name (resolved from the container, called with (string $html, array $context)) or a closure;
     * $catch receives (Throwable $e, array $context) when the render fails. The complete pipeline runs in the worker (sanitizer, fallback, events, author lockout; isolated() if set).
     *
     * @param array<string, mixed> $data serializable template data
     * @param class-string|(Closure(string, array<string, mixed>): mixed)|null $then
     * @param array<string, mixed> $context passed to $then / $catch (ids, recipients, ...)
     * @param class-string|(Closure(Throwable, array<string, mixed>): mixed)|null $catch
     */
    public function queueRender(string $template, array $data = [], Closure|string|null $then = null, array $context = [], Closure|string|null $catch = null): PendingDispatch
    {
        return $this->queueJob(['kind' => 'string', 'template' => $template, 'name' => 'inline:'.substr(hash('sha256', $template), 0, 12)], $data, $then, $context, $catch);
    }

    /**
     * Render a view on the queue (see queueRender()).
     *
     * @param array<string, mixed> $data
     * @param class-string|(Closure(string, array<string, mixed>): mixed)|null $then
     * @param array<string, mixed> $context
     * @param class-string|(Closure(Throwable, array<string, mixed>): mixed)|null $catch
     */
    public function queueRenderView(string $view, array $data = [], Closure|string|null $then = null, array $context = [], Closure|string|null $catch = null): PendingDispatch
    {
        return $this->queueJob(['kind' => 'view', 'view' => $view], $data, $then, $context, $catch);
    }

    /**
     * @internal the complete pipeline for a job (queued renders)
     *
     * @param array{kind: string, template?: string, name?: string, view?: string, path?: string} $job
     * @param array<string, mixed> $data
     */
    public function renderJobThroughPipeline(array $job, array $data): string
    {
        return match ($job['kind']) {
            'string' => $this->render((string) ($job['template'] ?? ''), $data),
            'file' => $this->renderFile((string) ($job['path'] ?? ''), (string) ($job['view'] ?? ''), $data),
            default => $this->renderView((string) ($job['view'] ?? ''), $data),
        };
    }

    /**
     * @param array{kind: string, template?: string, name?: string, view?: string, path?: string} $job
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function queueJob(array $job, array $data, Closure|string|null $then, array $context, Closure|string|null $catch): PendingDispatch
    {
        try {
            $payload = base64_encode(serialize(['state' => $this->isolationState(true), 'job' => $job, 'data' => $data, 'context' => $context]));
        } catch (SandboxException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SandboxException('Queued rendering needs serializable template data and context: '.$exception->getMessage());
        }
        return RenderTemplate::dispatch($payload, RenderTemplate::callback($then), RenderTemplate::callback($catch));
    }
    //#endregion Queued rendering

    //#region Author lockout

    /**
     * Tag renders / validations with the template author (user id, tenant, ...). With the author lockout enabled (config author_lockout.enabled or BladeSandbox::useViolationLimiter()), every security violation counts;
     * after max_violations within decay_minutes the author is locked: renders fail with AuthorLockedException (the fallback applies) and validation reports the lock.
     */
    public function forAuthor(int|string $author): self
    {
        $this->author = (string) $author;

        return $this;
    }

    public function author(): ?string
    {
        return $this->author;
    }

    private function authorLocked(): bool
    {
        return $this->author !== null && $this->manager->lockoutEnabled() && $this->manager->lockout()->isLocked($this->author);
    }

    private function recordViolation(): void
    {
        if ($this->author === null || !$this->manager->lockoutEnabled()) {
            return;
        }

        $limiter = $this->manager->lockout();
        $hits = $limiter->hit($this->author);
        if ($hits === $limiter->maxViolations()) {
            $this->manager->dispatch(new AuthorLocked($this->author, $hits));
        }
    }

    /** Validation of a locked author reports the lock; failing validations count as one violation. */
    private function checkedValidation(string $name, Closure $validate): ValidationResult
    {
        if ($this->authorLocked()) {
            return new ValidationResult([new Violation($name, 'author', (string) $this->author, AuthorLockedException::for((string) $this->author)->getMessage())]);
        }
        $result = $validate();
        if ($result->fails()) {
            $this->recordViolation();
        }
        return $result;
    }
    //#endregion Author lockout

    //#region Views

    /** Allow a view by exact name or pattern: "plugin::page", "plugin::emails.*", "plugin::partials.**". */
    public function allowView(string $pattern): self
    {
        $this->builder->allowView($pattern);
        return $this;
    }

    /** Allow every view of a namespace registered with loadViewsFrom()/addNamespace() (e.g. "plugin"). */
    public function allowViewNamespace(string $namespace): self
    {
        $this->builder->allowViewNamespace($namespace);
        return $this;
    }
    //#endregion Views

    //#region DTOs

    /** Allow a DTO class (and its subclasses) as template data object with its full public API. */
    public function allowDto(string $class): self
    {
        $this->builder->allowDto($class);
        return $this;
    }

    /** Allow every class inside a namespace (e.g. "App\\DTO\\") as DTO. */
    public function allowDtoNamespace(string $namespace): self
    {
        $this->builder->allowDtoNamespace($namespace);
        return $this;
    }

    /**
     * Whether iteration / string conversion / toArray() of DTOs may use the DTO's own implementation (default: true). When disabled, the sandbox builds them from public properties only.
     */
    public function nativeDtoConversion(bool $enabled = true): self
    {
        $this->builder->nativeDtoConversion($enabled);
        return $this;
    }
    //#endregion DTOs

    //#region Objects

    /** @param list<string>|string $methods method names or "*" for every public method */
    public function allowMethod(string $class, array|string $methods): self
    {
        $this->builder->allowMethod($class, $methods);
        return $this;
    }

    /** @param list<string>|string $properties property names or "*" */
    public function allowProperty(string $class, array|string $properties): self
    {
        $this->builder->allowProperty($class, $properties);
        return $this;
    }

    public function allowArrayAccess(string $class): self
    {
        $this->builder->allowArrayAccess($class);
        return $this;
    }

    public function allowIteration(string $class): self
    {
        $this->builder->allowIteration($class);
        return $this;
    }

    public function allowStringConversion(string $class): self
    {
        $this->builder->allowStringConversion($class);
        return $this;
    }

    /** Allow printing instances unescaped via toHtml() in {{ }} (only for classes producing trusted markup). */
    public function allowHtmlable(string $class): self
    {
        $this->builder->allowHtmlable($class);
        return $this;
    }
    //#endregion Objects

    //#region Functions & constants

    /** Allow one or more global functions: allowFunction('route', 'asset'). */
    public function allowFunction(string ...$functions): self
    {
        foreach ($functions as $function) {
            $this->builder->allowFunction($function);
        }
        return $this;
    }

    public function allowConstant(string $constant): self
    {
        $this->builder->allowConstant($constant);
        return $this;
    }

    /** @param list<string>|string $constants constant / enum case names or "*" */
    public function allowClassConstant(string $class, array|string $constants = '*'): self
    {
        $this->builder->allowClassConstant($class, $constants);
        return $this;
    }

    /** @param list<string>|string $methods static method names or "*" */
    public function allowStaticMethod(string $class, array|string $methods): self
    {
        $this->builder->allowStaticMethod($class, $methods);
        return $this;
    }

    /**
     * Allow macros registered on a Macroable class, e.g. `allowMacro(Collection::class, 'toUpper')` for `$items->toUpper()` or `allowMacro(Str::class, 'initials')` for `Str::initials($name)`.
     * "*" (the default) allows every macro registered on the class, now or later.
     *
     * Macros are the only undeclared methods a template can call besides names allowed explicitly with allowMethod(); "*" in allowMethod() and allowClassNamespace() cover declared public methods only.
     *
     * @param list<string>|string $macros
     */
    public function allowMacro(string $class, array|string $macros = '*'): self
    {
        $this->builder->allowMacro($class, $macros);
        return $this;
    }

    /**
     * Allow the public API of every class in a PHP namespace and its sub-namespaces, e.g. allowClassNamespace('App\\Options') for the application's enums:
     * cases/constants, public (static and instance) methods, public properties and string conversion.
     */
    public function allowClassNamespace(string $namespace): self
    {
        $this->builder->allowClassNamespace($namespace);
        return $this;
    }
    //#endregion Functions & constants

    //#region Components & directives

    /**
     * Allow a Blade component ("plugin::button", "alert", "forms.*").
     *
     * @param (Closure(array<string, mixed> $attributes): Component)|null $factory explicit constructor for class components that need services (the container is never used implicitly)
     */
    public function allowComponent(string $component, ?Closure $factory = null): self
    {
        $this->builder->allowComponent($component, $factory);
        return $this;
    }

    /** Enable a supported opt-in Blade directive (csrf, method, lang, choice, error, livewire, auth, guest, can, cannot, canany). */
    public function allowDirective(string ...$directives): self
    {
        foreach ($directives as $directive) {
            $this->builder->allowDirective($directive);
        }
        return $this;
    }

    /**
     * Enable all authentication / authorization directives at once: `@auth`, `@guest`, `@can`,
     * `@cannot` and `@canany` (with their `@else…` / `@end…` variants).
     */
    public function allowAuthDirectives(): self
    {
        return $this->allowDirective(...DirectivePolicy::AUTH);
    }

    public function denyDirective(string $directive): self
    {
        $this->builder->denyDirective($directive);
        return $this;
    }

    /**
     * Register a sandbox-safe custom directive. The handler runs at render time with the already evaluated (sandboxed) arguments; its string result is escaped unless it returns Htmlable.
     */
    public function directive(string $name, Closure $handler): self
    {
        $this->builder->directive($name, $handler);
        return $this;
    }

    public function allowRawEcho(bool $allow = true): self
    {
        $this->builder->allowRawEcho($allow);
        return $this;
    }
    //#endregion Components & directives

    //#region Livewire

    public function allowLivewireDirective(string $directive): self
    {
        $this->builder->allowLivewireDirective($directive);
        return $this;
    }

    public function allowLivewireAction(string $action): self
    {
        $this->builder->allowLivewireAction($action);
        return $this;
    }

    public function allowLivewireModel(string $property): self
    {
        $this->builder->allowLivewireModel($property);
        return $this;
    }

    public function allowLivewireEvent(string $event): self
    {
        $this->builder->allowLivewireEvent($event);
        return $this;
    }

    public function allowLivewireComponent(string $component): self
    {
        $this->builder->allowLivewireComponent($component);
        return $this;
    }

    /** Allow Alpine attributes, inline event handlers and <script> in Livewire-enabled apps. */
    public function allowAlpine(bool $allow = true): self
    {
        $this->builder->allowAlpine($allow);
        return $this;
    }
    //#endregion Livewire

    //#region Limits & debugging

    public function maxDepth(int $depth): self
    {
        $this->limits = $this->limits->with(['maxDepth' => $depth]);
        return $this;
    }

    public function maxOutputBytes(int $bytes): self
    {
        $this->limits = $this->limits->with(['maxOutputBytes' => $bytes]);
        return $this;
    }

    /** Maximum loop iterations per render (0 = unlimited). Stops infinite @while / @for / @foreach loops. */
    public function maxIterations(int $iterations): self
    {
        $this->limits = $this->limits->with(['maxIterations' => $iterations]);
        return $this;
    }

    /** Wall-clock time limit per render in milliseconds (0 = unlimited). */
    public function timeout(int $milliseconds): self
    {
        $this->limits = $this->limits->with(['timeoutMs' => $milliseconds]);
        return $this;
    }

    /** Maximum memory growth during a render in bytes (0 = unlimited). */
    public function maxMemory(int $bytes): self
    {
        $this->limits = $this->limits->with(['maxMemoryBytes' => $bytes]);
        return $this;
    }

    public function limits(): SandboxLimits
    {
        return $this->limits;
    }

    /** Log every security violation (structural information only) to the configured logger (config "logging"). */
    public function debug(bool $enabled = true): self
    {
        $this->debug = $enabled;
        return $this;
    }

    /**
     * Log this sandbox's violations to a PSR-3 logger, a LoggerInterface class or a log channel name, at the
     * given level (default: config logging.level); enables logging. Only channel / class names are carried
     * over to isolated and queued renders.
     */
    public function logUsing(LoggerInterface|string $logger, ?string $level = null): self
    {
        $this->logger = $logger;
        $this->logLevel = $level;
        $this->debug = true;

        return $this;
    }

    public function logger(): LoggerInterface|string|null
    {
        return $this->logger;
    }

    public function logLevel(): ?string
    {
        return $this->logLevel;
    }

    /**
     * Only render view files listed in the manifest with unchanged contents (see `blade-sandbox:hash`).
     * A manifest path or array is verified with the integrity key (APP_KEY by default); unsigned manifests are refused unless $requireSignature is false. Raw template strings (render()) are not affected.
     *
     * @param array<string, mixed>|IntegrityManifest|string|null $manifest null disables the check
     */
    public function verifyIntegrity(array|IntegrityManifest|string|null $manifest, bool $requireSignature = true): self
    {
        $this->integrity = $manifest === null ? null : $this->manager->manifest($manifest, $requireSignature);
        return $this;
    }
    //#endregion Limits & debugging

    //#region Output sanitizing

    /**
     * Sanitize the complete output of pages rendered by this sandbox.
     *
     * @param Closure(string, string): string|OutputSanitizer|string $sanitizer instance, closure or name from config("blade-sandbox.sanitizers") (e.g. "filesanitizer")
     * @param string|null $view only for entry views matching this name / pattern ("plugin::pages.*"); null = every page without a more specific rule
     */
    public function sanitizeWith(Closure|OutputSanitizer|string $sanitizer = 'default', ?string $view = null): self
    {
        $this->sanitizers = array_values(array_filter($this->sanitizers, static fn (array $rule): bool => $rule['pattern'] !== $view));
        $this->sanitizers[] = ['pattern' => $view, 'sanitizer' => match (true) {
            $sanitizer instanceof OutputSanitizer => $sanitizer,
            $sanitizer instanceof Closure => new CallbackSanitizer($sanitizer),
            default => $this->manager->sanitizer($sanitizer),
        }];
        return $this;
    }

    /** Remove all output sanitizers (or only the rule for one view pattern). */
    public function withoutSanitizer(?string $view = null): self
    {
        $this->sanitizers = func_num_args() === 0 ? [] : array_values(array_filter($this->sanitizers, static fn (array $rule): bool => $rule['pattern'] !== $view));
        return $this;
    }

    /** The sanitizer that applies to an entry view (specific patterns first, then the default rule). */
    public function sanitizerFor(string $view): ?OutputSanitizer
    {
        $default = null;
        foreach ($this->sanitizers as $rule) {
            if ($rule['pattern'] === null) {
                $default = $rule['sanitizer'];
            } elseif (Patterns::matches($rule['pattern'], $view) || (str_ends_with($rule['pattern'], '::') && str_starts_with($view, $rule['pattern']))) {
                return $rule['sanitizer'];
            }
        }

        return $default;
    }

    private function sanitizeOutput(string $view, string $html): string
    {
        $sanitizer = $this->sanitizerFor($view);
        if ($sanitizer === null) {
            return $html;
        }

        try {
            return $sanitizer->sanitize($html, $view);
        } catch (SecurityViolationException $violation) {
            $this->manager->auditLogger($this->debug, $this->logger, $this->logLevel)->record($violation, $view);
            throw $violation;
        }
    }

    /**
     * Enable @markdown($text) and @markdown … @endmarkdown (league/commonmark; raw HTML is escaped and unsafe links are removed).
     */
    public function allowMarkdown(): self
    {
        return $this->allowDirective('markdown');
    }
    //#endregion Output sanitizing

    //#region Translations & routes

    /**
     * Translations are on by default for every key: __(), trans(), trans_choice(), @lang and @choice. With patterns ("cms.*", "validation.*") only matching keys are available.
     */
    public function allowTranslations(string ...$patterns): self
    {
        $this->builder->allowTranslations(...$patterns);
        return $this;
    }

    /** Switch translations off completely (__(), trans(), trans_choice(), @lang, @choice). */
    public function withoutTranslations(): self
    {
        $this->builder->withoutTranslations();
        return $this;
    }

    public function denyTranslation(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->builder->denyTranslation($pattern);
        }
        return $this;
    }

    /**
     * Make route() available for route names matching the patterns ("shop.*", "pages.show"). Prefer this over allowFunction('route'), which allows every route name.
     */
    public function allowRoutes(string ...$patterns): self
    {
        $this->builder->allowRoutes(...$patterns);
        return $this;
    }

    public function denyRoute(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->builder->denyRoute($pattern);
        }
        return $this;
    }
    //#endregion Translations & routes

    //#region Deny rules (always win over allows)

    /**
     * Take classes back out of broader grants (class namespaces, "*" rules, value-object defaults, DTOs, macros): nothing of the class, its subclasses or implementors is reachable any more.
     */
    public function denyClass(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->builder->denyClass($class);
        }
        return $this;
    }

    /** Deny every class of a namespace and its sub-namespaces, e.g. "App\\Options\\Internal". */
    public function denyClassNamespace(string ...$namespaces): self
    {
        foreach ($namespaces as $namespace) {
            $this->builder->denyClassNamespace($namespace);
        }
        return $this;
    }

    /**
     * Deny methods (instance, static and macro calls of that name); "*" = every method.
     *
     * @param list<string>|string $methods
     */
    public function denyMethod(string $class, array|string $methods): self
    {
        $this->builder->denyMethod($class, $methods);
        return $this;
    }

    /** @param list<string>|string $properties */
    public function denyProperty(string $class, array|string $properties): self
    {
        $this->builder->denyProperty($class, $properties);
        return $this;
    }

    /**
     * Deny class constants and enum cases; "*" = all.
     *
     * @param list<string>|string $constants
     */
    public function denyClassConstant(string $class, array|string $constants): self
    {
        $this->builder->denyClassConstant($class, $constants);
        return $this;
    }

    /** @param list<string>|string $methods */
    public function denyStaticMethod(string $class, array|string $methods): self
    {
        $this->builder->denyStaticMethod($class, $methods);
        return $this;
    }

    /** Deny functions, e.g. to take single helpers back out of allowSafeHelpers(). */
    public function denyFunction(string ...$functions): self
    {
        foreach ($functions as $function) {
            $this->builder->denyFunction($function);
        }
        return $this;
    }

    public function denyConstant(string ...$constants): self
    {
        foreach ($constants as $constant) {
            $this->builder->denyConstant($constant);
        }
        return $this;
    }

    /** Deny views by name or pattern ("cms::admin.**"), also inside allowed namespaces and for component views. */
    public function denyView(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->builder->denyView($pattern);
        }
        return $this;
    }
    //#endregion Deny rules (always win over allows)

    //#region Fallback, output cache, helpers

    /**
     * Instead of throwing, return fallback output when a render fails (security violation, limit, syntax error, missing view, ...): "source" = the unrendered template, "strip" = the template without Blade syntax,
     * "escaped" = the template as escaped text, "empty" = nothing, a mode registered with BladeSandbox::extendFallback(),
     * a FallbackRenderer or a closure fn (string $source, string $view, Throwable $e): string. false disables the fallback.
     *
     * The failure is still reported (config "fallback_report"), audited and dispatched as TemplateRenderFailed.
     * Livewire attributes (and Alpine attributes unless allowed) are removed from the fallback output and the configured output sanitizer runs on it.
     *
     * @param (Closure(string, string, Throwable): string)|FallbackRenderer|false|string $mode
     */
    public function renderFallback(Closure|FallbackRenderer|false|string $mode = 'source'): self
    {
        if (is_string($mode)) {
            $this->manager->fallback($mode); // validates the name early
        }
        $this->fallback = $mode instanceof Closure ? new CallbackFallback($mode) : $mode;
        return $this;
    }

    /**
     * Cache the rendered (sanitized) output in Laravel's cache. The key covers the policy, the view, the top-level template source, the data, the sanitizer, the signed-in user when auth directives are allowed,
     * the CSRF token when @csrf is allowed, values registered with BladeSandbox::varyOutputCacheBy() and the optional $key callback. Changes to included views are picked up when the TTL expires.
     * Livewire output and data that cannot be serialized are never cached.
     *
     * @param (Closure(string, array<string, mixed>): mixed)|null $key extra key parts: fn (string $view, array $data)
     */
    public function cacheOutput(DateInterval|DateTimeInterface|int|null $ttl = 3600, ?string $store = null, ?Closure $key = null): self
    {
        $this->outputCache = ['ttl' => $ttl, 'store' => $store, 'key' => $key];
        return $this;
    }

    public function withoutOutputCache(): self
    {
        $this->outputCache = null;
        return $this;
    }

    /** Apply named helper sets ("safe" is built in; register more with BladeSandbox::helpers()). */
    public function allowHelpers(string ...$sets): self
    {
        foreach ($sets as $set) {
            $this->manager->applyHelpers($set, $this);
        }
        return $this;
    }

    /** The built-in "safe" helper set: string / number / date formatting functions (strtoupper, number_format, date, now, ...), Str::limit/slug/title/... and Number::format/currency/... (see Helpers\SafeHelpers). */
    public function allowSafeHelpers(): self
    {
        return $this->allowHelpers('safe');
    }
    //#endregion Fallback, output cache, helpers

    //#region Validation

    /** Validate a template string against this sandbox's policy without rendering it. */
    public function validate(string $template, string $name = 'inline'): ValidationResult
    {
        return $this->checkedValidation($name, fn (): ValidationResult => $this->manager->validator()->validateSource($template, $this->policy(), $name));
    }

    /** Validate a view (and the views, layouts and components it names literally) without rendering it. */
    public function validateView(string $view): ValidationResult
    {
        return $this->checkedValidation($view, fn (): ValidationResult => $this->integrityResult($view)->merge($this->manager->validator()->validateView($view, $this->policy())));
    }

    /** Validate a template file (e.g. an upload that is not registered as a view yet). */
    public function validateFile(string $path, ?string $name = null): ValidationResult
    {
        return $this->checkedValidation($name ?? $path, fn (): ValidationResult => $this->manager->validator()->validateFile($path, $this->policy(), $name));
    }

    private function integrityResult(string $view): ValidationResult
    {
        if ($this->integrity === null || !$this->manager->sources()->exists($view)) {
            return new ValidationResult();
        }
        try {
            $this->integrity->assert($view, $this->manager->sources()->source($view));
        } catch (TemplateIntegrityException $violation) {
            return new ValidationResult([Violation::fromException($violation, $view)]);
        }
        return new ValidationResult();
    }

    public function isDebugging(): bool
    {
        return $this->debug;
    }
    //#endregion Validation

    //#region Rendering

    public function policy(): SecurityPolicy
    {
        return $this->builder->build();
    }

    public function render(string $template, array $data = []): string
    {
        $name = 'inline:'.substr(hash('sha256', $template), 0, 12);
        return $this->pipeline($name, $data, static fn (): string => $template, ['kind' => 'string', 'template' => $template, 'name' => $name]);
    }

    public function renderView(string $view, array $data = []): string
    {
        return $this->pipeline($view, $data, fn (): string => $this->manager->sources()->source($view), ['kind' => 'view', 'view' => $view]);
    }

    /**
     * Render an already resolved view file. Used by the view engines; the view name is still checked. Views served by a template loader are rendered from the loader ($path is ignored).
     *
     * @param array<string, mixed> $data
     */
    public function renderFile(string $path, string $view, array $data = []): string
    {
        return $this->manager->sources()->usesLoader($view)
            ? $this->renderView($view, $data)
            : $this->pipeline($view, $data, static fn (): string => is_file($path) && is_readable($path) ? (string) file_get_contents($path) : '', ['kind' => 'file', 'path' => $path, 'view' => $view]);
    }

    /**
     * The raw render of a job (no sanitizer, fallback, cache or events): used by the pipeline and, in a child process, by isolated rendering.
     *
     * @internal
     *
     * @param array{kind: string, template?: string, name?: string, view?: string, path?: string} $job
     * @param array<string, mixed> $data
     */
    public function renderJob(array $job, array $data, ?SandboxRuntime $runtime = null): string
    {
        $runtime ??= $this->runtime();
        if ($job['kind'] === 'string') {
            return $runtime->renderer()->renderString((string) ($job['template'] ?? ''), $data, $runtime, (string) ($job['name'] ?? 'inline'));
        }
        $view = (string) ($job['view'] ?? '');
        (new ViewGuard($runtime->policy(), $runtime->audit()))->forTemplate($view)->assertView($view);
        return $job['kind'] === 'file' ? $runtime->renderer()->renderFile((string) ($job['path'] ?? ''), $view, $data, $runtime) : $runtime->renderer()->renderView($view, $data, $runtime);
    }

    /**
     * Render a template string and convert the result to plain text (e.g. the text part of an e-mail).
     *
     * @param array<string, mixed> $data
     */
    public function renderText(string $template, array $data = []): string
    {
        return $this->manager->textConverter()->convert($this->render($template, $data));
    }

    /** @param array<string, mixed> $data */
    public function renderViewText(string $view, array $data = []): string
    {
        return $this->manager->textConverter()->convert($this->renderView($view, $data));
    }
    //#endregion Rendering

    //#region Policy learning

    /** Static policy suggestion: every literally named function, method, view, component, directive, route or translation key the template uses that this sandbox does not allow yet. Nothing is rendered. */
    public function suggestPolicy(string $template, string $name = 'inline'): PolicySuggestion
    {
        return $this->suggest(fn (self $probe): ValidationResult => $this->manager->validator()->validateSource($template, $probe->policy(), $name));
    }

    public function suggestPolicyForView(string $view): PolicySuggestion
    {
        return $this->suggest(fn (self $probe): ValidationResult => $this->manager->validator()->validateView($view, $probe->policy()));
    }

    /**
     * Learning mode: the static suggestion plus a render with sample data in which every denied operation (methods and properties of the data, iteration, string conversion, includes, ...) is
     * recorded instead of failing. Denied operations are never executed; the template continues with a neutral value. Risky candidates are flagged instead of suggested.
     *
     * @param array<string, mixed> $data sample data
     */
    public function learn(string $template, array $data = [], string $name = 'learn'): PolicySuggestion
    {
        return $this->suggest(fn (self $probe): ValidationResult => $this->manager->validator()->validateSource($template, $probe->policy(), $name), static function (self $probe, LearningLog $log) use ($template, $data, $name): void {
            $runtime = $probe->runtime()->learnInto($log);
            $runtime->renderer()->renderString($template, $data, $runtime, $name);
        });
    }

    /** @param array<string, mixed> $data sample data */
    public function learnView(string $view, array $data = []): PolicySuggestion
    {
        return $this->suggest(fn (self $probe): ValidationResult => $this->manager->validator()->validateView($view, $probe->policy()), static function (self $probe, LearningLog $log) use ($view, $data): void {
            $runtime = $probe->runtime()->learnInto($log);
            $runtime->renderer()->renderView($view, $data, $runtime);
        });
    }

    /**
     * @param Closure(self): ValidationResult $validate
     * @param (Closure(self, LearningLog): void)|null $render
     */
    private function suggest(Closure $validate, ?Closure $render = null): PolicySuggestion
    {
        $probe = clone $this;
        $findings = [];
        $errors = [];

        for ($round = 0; $round < 25; $round++) {
            $progress = false;
            foreach ($validate($probe)->violations() as $violation) {
                if (in_array($violation->capability, ['syntax', 'construct', 'file', 'target'], true)) {
                    $errors[] = ($violation->line !== null ? 'Line '.$violation->line.': ' : '').$violation->message;
                    continue;
                }
                $key = $violation->capability.'|'.$violation->subject;
                if (!isset($findings[$key])) {
                    $findings[$key] = ['capability' => $violation->capability, 'subject' => $violation->subject, 'message' => $violation->message];
                    $progress = self::probeCompileAllowance($probe, $violation->capability, $violation->subject) || $progress;
                }
            }
            if (!$progress) {
                break;
            }
        }

        if ($render !== null && $errors === []) {
            $log = new LearningLog();
            try {
                $render($probe, $log);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
            foreach ($log->findings() as $finding) {
                $findings[$finding['capability'].'|'.$finding['subject']] ??= $finding;
            }
        }

        return new PolicySuggestion(array_values($findings), $errors);
    }

    /** Allows a compile-time capability on the probe copy; returns whether compiling again can find more. */
    private static function probeCompileAllowance(self $probe, string $capability, string $subject): bool
    {
        if ($capability !== 'directive') {
            return false;
        }
        if ($subject === '{!! !!}') {
            $probe->allowRawEcho();
            return true;
        }

        try {
            $probe->allowDirective(ltrim($subject, '@'));
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
    //#endregion Policy learning

    //#region Preview

    /**
     * Validate and render a template without throwing: returns the HTML (if it rendered) plus every static finding and the runtime error with line numbers where known. For editors with live preview.
     * The fallback, the output cache and events are not used.
     *
     * @param array<string, mixed> $data sample data
     */
    public function preview(string $template, array $data = [], string $name = 'preview'): PreviewResult
    {
        return $this->runPreview($name, $this->validate($template, $name), static fn (SandboxRuntime $runtime): string => $runtime->renderer()->renderString($template, $data, $runtime, $name));
    }

    /** @param array<string, mixed> $data sample data */
    public function previewView(string $view, array $data = []): PreviewResult
    {
        return $this->runPreview($view, $this->validateView($view), static function (SandboxRuntime $runtime) use ($view, $data): string {
            (new ViewGuard($runtime->policy(), $runtime->audit()))->forTemplate($view)->assertView($view);
            return $runtime->renderer()->renderView($view, $data, $runtime);
        });
    }

    /** @param Closure(SandboxRuntime): string $render */
    private function runPreview(string $view, ValidationResult $validation, Closure $render): PreviewResult
    {
        $started = hrtime(true);
        if ($this->authorLocked()) {
            return new PreviewResult($view, null, $validation, AuthorLockedException::for((string) $this->author));
        }
        try {
            $html = $this->sanitizeOutput($view, $render($this->runtime()));
        } catch (Throwable $exception) {
            if ($exception instanceof SecurityViolationException && $validation->passes()) {
                $this->recordViolation();
            }
            return new PreviewResult($view, null, $validation, $exception, (hrtime(true) - $started) / 1e6);
        }
        return new PreviewResult($view, $html, $validation, null, (hrtime(true) - $started) / 1e6);
    }
    //#endregion Preview

    //#region Pipeline

    /**
     * Every top-level render: output cache, sanitizer, events, test recorder and fallback.
     *
     * @param array<string, mixed> $data
     * @param Closure(): string $source the unrendered source (for the fallback and the cache key)
     * @param array{kind: string, template?: string, name?: string, view?: string, path?: string} $job
     */
    private function pipeline(string $view, array $data, Closure $source, array $job): string
    {
        if ($this->authorLocked()) {
            return $this->failed($view, AuthorLockedException::for((string) $this->author), $source);
        }

        $cache = $this->outputCache !== null ? $this->manager->outputCacheStore($this->outputCache['store']) : null;
        $key = $cache !== null ? $this->outputCacheKey($view, $data, $source) : null;

        if ($cache !== null && $key !== null) {
            $cached = $cache->get($key);
            if (is_string($cached)) {
                $this->manager->dispatch(new TemplateRendered($view, 0.0, strlen($cached), true));

                return $cached;
            }
        }
        $started = hrtime(true);
        try {
            $html = $this->sanitizeOutput($view, $this->isolation !== null ? $this->manager->isolatedRenderer()->render($this, $job, $data, $this->isolation) : $this->renderJob($job, $data));
        } catch (Throwable $exception) {
            return $this->failed($view, $exception, $source);
        }
        $this->manager->dispatch(new TemplateRendered($view, (hrtime(true) - $started) / 1e6, strlen($html)));
        if ($cache !== null && $key !== null) {
            $cache->put($key, $html, $this->outputCache['ttl'] ?? null);
        }
        return $html;
    }

    private function failed(string $view, Throwable $exception, Closure $source): string
    {
        if ($exception instanceof SecurityViolationException) {
            $this->manager->dispatch(new SecurityViolationDetected($view, $exception));
            $this->recordViolation();
        } elseif ($exception instanceof SandboxLimitExceededException) {
            $this->manager->dispatch(new SandboxLimitExceeded($view, $exception));
        }

        $fallback = $this->fallbackRenderer();
        $this->manager->dispatch(new TemplateRenderFailed($view, $exception, $fallback === null ? null : (is_string($this->fallback) ? $this->fallback : $fallback::class)));
        if ($fallback === null) {
            throw $exception;
        }
        $this->manager->reportFallback($exception);
        try {
            $template = $source();
        } catch (Throwable) {
            $template = '';
        }
        try {
            return $this->sanitizeOutput($view, BladeStripper::removeFrameworkAttributes($fallback->render($template, $view, $exception), $this->policy()->allowsAlpine()));
        } catch (Throwable) {
            return (new EscapedFallback())->render($template, $view, $exception);
        }
    }

    private function fallbackRenderer(): ?FallbackRenderer
    {
        if ($this->fallback === false) {
            return null;
        }
        return $this->fallback instanceof FallbackRenderer ? $this->fallback : $this->manager->fallback($this->fallback);
    }

    /**
     * @param array<string, mixed> $data
     * @param Closure(): string $source
     */
    private function outputCacheKey(string $view, array $data, Closure $source): ?string
    {
        $policy = $this->policy();
        $livewire = $policy->describe()['livewire'] ?? [];
        /** @noinspection NotOptimalIfConditionsInspection */
        if ($policy->allowsDirective('livewire') || (is_array($livewire) && ($livewire['components'] ?? []) !== [])) {
            return null;
        }

        try {
            $parts = [
                SandboxBladeCompiler::VERSION,
                $policy->fingerprint(),
                $view,
                hash('sha256', $source()),
                hash('sha256', serialize($data)),
                ($sanitizer = $this->sanitizerFor($view)) !== null ? $sanitizer::class : null,
                $this->manager->outputCacheVary($this, $view),
                $this->outputCache['key'] !== null ? ($this->outputCache['key'])($view, $data) : null,
            ];

            $container = $this->manager->container();
            /** @noinspection NotOptimalIfConditionsInspection */
            if (array_filter(DirectivePolicy::AUTH, $policy->allowsDirective(...)) !== [] && $container->bound('auth')) {
                $parts[] = ['user', $container->make('auth')->guard()->id()];
            }
            if ($container->bound('session') && ($policy->allowsDirective('csrf') || $policy->allowsDirective('error'))) {
                $session = $container->make('session')->driver();
                if ($policy->allowsDirective('error') && $session->has('errors')) {
                    return null;
                }
                if ($policy->allowsDirective('csrf')) {
                    $parts[] = ['csrf', hash('sha256', (string) $session->token())];
                }
            }
            return $this->manager->outputCachePrefix().hash('sha256', serialize($parts));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A regular Laravel View instance whose contents are rendered by this sandbox. Useful as the return value of a Livewire component's render() method or anywhere a View is expected.
     *
     * @param array<string, mixed> $data
     */
    public function view(string $view, array $data = []): ViewContract
    {
        $runtime = $this->runtime();
        (new ViewGuard($runtime->policy(), $runtime->audit()))->forTemplate($view)->assertView($view);
        $factory = $this->manager->viewFactory();
        if (!$factory instanceof Factory) {
            throw new ForbiddenViewException('A Laravel view factory is required to create sandboxed views.', 'view', $view);
        }
        return new View($factory, new SandboxViewEngine($this, $view), $view, $this->manager->sources()->path($view) ?? $view, $data);
    }

    public function runtime(): SandboxRuntime
    {
        return $this->manager->runtime($this->policy(), $this->debug, $this->limits, $this->integrity, $this->logger, $this->logLevel);
    }
    //#endregion Pipeline
}
