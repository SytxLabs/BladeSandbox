<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use JsonSerializable;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use ReflectionFunction;
use stdClass;
use Stringable;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Compiler\PhpAstValidator;
use SytxLabs\BladeSandbox\Compiler\SandboxRewriteVisitor;
use SytxLabs\BladeSandbox\Contracts\MarkdownConverter;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Guards\ArgumentGuard;
use SytxLabs\BladeSandbox\Guards\ComponentGuard;
use SytxLabs\BladeSandbox\Guards\DtoGuard;
use SytxLabs\BladeSandbox\Guards\FunctionGuard;
use SytxLabs\BladeSandbox\Guards\LivewireGuard;
use SytxLabs\BladeSandbox\Guards\MethodGuard;
use SytxLabs\BladeSandbox\Guards\ObjectGuard;
use SytxLabs\BladeSandbox\Guards\PropertyGuard;
use SytxLabs\BladeSandbox\Guards\ViewGuard;
use SytxLabs\BladeSandbox\Integrity\IntegrityManifest;
use SytxLabs\BladeSandbox\Learning\LearningLog;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Support\ClassMetadataCache;
use Throwable;
use UnitEnum;

/**
 * The only object compiled sandbox templates can talk to (as `$__sandbox`).
 *
 * Every public method listed in {@see self::API} is reachable from compiled code; everything a
 * template does with values (calls, property reads, offsets, iteration, string conversion, output)
 * is routed through here and checked against the policy.
 */
final class SandboxRuntime
{
    /** Methods compiled templates may call. Verified by the closed-world {@see PhpAstValidator}. */
    public const API = [
        // value operations (emitted by the expression rewriter)
        'fn', 'call', 'callNullsafe', 'staticCall', 'prop', 'propNullsafe', 'propQuiet', 'offset', 'offsetQuiet', 'iterate', 'spread', 'str', 'compare', 'toArray', 'destructure', 'constant', 'classConst',
        // output
        'escape', 'escapeText', 'escapeMarkup', 'raw', 'attributeEcho', 'tagNameEcho', 'attributeNameEcho', 'switchValue', 'componentAttribute', 'spreadAttributes', 'json',
        'classes', 'styles', 'directive', 'authCheck', 'can', 'canAny', 'csrfField', 'methodField', 'lang', 'choice', 'errorMessage', 'livewire',
        // loops and resource limits
        'tick', 'addLoop', 'incrementLoop', 'popLoop', 'currentLoop',
        // views and layouts
        'include', 'includeIf', 'includeWhen', 'includeUnless', 'includeFirst', 'each', 'renderLayout', 'startSection', 'stopSection', 'appendSection', 'yieldSection', 'yieldContent', 'parentPlaceholder',
        'hasSection', 'startPush', 'stopPush', 'startPrepend', 'stopPrepend', 'yieldPushContent', 'once',
        // markdown
        'markdown', 'startMarkdown', 'stopMarkdown',
        // components
        'startComponent', 'startViewComponent', 'renderComponent', 'startSlot', 'endSlot', 'props', 'aware',
    ];

    private ?LearningLog $learning = null;

    private readonly MethodGuard $methods;

    private readonly PropertyGuard $properties;

    private readonly FunctionGuard $functions;

    private readonly ObjectGuard $objects;

    private readonly ViewGuard $views;

    private readonly ComponentGuard $components;

    private readonly LivewireGuard $livewire;

    private readonly ArgumentGuard $arguments;

    private readonly string $parentPlaceholderSalt;

    public function __construct(private readonly SecurityPolicy $policy, private readonly SandboxContext $context, private readonly SandboxViewRenderer $renderer, private readonly ComponentRenderer $componentRenderer, private readonly AuditLogger $audit, private readonly LivewireAdapter $livewireAdapter, private readonly ?IntegrityManifest $integrity = null)
    {
        $dtos = new DtoGuard($policy, $audit);
        $this->arguments = new ArgumentGuard($policy, $audit);
        $this->methods = new MethodGuard($policy, $audit, $dtos, $this->arguments);
        $this->properties = new PropertyGuard($policy, $audit, $dtos);
        $this->functions = new FunctionGuard($policy, $audit, $this->arguments);
        $this->objects = new ObjectGuard($policy, $audit, $dtos);
        $this->views = new ViewGuard($policy, $audit);
        $this->components = new ComponentGuard($policy, $audit);
        $this->livewire = new LivewireGuard($policy, $audit, $livewireAdapter);
        $this->parentPlaceholderSalt = Str::random(8);
    }

