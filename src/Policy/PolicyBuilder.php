<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use Closure;
use InvalidArgumentException;

/**
 * Mutable, fluent builder for {@see SecurityPolicy}.
 */
final class PolicyBuilder
{
    /** Flags that restrict: when merging policies, false (restricted) wins. */
    private const RESTRICTIVE_FLAGS = ['nativeDtoConversion', 'translationsEnabled'];

    /** @var array<string, array<string, true>> */
    private array $methods = [];

    /** @var array<string, array<string, true>> */
    private array $properties = [];

    /** @var list<string> */
    private array $arrayAccess = [];

    /** @var list<string> */
    private array $iteration = [];

    /** @var list<string> */
    private array $stringConversion = [];

    /** @var list<string> */
    private array $htmlable = [];

    /** @var list<string> */
    private array $dtoClasses = [];

    /** @var list<string> */
    private array $dtoNamespaces = [];

    private bool $nativeDtoConversion = true;

    /** @var list<string> */
    private array $views = [];

    /** @var list<string> */
    private array $viewNamespaces = [];

    /** @var list<string> */
    private array $components = [];

    /** @var array<string, Closure> */
    private array $componentFactories = [];

    /** @var array<string, true> */
    private array $directives;

    /** @var array<string, Closure> */
    private array $directiveHandlers = [];

    /** @var array<string, true> */
    private array $functions = [];

    /** @var array<string, true> */
    private array $constants = [];

    /** @var array<string, array<string, true>> */
    private array $classConstants = [];

    /** @var array<string, array<string, true>> */
    private array $staticMethods = [];

    /** @var array<string, array<string, true>> */
    private array $macros = [];

    /** @var list<string> */
    private array $denyClasses = [];

    /** @var list<string> */
    private array $denyNamespaces = [];

    /** @var array<string, array<string, true>> */
    private array $denyMethods = [];

    /** @var array<string, array<string, true>> */
    private array $denyProperties = [];

    /** @var array<string, array<string, true>> */
    private array $denyClassConstants = [];

    /** @var array<string, array<string, true>> */
    private array $denyStaticMethods = [];

    /** @var array<string, true> */
    private array $denyFunctions = [];

    /** @var array<string, true> */
    private array $denyConstants = [];

    /** @var list<string> */
    private array $denyViews = [];

    /** @var list<string> */
    private array $classNamespaces = [];

    /** @var array<string, true> */
    private array $livewireDirectives = [];

    /** @var array<string, true> */
    private array $livewireActions = [];

    /** @var array<string, true> */
    private array $livewireModels = [];

    /** @var array<string, true> */
    private array $livewireEvents = [];

    /** @var list<string> */
    private array $livewireComponents = [];

    private bool $alpine = false;

    private bool $rawEcho = false;

    private bool $translationsEnabled = true;

    /** @var list<string> */
    private array $translationPatterns = [];

    /** @var list<string> */
    private array $routePatterns = [];

    /** @var list<string> */
    private array $denyRoutes = [];

    /** @var list<string> */
    private array $denyTranslations = [];

    /** @var array<string, true> directives denied explicitly (they stay denied when policies are merged) */
    private array $deniedDirectives = [];

    private ?SecurityPolicy $built = null;

    public function __construct()
    {
        $this->directives = array_fill_keys(DirectivePolicy::DEFAULTS, true);
    }

