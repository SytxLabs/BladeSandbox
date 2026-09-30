<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Learning;

use ReflectionMethod;
use SytxLabs\BladeSandbox\Support\Macros;
use Throwable;

final class PolicySuggestion
{
    private const DANGEROUS_FUNCTIONS = [
        'exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'assert', 'app', 'resolve', 'config', 'env', 'request', 'session', 'auth', 'cache', 'cookie', 'response',
        'redirect', 'view', 'dispatch', 'event', 'logger', 'storage_path', 'base_path', 'app_path', 'database_path', 'file_get_contents', 'file_put_contents', 'fopen', 'unlink', 'include', 'require',
        'call_user_func', 'call_user_func_array', 'array_map', 'array_filter', 'usort', 'serialize', 'unserialize', 'getenv', 'putenv', 'ini_set', 'phpinfo', 'dd', 'dump', 'var_dump', 'print_r',
    ];
    private const WRITE_METHODS = [
        'save', 'update', 'delete', 'forcedelete', 'destroy', 'create', 'insert', 'upsert', 'truncate', 'push', 'fill', 'forcefill', 'query',
        'newquery', 'increment', 'decrement', 'touch', 'restore', 'associate', 'dissociate', 'attach', 'detach', 'sync', 'toggle', 'setattribute', 'setrelation',
    ];

    /** @var array<string, array<string, true>> config key => values (flat keys) */
    private array $lists = [];
    /** @var array<string, array<string, array<string, true>>> config key => class => members */
    private array $members = [];
    private bool $rawEcho = false;
    /** @var list<string> */
    private array $flagged = [];
    /** @var list<string> */
    private array $errors;

    /**
     * @param list<array{capability: string, subject: string, message: string}> $findings
     * @param list<string> $errors templates that could not be compiled / rendered
     */
    public function __construct(array $findings = [], array $errors = [])
    {
        foreach ($findings as $finding) {
            $this->add($finding['capability'], $finding['subject'], $finding['message']);
        }
        $this->errors = array_values(array_unique($errors));
    }

    /** Combines the suggestions of several templates. */
    public function merge(self $other): self
    {
        $merged = clone $this;
        foreach ($other->lists as $key => $values) {
            $merged->lists[$key] = ($merged->lists[$key] ?? []) + $values;
        }
        foreach ($other->members as $key => $classes) {
            foreach ($classes as $class => $names) {
                $merged->members[$key][$class] = ($merged->members[$key][$class] ?? []) + $names;
            }
        }
        $merged->rawEcho = $merged->rawEcho || $other->rawEcho;
        $merged->flagged = array_values(array_unique([...$merged->flagged, ...$other->flagged]));
        $merged->errors = array_values(array_unique([...$merged->errors, ...$other->errors]));
        return $merged;
    }

    public function isEmpty(): bool
    {
        return $this->toConfig() === [] && $this->flagged === [] && $this->errors === [];
    }

    public function flagged(): array
    {
        return $this->flagged;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The missing allowances in the format of a policy in config/blade-sandbox.php.
     *
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        $config = [];
        foreach ($this->lists as $key => $values) {
            $values = array_keys($values);
            sort($values);
            $config[$key] = $values;
        }
        foreach ($this->members as $key => $classes) {
            ksort($classes);
            $config[$key] = array_map(static function (array $names): array {
                $names = array_keys($names);
                sort($names);
                return $names;
            }, $classes);
        }
        if ($this->rawEcho) {
            $config['raw_echo'] = true;
        }
        foreach (array_keys($config) as $key) {
            if (str_contains($key, '.')) {
                [$group, $name] = explode('.', $key, 2);
                $config[$group][$name] = $config[$key];
                unset($config[$key]);
            }
        }
        ksort($config);

        return $config;
    }

    public function toPhp(string $variable = '$sandbox'): string
    {
        $calls = [];
        $config = $this->toConfig();
        $list = static fn (array $values): string => implode(', ', array_map(static fn (string $v): string => var_export($v, true), $values));
        foreach (['functions' => 'allowFunction', 'constants' => 'allowConstant', 'views' => 'allowView', 'directives' => 'allowDirective', 'routes' => 'allowRoutes', 'translations' => 'allowTranslations'] as $key => $method) {
            if (isset($config[$key])) {
                $calls[] = $method.'('.$list($config[$key]).')';
            }
        }
        foreach (['components' => 'allowComponent', 'iteration' => 'allowIteration', 'array_access' => 'allowArrayAccess', 'string_conversion' => 'allowStringConversion'] as $key => $method) {
            foreach ($config[$key] ?? [] as $value) {
                $calls[] = $method.'('.var_export($value, true).')';
            }
        }
        foreach (['methods' => 'allowMethod', 'static_methods' => 'allowStaticMethod', 'properties' => 'allowProperty', 'class_constants' => 'allowClassConstant', 'macros' => 'allowMacro'] as $key => $method) {
            foreach ($config[$key] ?? [] as $class => $names) {
                $calls[] = $method.'(\\'.ltrim($class, '\\').'::class, ['.$list($names).'])';
            }
        }
        foreach (['directives' => 'allowLivewireDirective', 'actions' => 'allowLivewireAction'] as $key => $method) {
            foreach ($config['livewire'][$key] ?? [] as $value) {
                $calls[] = $method.'('.var_export($value, true).')';
            }
        }
        if ($this->rawEcho) {
            $calls[] = 'allowRawEcho()';
        }
        return $calls === [] ? '' : $variable."\n    ->".implode("\n    ->", $calls).';';
    }