    public function policy(): SecurityPolicy
    {
        return $this->policy;
    }

    public function context(): SandboxContext
    {
        return $this->context;
    }

    /** The integrity manifest view files must match, if the sandbox requires one. */
    public function integrity(): ?IntegrityManifest
    {
        return $this->integrity;
    }

    public function audit(): AuditLogger
    {
        return $this->audit;
    }

    public function renderer(): SandboxViewRenderer
    {
        return $this->renderer;
    }

    /**
     * Render an inline Blade string (e.g. returned by a component's render()) inside this sandbox.
     *
     * @param array<string, mixed> $data
     *
     * @throws Throwable
     */
    public function renderInline(string $template, array $data, string $name): string
    {
        return $this->renderer->renderString($template, $data, $this, $name);
    }

    /** Sets the template name used in audit entries of the guards. */
    public function using(string $template): void
    {
        foreach ([$this->methods, $this->properties, $this->functions, $this->objects, $this->views, $this->components, $this->livewire, $this->arguments] as $guard) {
            $guard->forTemplate($template);
        }
    }

    //#region Learning mode

    /**
     * Learning mode (Sandbox::learn()): denied operations are recorded instead of failing the render.
     * They are never executed; the template continues with a neutral value (null, '' or []).
     */
    public function learnInto(LearningLog $log): self
    {
        $this->learning = $log;
        $this->views->learnInto($log->record(...));

        return $this;
    }

    private function attempt(Closure $operation, mixed $neutral = null): mixed
    {
        if ($this->learning === null) {
            return $operation();
        }
        try {
            return $operation();
        } catch (SecurityViolationException $violation) {
            $this->learning->record($violation);
            return $neutral;
        }
    }
    //#endregion Learning mode

    //#region Value operations

    /**
     * @param array<int|string, mixed> $arguments
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function fn(string $function, array $arguments): mixed
    {
        return $this->attempt(function () use ($function, $arguments): mixed {
            $name = strtolower(ltrim($function, '\\'));

            if (in_array($name, SecurityPolicy::TRANSLATION_FUNCTIONS, true) && $this->policy->allowsFunction($name)) {
                return $this->translateFunction($name, $arguments);
            }

            if ($name === 'route' && $this->policy->allowsFunction('route')) {
                $route = $arguments['name'] ?? $arguments[0] ?? null;
                $this->functions->assertRoute(is_string($route) ? $route : ($route === null ? '' : $this->objects->toString($route)));
            }

            return $this->functions->call($function, $arguments);
        });
    }

    /**
     * __(), trans() and trans_choice() with a checked key; only string results are returned (a group
     * key would otherwise expose a whole translation file as an array), replacements are strings.
     *
     * @param array<int|string, mixed> $arguments
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function translateFunction(string $function, array $arguments): string
    {
        $key = $arguments['key'] ?? $arguments[0] ?? null;
        if ($key === null) {
            throw ForbiddenFunctionException::for($function.'()', 'a translation key is required');
        }
        $key = is_string($key) ? $key : $this->objects->toString($key);
        $this->functions->assertTranslation($key, $function.'()');

        $choice = $function === 'trans_choice';
        $number = $choice ? ($arguments['number'] ?? $arguments[1] ?? 0) : null;
        $replace = $arguments['replace'] ?? $arguments[$choice ? 2 : 1] ?? [];
        $locale = $arguments['locale'] ?? $arguments[$choice ? 3 : 2] ?? null;
        $replace = $this->scalarReplacements(is_array($replace) ? $replace : []);
        $locale = $locale === null ? null : $this->objects->toString($locale);

        if ($choice) {
            if (!is_int($number) && !is_float($number) && !is_countable($number)) {
                $number = (int) $this->objects->toString($number);
            }

            return app('translator')->choice($key, $number, $replace, $locale);
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        $line = app('translator')->get($key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }

    /** @param array<int|string, mixed> $arguments */
    public function call(mixed $object, string $method, array $arguments): mixed
    {
        return $this->attempt(fn () => $this->methods->call($object, $method, $arguments));
    }

