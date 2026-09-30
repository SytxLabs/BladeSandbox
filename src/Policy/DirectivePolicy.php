<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use Closure;

final class DirectivePolicy
{
    public const DEFAULTS = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless', 'isset', 'endisset', 'empty', 'endempty',
        'foreach', 'endforeach', 'forelse', 'endforelse', 'for', 'endfor', 'while', 'endwhile',
        'switch', 'case', 'default', 'break', 'continue', 'endswitch',
        'include', 'includeif', 'includewhen', 'includeunless', 'includefirst', 'each',
        'extends', 'section', 'endsection', 'show', 'stop', 'append', 'overwrite', 'yield', 'parent',
        'hassection', 'sectionmissing', 'push', 'endpush', 'prepend', 'endprepend', 'stack', 'once', 'endonce',
        'verbatim', 'endverbatim', 'json', 'class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required',
        'props', 'aware', 'component', 'endcomponent', 'slot', 'endslot', 'var',
    ];
    public const OPT_IN = [
        'csrf', 'method', 'lang', 'choice', 'error', 'enderror', 'livewire', 'markdown', 'endmarkdown',
        'auth', 'elseauth', 'endauth', 'guest', 'elseguest', 'endguest',
        'can', 'elsecan', 'endcan', 'cannot', 'elsecannot', 'endcannot', 'canany', 'elsecanany', 'endcanany',
    ];
    public const TRANSLATION = ['lang', 'choice'];
    public const AUTH = ['auth', 'guest', 'can', 'cannot', 'canany'];
    public const COMPANIONS = [
        'error' => ['enderror'],
        'markdown' => ['endmarkdown'],
        'auth' => ['elseauth', 'endauth'],
        'guest' => ['elseguest', 'endguest'],
        'can' => ['elsecan', 'endcan'],
        'cannot' => ['elsecannot', 'endcannot'],
        'canany' => ['elsecanany', 'endcanany'],
    ];
    public const NEVER = [
        'php', 'endphp', 'inject', 'use', 'dd', 'dump', 'env', 'endenv', 'production', 'endproduction', 'session', 'endsession', 'context', 'endcontext',
        'vite', 'vitereactrefresh', 'livewirestyles', 'livewirescripts', 'livewirescriptconfig', 'persist', 'endpersist', 'entangle', 'this', 'js', 'fragment', 'endfragment', 'unset',
        'teleport', 'endteleport', 'island', 'endisland', 'script', 'endscript', 'assets', 'endassets', 'volt', 'pushonce', 'endpushonce', 'prependonce', 'endprependonce', 'pushif', 'endpushif',
        'extendsfirst', 'includeisolated', 'hasstack', 'bool', 'elseenv',
    ];

    /**
     * @param array<string, true> $allowed lower-cased
     * @param array<string, Closure> $handlers lower-cased name => runtime handler
     */
    public function __construct(private readonly array $allowed, private readonly array $handlers = [])
    {
    }

    public function allows(string $directive): bool
    {
        $directive = strtolower($directive);
        return isset($this->allowed[$directive]) || isset($this->handlers[$directive]);
    }

    public function handler(string $directive): ?Closure
    {
        return $this->handlers[strtolower($directive)] ?? null;
    }

    public function isCustom(string $directive): bool
    {
        return isset($this->handlers[strtolower($directive)]);
    }

    public static function isKnownBuiltIn(string $directive): bool
    {
        $directive = strtolower($directive);
        return in_array($directive, self::DEFAULTS, true) || in_array($directive, self::OPT_IN, true) || in_array($directive, self::NEVER, true);
    }

    /** @return array<string, list<string>> */
    public function describe(): array
    {
        $allowed = array_keys($this->allowed);
        $handlers = array_keys($this->handlers);
        sort($allowed);
        sort($handlers);

        return ['allowed' => $allowed, 'custom' => $handlers];
    }
}
