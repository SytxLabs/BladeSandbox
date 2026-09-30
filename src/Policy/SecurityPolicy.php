<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use JsonException;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;

/**
 * Immutable snapshot of a sandbox's capabilities. Built by {@see PolicyBuilder}.
 */
final class SecurityPolicy implements SandboxPolicy
{
    /** Translation helpers handled by the translation policy instead of the function allowlist. */
    public const TRANSLATION_FUNCTIONS = ['__', 'trans', 'trans_choice'];

    private ?string $fingerprint = null;

    public function __construct(
        private readonly ObjectAccessPolicy $objects,
        private readonly DtoPolicy $dtos,
        private readonly ViewPolicy $views,
        private readonly ComponentPolicy $components,
        private readonly DirectivePolicy $directives,
        private readonly FunctionPolicy $functions,
        private readonly LivewirePolicy $livewire,
        private readonly bool $rawEcho = false,
        private readonly ClassNamespacePolicy $classNamespaces = new ClassNamespacePolicy(),
        private readonly DenyPolicy $denies = new DenyPolicy(),
        private readonly KeyPatternPolicy $translations = new KeyPatternPolicy(),
        private readonly KeyPatternPolicy $routes = new KeyPatternPolicy(true, []),
    ) {
    }

    public static function denyAll(): self
    {
        return (new PolicyBuilder())->build();
    }

    public function allowsFunction(string $name): bool
    {
        if ($this->denies->deniesFunction($name)) {
            return false;
        }

        $function = strtolower(ltrim($name, '\\'));
        if (in_array($function, self::TRANSLATION_FUNCTIONS, true) && $this->translations->enabled()) {
            return true;
        }
        if ($function === 'route' && $this->routes->restricted()) {
            return true;
        }

        return $this->functions->allowsFunction($name);
    }

    public function allowsTranslation(string $key): bool
    {
        return !$this->denies->deniesTranslation($key) && $this->translations->allows($key);
    }

    public function allowsRoute(string $name): bool
    {
        if ($name === '' || $this->denies->deniesRoute($name) || $this->denies->deniesFunction('route')) {
            return false;
        }
        return $this->routes->restricted() ? $this->routes->allows($name) : $this->functions->allowsFunction('route');
    }

    public function allowsConstant(string $name): bool
    {
        return !$this->denies->deniesConstant($name) && $this->functions->allowsConstant($name);
    }

    public function allowsClassConstant(string $class, string $constant): bool
    {
        if ($this->denies->deniesClassConstant($class, $constant)) {
            return false;
        }
        return $this->functions->allowsClassConstant($class, $constant) || $this->classNamespaces->contains($class);
    }

    public function allowsStaticMethod(string $class, string $method): bool
    {
        if (str_starts_with($method, '__') || $this->denies->deniesStaticMethod($class, $method)) {
            return false;
        }

        return $this->functions->allowsStaticMethod($class, $method) || $this->classNamespaces->contains($class);
    }

    public function allowsClassNamespace(object|string $class): bool
    {
        return !$this->denies->deniesClass($class) && $this->classNamespaces->contains($class);
    }

    public function allowsMethod(object|string $class, string $method): bool
    {
        if ($this->denies->deniesMethod($class, $method)) {
            return false;
        }
        if ($this->objects->allowsMethod($class, $method)) {
            return true;
        }
        return !str_starts_with($method, '__') && $this->classNamespaces->contains($class);
    }

    public function allowsMethodExplicitly(object|string $class, string $method): bool
    {
        return !$this->denies->deniesMethod($class, $method) && $this->objects->allowsMethodExplicitly($class, $method);
    }

    public function allowsMacro(object|string $class, string $macro): bool
    {
        if ($this->denies->deniesMethod($class, $macro) || (is_string($class) && $this->denies->deniesStaticMethod($class, $macro))) {
            return false;
        }
        return $this->objects->allowsMacro($class, $macro);
    }

    public function allowsProperty(object|string $class, string $property): bool
    {
        if ($this->denies->deniesProperty($class, $property)) {
            return false;
        }

        return $this->objects->allowsProperty($class, $property) || $this->classNamespaces->contains($class);
    }

    public function allowsArrayAccess(object $object): bool
    {
        return !$this->denies->deniesClass($object) && $this->objects->allowsArrayAccess($object);
    }

    public function allowsIteration(object $object): bool
    {
        return !$this->denies->deniesClass($object) && $this->objects->allowsIteration($object);
    }

    public function allowsStringConversion(object $object): bool
    {
        if ($this->denies->deniesClass($object)) {
            return false;
        }

        return $this->objects->allowsStringConversion($object) || $this->classNamespaces->contains($object);
    }

    public function allowsHtmlable(object $object): bool
    {
        return !$this->denies->deniesClass($object) && $this->objects->allowsHtmlable($object);
    }

    public function allowsView(string $view): bool
    {
        return !$this->denies->deniesView($view) && $this->views->allowsView($view);
    }

    public function deniesView(string $view): bool
    {
        return $this->denies->deniesView($view);
    }

    public function allowsViewNamespace(string $namespace): bool
    {
        return $this->views->allowsNamespace($namespace);
    }

    public function allowsComponent(string $component): bool
    {
        return $this->components->allows($component);
    }

    public function allowsDirective(string $directive): bool
    {
        return $this->directives->allows($directive);
    }

    public function allowsDto(object|string $class): bool
    {
        return !$this->denies->deniesClass($class) && $this->dtos->allows($class);
    }

    public function allowsRawEcho(): bool
    {
        return $this->rawEcho;
    }

    public function allowsLivewireDirective(string $directive): bool
    {
        return $this->livewire->allowsDirective($directive);
    }

    public function allowsLivewireAction(string $action): bool
    {
        return $this->livewire->allowsAction($action);
    }

    public function allowsLivewireModel(string $property): bool
    {
        return $this->livewire->allowsModel($property);
    }

    public function allowsLivewireEvent(string $event): bool
    {
        return $this->livewire->allowsEvent($event);
    }

    public function allowsLivewireComponent(string $component): bool
    {
        return $this->livewire->allowsComponent($component);
    }

    public function allowsAlpine(): bool
    {
        return $this->livewire->allowsAlpine();
    }

    public function usesNativeDtoConversion(): bool
    {
        return $this->dtos->nativeConversion();
    }

    public function directives(): DirectivePolicy
    {
        return $this->directives;
    }

    public function components(): ComponentPolicy
    {
        return $this->components;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'objects' => $this->objects->describe(),
            'dtos' => $this->dtos->describe(),
            'views' => $this->views->describe(),
            'components' => $this->components->describe(),
            'directives' => $this->directives->describe(),
            'functions' => $this->functions->describe(),
            'livewire' => $this->livewire->describe(),
            'rawEcho' => $this->rawEcho,
            'classNamespaces' => $this->classNamespaces->describe(),
            'denies' => $this->denies->describe(),
            'translations' => $this->translations->describe(),
            'routes' => $this->routes->describe(),
        ];
    }

    public function fingerprint(): string
    {
        try {
            return $this->fingerprint ??= hash('sha256', json_encode($this->describe(), JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return $this->fingerprint ??= hash('sha256', serialize($this->describe()));
        }
    }
}