    /** @param array<int|string, mixed> $arguments */
    public function staticCall(string $class, string $method, array $arguments): mixed
    {
        return $this->attempt(fn () => $this->methods->callStatic($class, $method, $arguments));
    }

    /** @param array<int|string, mixed> $arguments */
    public function callNullsafe(mixed $object, string $method, array $arguments): mixed
    {
        return $this->attempt(fn () => $object === null ? null : $this->methods->call($object, $method, $arguments));
    }

    public function prop(mixed $object, string $property): mixed
    {
        return $this->attempt(fn () => $this->properties->get($object, $property));
    }

    public function propNullsafe(mixed $object, string $property): mixed
    {
        return $this->attempt(fn () => $object === null ? null : $this->properties->get($object, $property));
    }

    public function propQuiet(mixed $object, string $property): mixed
    {
        return $this->attempt(fn () => $this->properties->get($object, $property, true));
    }

    public function offset(mixed $container, mixed $key): mixed
    {
        return $this->attempt(fn () => $this->objects->offset($container, $key));
    }

    public function offsetQuiet(mixed $container, mixed $key): mixed
    {
        return $this->attempt(fn () => $this->objects->offset($container, $key, true));
    }

    /** @return iterable<mixed, mixed> */
    public function iterate(mixed $value, bool $destructure = false): iterable
    {
        return $this->attempt(fn () => $this->objects->iterate($value, $destructure));
    }

    public function spread(mixed $value): array
    {
        return $this->attempt(fn () => $this->objects->spread($value, fn () => $this->context->tick()));
    }

    public function str(mixed $value): string
    {
        return $this->attempt(fn () => $this->objects->toString($value));
    }

    public function compare(string $operator, mixed $left, mixed $right): bool|int
    {
        return $this->attempt(fn () => $this->objects->compare($operator, $left, $right));
    }

    public function toArray(mixed $value): array
    {
        return $this->attempt(fn () => $this->objects->toArray($value));
    }

    public function destructure(mixed $value): array
    {
        return $this->attempt(fn () => $this->objects->destructure($value));
    }

    public function constant(string $name): mixed
    {
        return $this->attempt(fn () => $this->functions->constant($name));
    }

    public function classConst(string $class, string $name): mixed
    {
        return $this->attempt(fn () => $this->functions->classConstant($class, $name));
    }
    //#endregion Value operations

    //#region Output

    public function escape(mixed $value): string
    {
        return $this->attempt(fn () => $this->objects->escape($value));
    }

    /**
     * Echo inside an attribute value, comment or raw text element: never renders raw markup
     * (an Htmlable such as a slot is escaped as text there).
     */
    public function escapeText(mixed $value): string
    {
        return $this->attempt(fn () => $this->objects->escapeText($value));
    }

