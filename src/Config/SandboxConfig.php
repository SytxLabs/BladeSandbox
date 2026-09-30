<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Config;

use SytxLabs\BladeSandbox\Sanitizers\DefaultSanitizer;
use SytxLabs\BladeSandbox\Sanitizers\FileSanitizerHtmlSanitizer;
use SytxLabs\BladeSandbox\Sanitizers\HtmlSanitizer;

final class SandboxConfig
{
    public const LIMIT_KEYS = ['max_depth', 'max_output_bytes', 'max_iterations', 'timeout_ms', 'max_memory_bytes'];

    public const POLICY_DEFAULTS = [
        'extends' => [],
        'presets' => [],
        'views' => [],
        'view_namespaces' => [],
        'dtos' => [],
        'dto_namespaces' => [],
        'native_dto_conversion' => true,
        'methods' => [],
        'properties' => [],
        'iteration' => [],
        'array_access' => [],
        'string_conversion' => [],
        'functions' => [],
        'constants' => [],
        'class_constants' => [],
        'static_methods' => [],
        'macros' => [],
        'class_namespaces' => [],
        'components' => [],
        'directives' => [],
        'auth_directives' => false,
        'translations' => true,
        'routes' => [],
        'deny_classes' => [],
        'deny_class_namespaces' => [],
        'deny_methods' => [],
        'deny_static_methods' => [],
        'deny_properties' => [],
        'deny_class_constants' => [],
        'deny_functions' => [],
        'deny_constants' => [],
        'deny_views' => [],
        'deny_routes' => [],
        'deny_translations' => [],
        'helpers' => [],
        'fallback' => false,
        'output_cache' => null,
        'raw_echo' => false,
        'integrity_manifest' => null,
        'sanitizer' => null,
        'view_sanitizers' => [],
        'livewire' => [
            'directives' => [],
            'actions' => [],
            'models' => [],
            'events' => [],
            'components' => [],
            'alpine' => false,
        ],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'default' => 'default',
            'policy_resolver' => null,
            'policies' => ['default' => []],
            'namespaces' => [],
            'value_objects' => true,
            'limits' => [
                'max_depth' => 32,
                'max_output_bytes' => 0,
                'max_iterations' => 1_000_000,
                'timeout_ms' => 10_000,
                'max_memory_bytes' => 0,
            ],
            'cache' => [
                'driver' => 'file',
                'path' => null,
                'disk' => 'local',
            ],
            'cache_path' => null,
            'loaders' => [],
            'output_cache' => [
                'store' => null,
                'prefix' => 'blade-sandbox:output:',
            ],
            'logging' => [
                'enabled' => false,
                'channel' => null,
                'level' => 'warning',
                'logger' => null,
            ],
            'author_lockout' => [
                'enabled' => false,
                'max_violations' => 5,
                'decay_minutes' => 60,
                'store' => null,
                'prefix' => 'blade-sandbox:lockout:',
            ],
            'isolation' => [
                'timeout' => 10,
                'memory_limit' => '256M',
                'command' => null,
            ],
            'fallback_report' => true,
            'text_word_wrap' => 0,
            'hidden_variables' => ['app', '__env', '_instance', '__livewire'],
            'sanitizers' => [
                'default' => DefaultSanitizer::class,
                'strict' => [DefaultSanitizer::class, ['rejectUnsafe' => true]],
                'html' => HtmlSanitizer::class,
                'html-strict' => [HtmlSanitizer::class, ['rejectUnsafe' => true]],
                'filesanitizer' => FileSanitizerHtmlSanitizer::class,
                'filesanitizer-strict' => [FileSanitizerHtmlSanitizer::class, ['rejectUnsafe' => true]],
            ],
            'integrity' => [
                'key' => null,
            ],
            'livewire' => [
                'guard_requests' => true,
            ],
        ];
    }

    public static function resolve(array $config): array
    {
        return self::merge(self::defaults(), self::normalize($config));
    }

    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = $base[$key] ?? null;
            $group = is_array($current) && $current !== [] && ! array_is_list($current);
            $base[$key] = match (true) {
                $group && $value === [] => $current,
                $group && is_array($value) && ! array_is_list($value) => self::merge($current, $value),
                default => $value,
            };
        }

        return $base;
    }

    public static function normalize(array $config): array
    {
        foreach (self::LIMIT_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                $limits = is_array($config['limits'] ?? null) ? $config['limits'] : [];
                $limits[$key] = $config[$key];
                $config['limits'] = $limits;
                unset($config[$key]);
            }
        }

        if (is_array($config['audit'] ?? null)) {
            $logging = is_array($config['logging'] ?? null) ? $config['logging'] : [];
            if ($config['audit']['enabled'] ?? false) {
                $logging['enabled'] = true;
            }
            if (($logging['channel'] ?? null) === null && isset($config['audit']['channel'])) {
                $logging['channel'] = $config['audit']['channel'];
            }
            $config['logging'] = $logging;
            unset($config['audit']);
        }

        return $config;
    }
}
