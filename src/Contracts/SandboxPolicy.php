<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/**
 * The complete, immutable capability set of one sandbox.
 * Everything that is not explicitly allowed is denied (default deny + explicit allow).
 */
interface SandboxPolicy extends ObjectPolicy
{
    public function allowsFunction(string $name): bool;

    public function allowsConstant(string $name): bool;

    /** A translation key used with __(), trans(), trans_choice(), @lang or @choice. */
    public function allowsTranslation(string $key): bool;

    /** A route name used with route(). */
    public function allowsRoute(string $name): bool;

    public function allowsClassConstant(string $class, string $constant): bool;

    /** Static method call `Class::method()` (never magic methods / __callStatic). */
    public function allowsStaticMethod(string $class, string $method): bool;

    /** A method the class does not declare (it reaches __call()) that was allowed by its exact name with allowMethod(). Wildcards and class namespaces never cover undeclared methods. */
    public function allowsMethodExplicitly(object|string $class, string $method): bool;

    /** A macro registered on a Macroable class (instance or static call) that was allowed with allowMacro(). */
    public function allowsMacro(object|string $class, string $macro): bool;

    /** Whether the class belongs to a namespace allowed with allowClassNamespace(). */
    public function allowsClassNamespace(object|string $class): bool;

    public function allowsView(string $view): bool;

    public function allowsViewNamespace(string $namespace): bool;

    /** Whether the view matches a deny rule (denyView()); deny rules win over every allow. */
    public function deniesView(string $view): bool;

    public function allowsComponent(string $component): bool;

    public function allowsDirective(string $directive): bool;

    public function allowsDto(object|string $class): bool;

    public function allowsRawEcho(): bool;

    public function allowsLivewireDirective(string $directive): bool;

    public function allowsLivewireAction(string $action): bool;

    public function allowsLivewireModel(string $property): bool;

    public function allowsLivewireEvent(string $event): bool;

    public function allowsLivewireComponent(string $component): bool;

    public function allowsAlpine(): bool;

    /**
     * Whether DTO iteration / string conversion may use the DTO's own getIterator() / __toString().
     * When false the sandbox builds both from the sandbox-visible members only.
     */
    public function usesNativeDtoConversion(): bool;

    /** A stable hash of every compile-relevant setting. Used as part of the compiled-template cache key so a template validated under one policy is never reused under another. */
    public function fingerprint(): string;
}