    /** @return array{config: array<string, mixed>, flagged: list<string>, errors: list<string>} */
    public function toArray(): array
    {
        return ['config' => $this->toConfig(), 'flagged' => $this->flagged, 'errors' => $this->errors];
    }

    private function add(string $capability, string $subject, string $message): void
    {
        if (str_contains($message, 'callable')) {
            $this->flag($subject.': callable arguments are never allowed');
            return;
        }
        match ($capability) {
            'function' => $this->addFunction(preg_replace('/\(\)\z/', '', $subject) ?? $subject),
            'method' => $this->addMethod($subject, $message),
            'property' => $this->addProperty($subject, $message),
            'view-namespace' => $this->list('view_namespaces', $subject),
            'view' => str_contains($message, 'invalid view name') ? $this->flag($message) : $this->list('views', $subject),
            'component' => str_contains($message, 'invalid') ? $this->flag($message) : $this->list('components', $subject),
            'directive' => $this->addDirective($subject, $message),
            'route' => $this->list('routes', $subject),
            'translation' => $this->list('translations', $subject),
            'livewire-directive' => $this->list('livewire.directives', $subject),
            'livewire-action' => $this->list('livewire.actions', $subject),
            default => $this->flag($message),
        };
    }

    private function addFunction(string $function): void
    {
        if (in_array(strtolower($function), self::DANGEROUS_FUNCTIONS, true)) {
            $this->flag($function.'(): executes code or reaches application internals, never allow it');
            return;
        }
        $this->list('functions', $function);
    }

    private function addMethod(string $subject, string $message): void
    {
        if (preg_match('/\A(.+)::([A-Za-z_]\w*)\(\)\z/', $subject, $match) !== 1 || str_contains($message, 'facades') || str_contains($message, 'unknown class') || str_contains($message, "DTO's public API")) {
            $this->flag($message);
            return;
        }
        [$class, $method] = [$match[1], $match[2]];
        $lower = strtolower($method);

        match (true) {
            $lower === '__tostring' => $this->list('string_conversion', $class),
            $lower === 'getiterator' => $this->list('iteration', $class),
            $lower === 'offsetget' => $this->list('array_access', $class),
            str_starts_with($method, '__') || $lower === 'tohtml' => $this->flag($message),
            in_array($lower, self::WRITE_METHODS, true) => $this->flag($subject.': changes data or opens a query, never allow it in templates'),
            str_contains($message, 'macro') || Macros::has($class, $method) => $this->member('macros', $class, $method),
            self::isStatic($class, $method) => $this->member('static_methods', $class, $method),
            default => $this->member('methods', $class, $method),
        };
    }

    private function addProperty(string $subject, string $message): void
    {
        match (true) {
            str_starts_with($subject, 'constant ') => $this->list('constants', substr($subject, 9)),
            preg_match('/\A(.+)::\$(\w+)\z/', $subject, $match) === 1 => $this->member('properties', $match[1], $match[2]),
            preg_match('/\A([\w\\\\]+)::(\w+)\z/', $subject, $match) === 1 => $this->member('class_constants', $match[1], $match[2]),
            default => $this->flag($message),
        };
    }

    private function addDirective(string $subject, string $message): void
    {
        if ($subject === '{!! !!}') {
            $this->rawEcho = true;
        } elseif (str_contains($message, 'never') || str_contains($message, 'application directives')) {
            $this->flag($message);
        } else {
            $this->list('directives', ltrim($subject, '@'));
        }
    }

    private function list(string $key, string $value): void
    {
        $this->lists[$key][$value] = true;
    }

    private function member(string $key, string $class, string $member): void
    {
        $this->members[$key][ltrim($class, '\\')][$member] = true;
    }

    private function flag(string $reason): void
    {
        if (!in_array($reason, $this->flagged, true)) {
            $this->flagged[] = $reason;
        }
    }

    private static function isStatic(string $class, string $method): bool
    {
        try {
            return class_exists($class, false) && method_exists($class, $method) && (new ReflectionMethod($class, $method))->isStatic();
        } catch (Throwable) {
            return false;
        }
    }
}