    /** @return array<string, mixed> the built policy snapshot is not serialized (it is rebuilt on demand) */
    public function __serialize(): array
    {
        $state = get_object_vars($this);
        unset($state['built']);

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function __unserialize(array $state): void
    {
        foreach ($state as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
        $this->built = null;
    }

    /** @param list<string>|string $methods */
    public function allowMethod(string $class, array|string $methods): self
    {
        $class = self::className($class);
        foreach ((array) $methods as $method) {
            if (str_starts_with($method, '__')) {
                throw new InvalidArgumentException('Magic methods cannot be allowed as direct method calls.');
            }
            $this->methods[$class][strtolower($method)] = true;
        }

        return $this->changed();
    }

    /**
     * Allow macros registered on a Macroable class (e.g. Collection::macro(), Str::macro()), for instance
     * and static calls. "*" allows every macro registered on the class (now or later).
     *
     * @param list<string>|string $macros
     */
    public function allowMacro(string $class, array|string $macros = '*'): self
    {
        $class = self::className($class);
        foreach ((array) $macros as $macro) {
            if ($macro === '' || str_starts_with($macro, '__')) {
                throw new InvalidArgumentException('Invalid macro name "'.$macro.'".');
            }
            $this->macros[$class][strtolower($macro)] = true;
        }

        return $this->changed();
    }

    /** @param list<string>|string $properties */
    public function allowProperty(string $class, array|string $properties): self
    {
        $class = self::className($class);
        foreach ((array) $properties as $property) {
            $this->properties[$class][$property] = true;
        }

        return $this->changed();
    }

    public function allowArrayAccess(string $class): self
    {
        $this->arrayAccess[] = self::className($class);

        return $this->changed();
    }

    public function allowIteration(string $class): self
    {
        $this->iteration[] = self::className($class);

        return $this->changed();
    }

    public function allowStringConversion(string $class): self
    {
        $this->stringConversion[] = self::className($class);

        return $this->changed();
    }

    public function allowHtmlable(string $class): self
    {
        $this->htmlable[] = self::className($class);

        return $this->changed();
    }

    public function allowDto(string $class): self
    {
        $this->dtoClasses[] = self::className($class);

        return $this->changed();
    }

    public function allowDtoNamespace(string $namespace): self
    {
        $namespace = strtolower(trim($namespace, '\\'));
        if ($namespace === '') {
            throw new InvalidArgumentException('The global namespace cannot be allowed as DTO namespace.');
        }
        $this->dtoNamespaces[] = $namespace.'\\';

        return $this->changed();
    }

    public function nativeDtoConversion(bool $enabled): self
    {
        $this->nativeDtoConversion = $enabled;

        return $this->changed();
    }

    public function allowView(string $pattern): self
    {
        $this->views[] = $pattern;

        return $this->changed();
    }

    public function allowViewNamespace(string $namespace): self
    {
        $this->viewNamespaces[] = $namespace;

        return $this->changed();
    }

    public function allowComponent(string $pattern, ?Closure $factory = null): self
    {
        $this->components[] = $pattern;
        if ($factory !== null) {
            $this->componentFactories[$pattern] = $factory;
        }

        return $this->changed();
    }

    public function allowDirective(string $directive): self
    {
        $directive = strtolower(ltrim($directive, '@'));
        if (in_array($directive, DirectivePolicy::NEVER, true)) {
            throw new InvalidArgumentException("The @{$directive} directive can never be enabled inside a sandbox.");
        }
        if (!in_array($directive, DirectivePolicy::DEFAULTS, true) && !in_array($directive, DirectivePolicy::OPT_IN, true)) {
            throw new InvalidArgumentException("The @{$directive} directive is not a sandbox-supported built-in. Register a sandbox-safe handler with directive() instead.");
        }
        $this->directives[$directive] = true;
        unset($this->deniedDirectives[$directive]);
        foreach (DirectivePolicy::COMPANIONS[$directive] ?? [] as $companion) {
            $this->directives[$companion] = true;
            unset($this->deniedDirectives[$companion]);
        }

        return $this->changed();
    }

    public function denyDirective(string $directive): self
    {
        $directive = strtolower(ltrim($directive, '@'));
        unset($this->directives[$directive]);
        $this->deniedDirectives[$directive] = true;

        return $this->changed();
    }

    public function directive(string $name, Closure $handler): self
    {
        $name = strtolower(ltrim($name, '@'));
        if (DirectivePolicy::isKnownBuiltIn($name) || preg_match('/\A[a-z_][a-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidArgumentException("Cannot register sandbox directive @{$name}.");
        }
        $this->directiveHandlers[$name] = $handler;

        return $this->changed();
    }

    public function allowFunction(string $function): self
    {
        $this->functions[strtolower(ltrim($function, '\\'))] = true;

        return $this->changed();
    }

    public function allowConstant(string $constant): self
    {
        $this->constants[ltrim($constant, '\\')] = true;

        return $this->changed();
    }

    /** @param list<string>|string $constants */
    public function allowClassConstant(string $class, array|string $constants = '*'): self
    {
        $class = self::className($class);
        foreach ((array) $constants as $constant) {
            $this->classConstants[$class][$constant] = true;
        }

        return $this->changed();
    }

    /** @param list<string>|string $methods */
    public function allowStaticMethod(string $class, array|string $methods): self
    {
        $class = self::className($class);
        foreach ((array) $methods as $method) {
            if (str_starts_with($method, '__')) {
                throw new InvalidArgumentException('Magic methods cannot be allowed as static calls.');
            }
            $this->staticMethods[$class][strtolower($method)] = true;
        }

        return $this->changed();
    }

    /**
     * Allow the public API of every class in a PHP namespace and its sub-namespaces
     * (e.g. "App\\Options" or "App/Options" for the application's enums).
     */
    public function allowClassNamespace(string $namespace): self
    {
        $normalized = ClassNamespacePolicy::normalize($namespace);
        if ($normalized === '') {
            throw new InvalidArgumentException('The global namespace cannot be allowed as class namespace.');
        }
        $this->classNamespaces[] = $normalized;

        return $this->changed();
    }

    public function allowLivewireDirective(string $directive): self
    {
        $this->livewireDirectives[strtolower(preg_replace('/\A(wire:)/i', '', $directive) ?? $directive)] = true;

        return $this->changed();
    }

    public function allowLivewireAction(string $action): self
    {
        $this->livewireActions[$action] = true;

        return $this->changed();
    }

    public function allowLivewireModel(string $property): self
    {
        $this->livewireModels[$property] = true;

        return $this->changed();
    }

    public function allowLivewireEvent(string $event): self
    {
        $this->livewireEvents[$event] = true;

        return $this->changed();
    }

    public function allowLivewireComponent(string $component): self
    {
        $this->livewireComponents[] = $component;

        return $this->changed();
    }

    public function allowAlpine(bool $allow = true): self
    {
        $this->alpine = $allow;

        return $this->changed();
    }

    public function allowRawEcho(bool $allow = true): self
    {
        $this->rawEcho = $allow;

        return $this->changed();
    }

    //#region Deny rules (always win)

    public function denyClass(string $class): self
    {
        $this->denyClasses[] = self::className($class);

        return $this->changed();
    }

    public function denyClassNamespace(string $namespace): self
    {
        $normalized = ClassNamespacePolicy::normalize($namespace);
        if ($normalized === '') {
            throw new InvalidArgumentException('The global namespace cannot be denied as class namespace; simply do not allow it.');
        }
        $this->denyNamespaces[] = $normalized;

        return $this->changed();
    }

    /** @param list<string>|string $methods */
    public function denyMethod(string $class, array|string $methods): self
    {
        foreach ((array) $methods as $method) {
            $this->denyMethods[self::className($class)][strtolower($method)] = true;
        }

        return $this->changed();
    }

    /** @param list<string>|string $properties */
    public function denyProperty(string $class, array|string $properties): self
    {
        foreach ((array) $properties as $property) {
            $this->denyProperties[self::className($class)][$property] = true;
        }

        return $this->changed();
    }

    /** @param list<string>|string $constants */
    public function denyClassConstant(string $class, array|string $constants): self
    {
        foreach ((array) $constants as $constant) {
            $this->denyClassConstants[self::className($class)][$constant] = true;
        }

        return $this->changed();
    }

    /** @param list<string>|string $methods */
    public function denyStaticMethod(string $class, array|string $methods): self
    {
        foreach ((array) $methods as $method) {
            $this->denyStaticMethods[self::className($class)][strtolower($method)] = true;
        }

        return $this->changed();
    }

    public function denyFunction(string $function): self
    {
        $this->denyFunctions[strtolower(ltrim($function, '\\'))] = true;

        return $this->changed();
    }

    public function denyConstant(string $constant): self
    {
        $this->denyConstants[ltrim($constant, '\\')] = true;

        return $this->changed();
    }

    public function denyView(string $pattern): self
    {
        $this->denyViews[] = $pattern;

        return $this->changed();
    }

    /**
     * Adds every rule of $other to this builder (policy inheritance). Allow and deny collections are
     * united, handlers / factories of this builder win over $other's, denied directives stay denied,
     * permissions (raw echo, Alpine) are granted if either side grants them, and restrictions
     * (native DTO conversion off, translations off) apply if either side sets them.
     */
    public function merge(self $other): self
    {
        foreach (get_object_vars($other) as $property => $value) {
            if ($property === 'built' || $property === 'directives' || $property === 'deniedDirectives') {
                continue;
            }

            $current = $this->{$property};
            if (is_bool($value) && is_bool($current)) {
                $this->{$property} = in_array($property, self::RESTRICTIVE_FLAGS, true) ? ($current && $value) : ($current || $value);
            } elseif (is_array($value) && is_array($current)) {
                $this->{$property} = self::mergeRules($current, $value);
            }
        }

        $this->deniedDirectives += $other->deniedDirectives;
        $this->directives = array_diff_key($this->directives + $other->directives, $this->deniedDirectives);

        return $this->changed();
    }

    /**
     * @param array<array-key, mixed> $current
     * @param array<array-key, mixed> $other
     *
     * @return array<array-key, mixed>
     */
    private static function mergeRules(array $current, array $other): array
    {
        if ($current === []) {
            return $other;
        }
        if ($other === []) {
            return $current;
        }
        if (array_is_list($current) && array_is_list($other)) {
            return array_values(array_unique([...$current, ...$other], SORT_REGULAR));
        }

        $merged = $current;
        foreach ($other as $key => $value) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            } elseif (is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = self::mergeRules($merged[$key], $value);
            }
        }

        return $merged;
    }
    //#endregion Deny rules (always win)

    //#region Translations & routes

    /** Enable translations (on by default); with patterns only matching keys ("cms.*", "validation.*"). */
    public function allowTranslations(string ...$patterns): self
    {
        $this->translationsEnabled = true;
        array_push($this->translationPatterns, ...$patterns);

        return $this->changed();
    }

    public function withoutTranslations(): self
    {
        $this->translationsEnabled = false;

        return $this->changed();
    }

    public function denyTranslation(string $pattern): self
    {
        $this->denyTranslations[] = $pattern;

        return $this->changed();
    }

    /** Allow route() for route names matching the patterns ("shop.*", "pages.show"). */
    public function allowRoutes(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidArgumentException('Route patterns must not be empty.');
            }
            $this->routePatterns[] = $pattern;
        }

