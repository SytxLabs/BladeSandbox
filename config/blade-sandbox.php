<?php

/*
|--------------------------------------------------------------------------
| Blade Sandbox
|--------------------------------------------------------------------------
|
| Only list what you change: this file is deep-merged over the package defaults (SytxLabs\BladeSandbox\Config\SandboxConfig::defaults()), so removed keys keep their default.
| Packages (e.g. a CMS) can override settings and policies from their service provider with BladeSandbox::configure([...]), BladeSandbox::definePolicy() and BladeSandbox::extendPolicy().
| Every key is described in the README ("Configuration reference").
*/

return [

    // Policy of BladeSandbox::render() / renderView(); a PolicyResolver class may choose one per request.
    'default' => env('BLADE_SANDBOX_POLICY', 'default'),
    'policy_resolver' => null,

    // Everything not allowed in a policy is denied. More keys: extends, presets, dtos, methods, functions, class_namespaces, components, directives, translations, routes, deny_*, fallback, sanitizer, livewire, ...
    'policies' => [
        'default' => [
            'views' => [],              // 'plugin::emails.*' (one level), 'plugin::**' (any depth)
            'view_namespaces' => [],
            'dtos' => [],
            'helpers' => [],            // ['safe'] = vetted string / number / date helpers
            'fallback' => false,        // 'source' | 'strip' | 'escaped' | 'empty': unrendered output on errors
        ],
    ],

    // View namespaces that are ALWAYS rendered through a policy (view(), @include, Mailables, Livewire): 'plugin' => 'default'.
    'namespaces' => [],

    // Templates from elsewhere than files, e.g. 'cms' => ['driver' => 'eloquent', 'model' => Template::class, 'name' => 'identifier', 'content' => 'content'], ['driver' => 'array', 'templates' => [...]] or a class.
    'loaders' => [],

    // Checked at every loop iteration / include / component; 0 disables a limit (not max_depth).
    'limits' => [
        'max_iterations' => 1_000_000,
        'timeout_ms' => 10_000,
        'max_memory_bytes' => 0,
        'max_output_bytes' => 0,
        'max_depth' => 32,
    ],

    // Compiled templates (included PHP, keep them local and non-writable for untrusted parties): file | disk (local filesystem disk) | memory | a driver from BladeSandbox::extendCache().
    'cache' => [
        'driver' => env('BLADE_SANDBOX_CACHE_DRIVER', 'file'),
        'path' => env('BLADE_SANDBOX_CACHE_PATH'),          // null = <view.compiled>/blade-sandbox
        'disk' => env('BLADE_SANDBOX_CACHE_DISK', 'local'),
    ],

    // Rendered output cache (->cacheOutput()); null = default cache store.
    'output_cache' => [
        'store' => env('BLADE_SANDBOX_OUTPUT_CACHE_STORE'),
    ],

    // Security violations (capability + subject, never template source or values). "logger" is a log channel or a Psr\Log\LoggerInterface class; "channel" a log channel (null = default).
    'logging' => [
        'enabled' => env('BLADE_SANDBOX_LOG', false),
        'channel' => env('BLADE_SANDBOX_LOG_CHANNEL'),
        'level' => env('BLADE_SANDBOX_LOG_LEVEL', 'warning'),
        'logger' => null,
    ],

    // ->forAuthor($id): lock authors after max_violations within decay_minutes.
    'author_lockout' => [
        'enabled' => env('BLADE_SANDBOX_AUTHOR_LOCKOUT', false),
        'max_violations' => 5,
        'decay_minutes' => 60,
    ],

    // ->isolated(): child PHP process, killed after "timeout" seconds; "command" = null uses [PHP_BINARY, base_path('artisan'), 'blade-sandbox:render'].
    'isolation' => [
        'timeout' => 10,
        'memory_limit' => '256M',
        'command' => null,
    ],

    // Named output sanitizers are merged with the defaults (default, strict, html, html-strict, filesanitizer, filesanitizer-strict): 'cms' => App\Sandbox\CmsSanitizer::class.
    'sanitizers' => [],
];