    /** Markup of a directive (@yield, @include, ...) used inside an attribute value, comment or raw text element. */
    public function escapeMarkup(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /** {!! !!}: only compiled in text context; the markup is validated like template markup. */
    public function raw(mixed $value): string
    {
        $html = $this->objects->raw($value);
        if (!($value instanceof Htmlable && $this->policy->allowsHtmlable($value))) {
            $this->livewire->checkHtml($html);
        }

        return $html;
    }

    /** An echo in attribute position (`<div {{ $attributes }}>`): the produced attributes are validated. */
    public function attributeEcho(mixed $value): string
    {
        return $this->attempt(function () use ($value): string {
            if ($value instanceof ComponentAttributeBag) {
                foreach ($value->getAttributes() as $name => $attribute) {
                    $this->livewire->checkAttribute((string) $name, is_scalar($attribute) ? (string) $attribute : null, !is_scalar($attribute) && $attribute !== null);
                }

                return $this->objects->attributeBag($value);
            }
            $html = $this->objects->escapeText($value);
            $this->livewire->checkAttributeString($html);

            return $html;
        });
    }

    /** An echo inside a tag name (`<h{{ $level }}>`): must be a plain name. */
    public function tagNameEcho(mixed $value): string
    {
        $name = $this->objects->toString($value);
        if (preg_match('/\A[A-Za-z0-9\-]*\z/', $name) !== 1) {
            throw new SecurityViolationException('Dynamic tag names may only contain letters, digits and dashes.', 'tag', 'dynamic tag name');
        }
        $this->livewire->checkTag($name);

        return $name;
    }

    /** An echo inside an attribute name (`data-{{ $key }}`): must be a plain name. */
    public function attributeNameEcho(mixed $value): string
    {
        $name = $this->objects->toString($value);
        if (preg_match('/\A[A-Za-z0-9_\-.]*\z/', $name) !== 1) {
            throw new SecurityViolationException('Dynamic attribute names may only contain letters, digits, "_", "-" and ".".', 'attribute', 'dynamic attribute name');
        }

        return $name;
    }

    /** @switch / @case values are compared loosely by PHP; objects must not be converted implicitly. */
    public function switchValue(mixed $value): mixed
    {
        $this->objects->assertLooselyComparable($value);

        return $value;
    }

    /** A `{{ }}` inside a static component attribute value (escaped like Blade's sanitizeComponentAttribute()). */
    public function componentAttribute(mixed $value): mixed
    {
        return (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable) ? htmlspecialchars($this->objects->toString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) : $value;
    }

    public function spreadAttributes(mixed $value): array
    {
        $escape = true;
        if ($value instanceof ComponentAttributeBag) {
            $value = $value->getAttributes();
            $escape = false;
        }

        if (!is_array($value)) {
            throw new SandboxException('Only attribute bags and arrays can be passed as component attributes.');
        }

        $attributes = [];
        foreach ($value as $name => $attribute) {
            $name = (string) $name;
            $this->livewire->checkAttribute($name, is_scalar($attribute) ? (string) $attribute : null, !is_scalar($attribute) && $attribute !== null, true);
            $attributes[$name] = $escape ? $this->componentAttribute($attribute) : $attribute;
        }

        return $attributes;
    }

    public function json(mixed $value, int $flags = 0, int $depth = 512): string
    {
        $this->assertJsonEncodable($value, 0);

        /** @noinspection PhpUnhandledExceptionInspection */
        return (string) json_encode($value, $flags | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR, max(1, min($depth, 512)));
    }

    private function assertJsonEncodable(mixed $value, int $depth): void
    {
        if ($depth > 64) {
            throw new SandboxException('Value is nested too deeply for @json.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertJsonEncodable($item, $depth + 1);
            }

            return;
        }
        if (!is_object($value) || $value instanceof UnitEnum || $value instanceof DateTimeInterface) {
            return;
        }
        if ($value instanceof stdClass || ($this->policy->allowsDto($value) && !$value instanceof JsonSerializable)) {
            foreach (get_object_vars($value) as $item) {
                $this->assertJsonEncodable($item, $depth + 1);
            }

            return;
        }

        $violation = ForbiddenMethodException::for($value::class.'::jsonSerialize()', 'JSON encoding');
        $this->audit->record($violation, $this->context->currentTemplate());
        throw $violation;
    }

    /** @param array<int|string, mixed>|string $classes */
    public function classes(array|string $classes): string
    {
        $result = [];
        foreach ((array) $classes as $class => $constraint) {
            if (is_int($class)) {
                $result[] = $this->objects->toString($constraint);
            } elseif ($constraint) {
                $result[] = $class;
            }
        }

        return htmlspecialchars(implode(' ', $result), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    public function styles(array|string $styles): string
    {
        $result = [];
        foreach ((array) $styles as $style => $constraint) {
            if (is_int($style)) {
                $result[] = rtrim($this->objects->toString($constraint), ';').';';
            } elseif ($constraint) {
                $result[] = rtrim($style, ';').';';
            }
        }

        return htmlspecialchars(implode(' ', $result), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    /** @param array<int, mixed> $arguments */
    public function directive(string $name, array $arguments): string
    {
        $handler = $this->policy->directives()->handler($name);
        if ($handler === null) {
            throw new ForbiddenDirectiveException('Directive @'.$name.' is not allowed in the sandbox.', 'directive', '@'.$name);
        }

        try {
            $this->arguments->check('@'.$name, $arguments, ClassMetadataCache::describe((new ReflectionFunction($handler))->getParameters()));
        } catch (ReflectionException $e) {
            throw new SandboxException('Failed to reflect directive handler for @'.$name.': '.$e->getMessage(), 0, $e);
        }
        $result = $handler(...$arguments);
        if ($result instanceof Htmlable) {
            return $result->toHtml();
        }

        return htmlspecialchars($this->objects->toString($result), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    /** @auth / @guest: whether the current user is authenticated (on the given guard). The user object is never exposed. */
    public function authCheck(mixed $guard = null): bool
    {
        if ($guard !== null && (!is_string($guard) || preg_match('/\A[A-Za-z0-9_\-.]+\z/', $guard) !== 1)) {
            throw new SandboxException('Invalid auth guard name.');
        }

        return (bool) app('auth')->guard($guard)->check();
    }

    /** @can / @cannot: Gate check for the current user. Arguments are passed to your policies / gates. */
    public function can(mixed $ability, mixed $arguments = []): bool
    {
        $this->arguments->check('@can', [$arguments], null);

        return app(Gate::class)->allows(self::ability($ability), $arguments);
    }

    /** @canany: Gate::any(). */
    public function canAny(mixed $abilities, mixed $arguments = []): bool
    {
        if (!is_array($abilities)) {
            throw new SandboxException('@canany expects an array of abilities.');
        }
        $this->arguments->check('@canany', [$arguments], null);

        return app(Gate::class)->any(array_map(self::ability(...), array_values($abilities)), $arguments);
    }

    private static function ability(mixed $ability): string
    {
        if ($ability instanceof BackedEnum) {
            $ability = (string) $ability->value;
        }
        if (!is_string($ability) || preg_match('/\A[A-Za-z0-9_\-.:]+\z/', $ability) !== 1) {
            throw new SandboxException('Abilities must be plain strings.');
        }

        return $ability;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="'.htmlspecialchars((string) csrf_token(), ENT_QUOTES, 'UTF-8').'" autocomplete="off">';
    }

    public function methodField(mixed $method): string
    {
        if (!is_string($method) || preg_match('/\A[A-Za-z]{1,16}\z/', $method) !== 1) {
            throw new SandboxException('Invalid form method.');
        }

        return '<input type="hidden" name="_method" value="'.strtoupper($method).'">';
    }

    /** @param array<string, mixed> $replace */
    public function lang(mixed $key, array $replace = [], ?string $locale = null): string
    {
        $key = $this->objects->toString($key);
        $this->functions->assertTranslation($key, '@lang');
        /** @noinspection PhpUnhandledExceptionInspection */
        $line = app('translator')->get($key, $this->scalarReplacements($replace), $locale);

        return is_string($line) ? htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) : '';
    }

    /** @param array<string, mixed> $replace */
    public function choice(mixed $key, mixed $number, array $replace = [], ?string $locale = null): string
    {
        if (!is_int($number) && !is_float($number) && !is_countable($number)) {
            $number = (int) $this->objects->toString($number);
        }

        $key = $this->objects->toString($key);
        $this->functions->assertTranslation($key, '@choice');

        return htmlspecialchars(app('translator')->choice($key, $number, $this->scalarReplacements($replace), $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    /**
     * @param array<string, mixed> $replace
     *
     * @return array<string, string>
     */
    private function scalarReplacements(array $replace): array
    {
        return array_map(fn (mixed $value): string => $this->objects->toString($value), $replace);
    }

    /** @param array<string, mixed> $scope */
    public function errorMessage(array $scope, mixed $field, string $bag = 'default'): ?string
    {
        $errors = $scope['errors'] ?? null;
        if ($errors instanceof ViewErrorBag) {
            $errors = $errors->getBag($bag);
        }
        if (!$errors instanceof MessageBag) {
            return null;
        }

        $message = $errors->first($this->objects->toString($field));

        return $message === '' ? null : $message;
    }

    /** @param array<string, mixed> $parameters */
    public function livewire(mixed $component, array $parameters = []): string
    {
        if (!is_string($component)) {
            throw new SandboxException('Livewire component names must be strings.');
        }

        $this->livewire->assertComponent($component);
        $this->spreadAttributes($parameters);

        return $this->livewireAdapter->mount($component, $parameters);
    }
    //#endregion Output

    //#region Loops

    /**
     * @param iterable<mixed, mixed> $data
     *
     * @return iterable<mixed, mixed>
     */
    public function addLoop(iterable $data): iterable
    {
        return $this->context->addLoop($data);
    }

    public function tick(): void
    {
        $this->context->tick();
    }

    public function incrementLoop(): void
    {
        $this->context->incrementLoop();
    }

    public function popLoop(): void
    {
        $this->context->popLoop();
    }

    public function currentLoop(): ?LoopState
    {
        return $this->context->currentLoop();
    }
    //#endregion Loops

    //#region Views

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $data
     */
    public function include(mixed $view, array $scope = [], array $data = []): string
    {
        return $this->attempt(fn () => $this->renderer->renderView($this->views->assertView($view), array_merge(self::scope($scope), $data), $this), '');
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $data
     */
    public function includeIf(mixed $view, array $scope = [], array $data = []): string
    {
        return $this->attempt(function () use ($view, $scope, $data): string {
            $name = $this->views->assertView($view);

            return $this->renderer->exists($name) ? $this->renderer->renderView($name, array_merge(self::scope($scope), $data), $this) : '';
        }, '');
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $data
     */
    public function includeWhen(mixed $condition, mixed $view, array $scope = [], array $data = []): string
    {
        return $this->attempt(fn () => $condition ? $this->include($view, $scope, $data) : '', '');
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $data
     */
    public function includeUnless(mixed $condition, mixed $view, array $scope = [], array $data = []): string
    {
        return $this->attempt(fn () => $condition ? '' : $this->include($view, $scope, $data), '');
    }

    /**
     * @param array<int, mixed> $views
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $data
     *
     * @throws Throwable
     */
    public function includeFirst(array $views, array $scope = [], array $data = []): string
    {
        return $this->attempt(function () use ($views, $scope, $data): string {
            foreach ($views as $view) {
                $name = $this->views->assertView($view);
                if ($this->renderer->exists($name)) {
                    return $this->renderer->renderView($name, array_merge(self::scope($scope), $data), $this);
                }
            }

            throw new SandboxException('None of the views passed to @includeFirst exist.');
        }, '');
    }

    public function each(mixed $view, mixed $data, mixed $iterator, mixed $empty = 'raw|'): string
    {
        return $this->attempt(function () use ($view, $data, $iterator, $empty): string {
            $name = $this->views->assertView($view);
            $variable = $this->objects->toString($iterator);
            if (preg_match('/\A[A-Za-z_]\w*\z/', $variable) !== 1 || SandboxRewriteVisitor::isReservedVariable($variable)) {
                throw new SandboxException('Invalid @each variable name.');
            }

            $result = '';
            $count = 0;
            foreach ($this->iterate($data) as $key => $value) {
                $this->context->tick();
                $result .= $this->renderer->renderView($name, ['key' => $key, $variable => $value], $this);
                $count++;
            }

            if ($count === 0) {
                $empty = $this->objects->toString($empty);
                $result = str_starts_with($empty, 'raw|') ? htmlspecialchars(substr($empty, 4), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) : $this->renderer->renderView($this->views->assertView($empty), [], $this);
            }

            return $result;
        }, '');
    }

    /** @param array<string, mixed> $scope */
    public function renderLayout(mixed $view, array $scope = []): string
    {
        return $this->renderer->renderView($this->views->assertView($view), self::scope($scope), $this);
    }
    //#endregion Views

    //#region Sections / stacks

    public function startSection(mixed $section, mixed $content = null): void
    {
        $section = self::name($section);
        if ($content === null) {
            if (ob_start()) {
                $this->context->sectionStack[] = $section;
            }

            return;
        }
        $this->extendSection($section, $this->escape($content));
    }

    public function stopSection(bool $overwrite = false): string
    {
        $last = array_pop($this->context->sectionStack);
        if ($last === null) {
            throw new SandboxException('Cannot end a section without first starting one.');
        }
        $content = (string) ob_get_clean();
        if ($overwrite) {
            $this->context->sections[$last] = $content;
        } else {
            $this->extendSection($last, $content);
        }

        return $last;
    }

    public function appendSection(): string
    {
        $last = array_pop($this->context->sectionStack);
        if ($last === null) {
            throw new SandboxException('Cannot end a section without first starting one.');
        }
        $this->context->sections[$last] = ($this->context->sections[$last] ?? '').ob_get_clean();

        return $last;
    }

    public function yieldSection(): string
    {
        return $this->context->sectionStack === [] ? '' : $this->yieldContent($this->stopSection());
    }

    public function yieldContent(mixed $section, mixed $default = ''): string
    {
        $section = self::name($section);

        return str_replace($this->placeholder($section), '', $this->context->sections[$section] ?? ($default === '' ? '' : $this->escape($default)));
    }

    public function parentPlaceholder(): string
    {
        return $this->placeholder($this->context->sectionStack[count($this->context->sectionStack) - 1] ?? '');
    }

    public function hasSection(mixed $section): bool
    {
        return array_key_exists(self::name($section), $this->context->sections);
    }

    private function extendSection(string $section, string $content): void
    {
        $this->context->sections[$section] = isset($this->context->sections[$section]) ? str_replace($this->placeholder($section), $content, $this->context->sections[$section]) : $content;
    }

    private function placeholder(string $section): string
    {
        return '##parent-placeholder-'.hash('xxh128', $this->parentPlaceholderSalt.$section).'##';
    }

    public function startPush(mixed $stack, mixed $content = ''): void
    {
        $stack = self::name($stack);
        if ($content === '' || $content === null) {
            if (ob_start()) {
                $this->context->pushStack[] = $stack;
            }

            return;
        }
        $this->context->pushes[$stack][] = $this->escape($content);
    }

    public function markdown(mixed $value): string
    {
        return $value === null ? '' : self::markdownConverter()->convert($this->objects->toString($value));
    }

    public function startMarkdown(): void
    {
        if (ob_start()) {
            $this->context->markdownDepth++;
        }
    }

    public function stopMarkdown(): string
    {
        if ($this->context->markdownDepth === 0) {
            throw new SandboxException('Cannot end a markdown block without first starting one.');
        }
        $this->context->markdownDepth--;

        return self::markdownConverter()->convert((string) ob_get_clean());
    }

    private static function markdownConverter(): MarkdownConverter
    {
        return app(SandboxManager::class)->markdownConverter();
    }

    public function stopPush(): void
    {
        $last = array_pop($this->context->pushStack);
        if ($last === null) {
            throw new SandboxException('Cannot end a push stack without first starting one.');
        }
        $this->context->pushes[$last][] = (string) ob_get_clean();
    }

    public function startPrepend(mixed $stack, mixed $content = ''): void
    {
        $stack = self::name($stack);
        if ($content === '' || $content === null) {
            if (ob_start()) {
                $this->context->pushStack[] = '<prepend>'.$stack;
            }

            return;
        }
        $this->context->prepends[$stack] ??= [];
        array_unshift($this->context->prepends[$stack], $this->escape($content));
    }

    public function stopPrepend(): void
    {
        $last = array_pop($this->context->pushStack);
        if ($last === null || !str_starts_with($last, '<prepend>')) {
            throw new SandboxException('Cannot end a prepend operation without first starting one.');
        }

        $stack = substr($last, strlen('<prepend>'));
        $this->context->prepends[$stack] ??= [];
        array_unshift($this->context->prepends[$stack], (string) ob_get_clean());
    }

    public function yieldPushContent(mixed $stack, mixed $default = ''): string
    {
        $stack = self::name($stack);
        if (!isset($this->context->pushes[$stack]) && !isset($this->context->prepends[$stack])) {
            return $default === '' ? '' : $this->escape($default);
        }

        return implode('', $this->context->prepends[$stack] ?? []).implode('', $this->context->pushes[$stack] ?? []);
    }

    public function once(string $id): bool
    {
        if (isset($this->context->once[$id])) {
            return false;
        }
        $this->context->once[$id] = true;

        return true;
    }
    //#endregion Sections / stacks

    //#region Components

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $bound
     */
    public function startComponent(mixed $component, array $attributes, array $bound = []): void
    {
        $this->context->components[] = new ComponentFrame('tag', $this->components->assertComponent($component), $attributes, $this->sanitizeBound($attributes, $bound));
        ob_start();
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $bound
     *
     * @return array<string, mixed>
     */
    private function sanitizeBound(array $attributes, array $bound): array
    {
        foreach ($bound as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->componentAttribute($attributes[$key]);
            }
        }

        return $attributes;
    }

    public function startViewComponent(mixed $view, array $data = []): void
    {
        $this->context->components[] = new ComponentFrame('view', $this->views->assertView($view), $data);
        ob_start();
    }

    public function renderComponent(): string
    {
        $frame = array_pop($this->context->components);
        if ($frame === null) {
            throw new SandboxException('Cannot render a component that was not started.');
        }

        return $this->componentRenderer->render($frame, new ComponentSlot(trim((string) ob_get_clean())), $this);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $bound
     */
    public function startSlot(mixed $name, array $attributes = [], array $bound = []): void
    {
        $frame = $this->context->components[count($this->context->components) - 1] ?? null;
        if ($frame === null) {
            throw new SandboxException('Slots can only be used inside components.');
        }

        $name = self::name($name);
        if (preg_match('/\A[A-Za-z_][\w\-]*\z/', $name) !== 1 || SandboxRewriteVisitor::isReservedVariable($name) || $name === 'slot' || $name === 'attributes') {
            throw new SandboxException('Invalid slot name.');
        }

        $frame->slotStack[] = ['name' => $name, 'attributes' => $this->sanitizeBound($attributes, $bound)];
        ob_start();
    }

    public function endSlot(): void
    {
        $frame = $this->context->components[count($this->context->components) - 1] ?? null;
        $slot = $frame === null ? null : array_pop($frame->slotStack);
        if ($frame === null || $slot === null) {
            throw new SandboxException('Cannot end a slot that was not started.');
        }
        $frame->slots[self::camel($slot['name'])] = new ComponentSlot(trim((string) ob_get_clean()), $slot['attributes']);
    }

    /**
     * @param array<int|string, mixed> $definitions
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function props(array $definitions, array $scope): array
    {
        $attributes = $scope['attributes'] ?? new ComponentAttributeBag();
        if (!$attributes instanceof ComponentAttributeBag) {
            $attributes = new ComponentAttributeBag();
        }

        $variables = [];
        $consumed = [];
        foreach ($definitions as $key => $default) {
            $name = is_int($key) ? (string) $default : $key;
            $variable = self::camel($name);
            self::assertVariableName($variable);

            $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
            $inBag = $attributes->has($name) ? $name : ($attributes->has($kebab) ? $kebab : null);
            if ($inBag !== null) {
                $variables[$variable] = array_key_exists($variable, $scope) ? $scope[$variable] : $attributes->get($inBag);
                $consumed[] = $inBag;
            } elseif (array_key_exists($variable, $scope) && !($scope[$variable] instanceof ComponentAttributeBag)) {
                $variables[$variable] = $scope[$variable];
            } elseif (!is_int($key)) {
                $variables[$variable] = $default;
            }
            $consumed[] = $variable;
        }

        $variables['attributes'] = $attributes->except($consumed);

        return $variables;
    }

    /**
     * @param array<int|string, mixed> $definitions
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function aware(array $definitions, array $scope): array
    {
        $variables = [];
        foreach ($definitions as $key => $default) {
            $variable = self::camel(is_int($key) ? (string) $default : $key);
            self::assertVariableName($variable);

            $found = false;
            for ($i = count($this->context->componentData) - 2; $i >= 0; $i--) {
                if (array_key_exists($variable, $this->context->componentData[$i])) {
                    $variables[$variable] = $this->context->componentData[$i][$variable];
                    $found = true;
                    break;
                }
            }
            if (!$found && !array_key_exists($variable, $scope) && !is_int($key)) {
                $variables[$variable] = $default;
            }
        }

        return $variables;
    }
    //#endregion Components

    //#region Helpers

    /**
     * Variables of the including template without the sandbox internals.
     *
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public static function scope(array $scope): array
    {
        foreach (array_keys($scope) as $key) {
            if (!is_string($key) || SandboxRewriteVisitor::isReservedVariable($key)) {
                unset($scope[$key]);
            }
        }

        return $scope;
    }

    private static function name(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new SandboxException('Section, stack and slot names must be strings.');
        }

        return (string) $value;
    }

    public static function camel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))));
    }

    private static function assertVariableName(string $variable): void
    {
        if (preg_match('/\A[A-Za-z_]\w*\z/', $variable) !== 1 || SandboxRewriteVisitor::isReservedVariable($variable) || $variable === 'attributes') {
            throw new SandboxException('Invalid component property name.');
        }
    }
    //#endregion Helpers
}