        return $this->changed();
    }

    public function denyRoute(string $pattern): self
    {
        $this->denyRoutes[] = $pattern;

        return $this->changed();
    }

    public function build(): SecurityPolicy
    {
        return $this->built ??= new SecurityPolicy(
            new ObjectAccessPolicy($this->methods, $this->properties, array_values(array_unique($this->arrayAccess)), array_values(array_unique($this->iteration)), array_values(array_unique($this->stringConversion)), array_values(array_unique($this->htmlable)), $this->macros),
            new DtoPolicy(array_values(array_unique($this->dtoClasses)), array_values(array_unique($this->dtoNamespaces)), $this->nativeDtoConversion),
            new ViewPolicy(array_values(array_unique($this->views)), array_values(array_unique($this->viewNamespaces))),
            new ComponentPolicy(array_values(array_unique($this->components)), $this->componentFactories),
            new DirectivePolicy($this->effectiveDirectives(), $this->directiveHandlers),
            new FunctionPolicy($this->functions, $this->constants, $this->classConstants, $this->staticMethods),
            new LivewirePolicy($this->livewireDirectives, $this->livewireActions, $this->livewireModels, $this->livewireEvents, array_values(array_unique($this->livewireComponents)), $this->alpine),
            $this->rawEcho,
            new ClassNamespacePolicy(array_values(array_unique($this->classNamespaces))),
            new DenyPolicy(array_values(array_unique($this->denyClasses)), array_values(array_unique($this->denyNamespaces)), $this->denyMethods, $this->denyProperties, $this->denyClassConstants, $this->denyStaticMethods, $this->denyFunctions, $this->denyConstants, array_values(array_unique($this->denyViews)), array_values(array_unique($this->denyRoutes)), array_values(array_unique($this->denyTranslations))),
            new KeyPatternPolicy($this->translationsEnabled, array_values(array_unique($this->translationPatterns))),
            new KeyPatternPolicy(true, array_values(array_unique($this->routePatterns))),
        );
    }

    /**
     * Translations are on by default: @lang / @choice are available unless translations are disabled or
     * the directive was denied explicitly.
     *
     * @return array<string, true>
     */
    private function effectiveDirectives(): array
    {
        $directives = $this->directives;
        if ($this->translationsEnabled) {
            foreach (DirectivePolicy::TRANSLATION as $directive) {
                if (!isset($this->deniedDirectives[$directive])) {
                    $directives[$directive] = true;
                }
            }
        }

        return $directives;
    }

    private function changed(): self
    {
        $this->built = null;

        return $this;
    }

    private static function className(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
    //#endregion Translations & routes
}
