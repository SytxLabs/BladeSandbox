<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox;

use InvalidArgumentException;

final class PolicyConfiguration
{
    /** @param array<string, mixed> $definition */
    public static function apply(Sandbox $sandbox, array $definition): Sandbox
    {
        // Parent policies first ('extends' => 'default' or ['base', 'mail']); the keys below add to them.
        $parents = array_values(array_map('strval', (array) ($definition['extends'] ?? [])));
        if ($parents !== []) {
            $sandbox->extend(...$parents);
        }

        // Presets are Sandbox macros (Sandbox::macro('cms', fn () => $this->allowView(...))).
        foreach ((array) ($definition['presets'] ?? []) as $preset) {
            $preset = (string) $preset;
            if (! Sandbox::hasMacro($preset)) {
                throw new InvalidArgumentException('Unknown sandbox preset "'.$preset.'" (register it with Sandbox::macro()).');
            }
            $sandbox->{$preset}();
        }
        foreach ((array) ($definition['views'] ?? []) as $view) {
            $sandbox->allowView((string) $view);
        }
        foreach ((array) ($definition['view_namespaces'] ?? []) as $namespace) {
            $sandbox->allowViewNamespace((string) $namespace);
        }
        foreach ((array) ($definition['dtos'] ?? []) as $dto) {
            $sandbox->allowDto((string) $dto);
        }
        foreach ((array) ($definition['dto_namespaces'] ?? []) as $namespace) {
            $sandbox->allowDtoNamespace((string) $namespace);
        }
        if (array_key_exists('native_dto_conversion', $definition)) {
            $sandbox->nativeDtoConversion((bool) $definition['native_dto_conversion']);
        }
        foreach ((array) ($definition['functions'] ?? []) as $function) {
            $sandbox->allowFunction((string) $function);
        }
        foreach ((array) ($definition['constants'] ?? []) as $constant) {
            $sandbox->allowConstant((string) $constant);
        }
        foreach ((array) ($definition['class_constants'] ?? []) as $class => $constants) {
            $sandbox->allowClassConstant((string) $class, (array) $constants);
        }
        foreach ((array) ($definition['class_namespaces'] ?? []) as $namespace) {
            $sandbox->allowClassNamespace((string) $namespace);
        }
        foreach ((array) ($definition['static_methods'] ?? []) as $class => $methods) {
            $sandbox->allowStaticMethod((string) $class, array_values((array) $methods));
        }
        foreach ((array) ($definition['methods'] ?? []) as $class => $methods) {
            $sandbox->allowMethod((string) $class, array_values((array) $methods));
        }
        foreach ((array) ($definition['macros'] ?? []) as $class => $macros) {
            $sandbox->allowMacro((string) $class, array_values((array) $macros));
        }
        foreach ((array) ($definition['properties'] ?? []) as $class => $properties) {
            $sandbox->allowProperty((string) $class, array_values((array) $properties));
        }
        foreach ((array) ($definition['iteration'] ?? []) as $class) {
            $sandbox->allowIteration((string) $class);
        }
        foreach ((array) ($definition['array_access'] ?? []) as $class) {
            $sandbox->allowArrayAccess((string) $class);
        }
        foreach ((array) ($definition['string_conversion'] ?? []) as $class) {
            $sandbox->allowStringConversion((string) $class);
        }
        foreach ((array) ($definition['components'] ?? []) as $component) {
            $sandbox->allowComponent((string) $component);
        }
        foreach ((array) ($definition['directives'] ?? []) as $directive) {
            $sandbox->allowDirective((string) $directive);
        }
        if (is_string($definition['integrity_manifest'] ?? null) && $definition['integrity_manifest'] !== '') {
            $sandbox->verifyIntegrity($definition['integrity_manifest']);
        }
        if (is_string($definition['sanitizer'] ?? null) && $definition['sanitizer'] !== '') {
            $sandbox->sanitizeWith($definition['sanitizer']);
        }
        foreach ((array) ($definition['view_sanitizers'] ?? []) as $pattern => $sanitizer) {
            $sandbox->sanitizeWith((string) $sanitizer, (string) $pattern);
        }
        $sandbox->denyClass(...array_map('strval', array_values((array) ($definition['deny_classes'] ?? []))));
        $sandbox->denyClassNamespace(...array_map('strval', array_values((array) ($definition['deny_class_namespaces'] ?? []))));
        $sandbox->denyFunction(...array_map('strval', array_values((array) ($definition['deny_functions'] ?? []))));
        $sandbox->denyConstant(...array_map('strval', array_values((array) ($definition['deny_constants'] ?? []))));
        $sandbox->denyView(...array_map('strval', array_values((array) ($definition['deny_views'] ?? []))));
        foreach ((array) ($definition['deny_methods'] ?? []) as $class => $methods) {
            $sandbox->denyMethod((string) $class, array_values(array_map('strval', (array) $methods)));
        }
        foreach ((array) ($definition['deny_static_methods'] ?? []) as $class => $methods) {
            $sandbox->denyStaticMethod((string) $class, array_values(array_map('strval', (array) $methods)));
        }
        foreach ((array) ($definition['deny_properties'] ?? []) as $class => $properties) {
            $sandbox->denyProperty((string) $class, array_values(array_map('strval', (array) $properties)));
        }
        foreach ((array) ($definition['deny_class_constants'] ?? []) as $class => $constants) {
            $sandbox->denyClassConstant((string) $class, array_values(array_map('strval', (array) $constants)));
        }

        if (array_key_exists('translations', $definition)) {
            $translations = $definition['translations'];
            if ($translations === false) {
                $sandbox->withoutTranslations();
            } elseif (is_array($translations)) {
                $sandbox->allowTranslations(...array_values(array_map('strval', $translations)));
            }
        }
        $sandbox->denyTranslation(...array_values(array_map('strval', (array) ($definition['deny_translations'] ?? []))));
        $sandbox->allowRoutes(...array_values(array_map('strval', (array) ($definition['routes'] ?? []))));
        $sandbox->denyRoute(...array_values(array_map('strval', (array) ($definition['deny_routes'] ?? []))));

        foreach ((array) ($definition['helpers'] ?? []) as $set) {
            $sandbox->allowHelpers((string) $set);
        }
        if (is_string($definition['fallback'] ?? null) && $definition['fallback'] !== '') {
            $sandbox->renderFallback($definition['fallback']);
        }
        if (is_array($definition['output_cache'] ?? null)) {
            $ttl = $definition['output_cache']['ttl'] ?? 3600;
            $store = $definition['output_cache']['store'] ?? null;
            $sandbox->cacheOutput(is_int($ttl) ? $ttl : null, is_string($store) ? $store : null);
        }
        if ($definition['auth_directives'] ?? false) {
            $sandbox->allowAuthDirectives();
        }
        if ($definition['raw_echo'] ?? false) {
            $sandbox->allowRawEcho();
        }

        $livewire = (array) ($definition['livewire'] ?? []);
        foreach ((array) ($livewire['directives'] ?? []) as $directive) {
            $sandbox->allowLivewireDirective((string) $directive);
        }
        foreach ((array) ($livewire['actions'] ?? []) as $action) {
            $sandbox->allowLivewireAction((string) $action);
        }
        foreach ((array) ($livewire['models'] ?? []) as $model) {
            $sandbox->allowLivewireModel((string) $model);
        }
        foreach ((array) ($livewire['events'] ?? []) as $event) {
            $sandbox->allowLivewireEvent((string) $event);
        }
        foreach ((array) ($livewire['components'] ?? []) as $component) {
            $sandbox->allowLivewireComponent((string) $component);
        }
        if ($livewire['alpine'] ?? false) {
            $sandbox->allowAlpine();
        }

        return $sandbox;
    }
}
