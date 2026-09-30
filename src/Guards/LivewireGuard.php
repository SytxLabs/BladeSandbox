<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Compiler\HtmlContextTracker;
use SytxLabs\BladeSandbox\Contracts\SandboxPolicy;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenLivewireActionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenLivewireDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Livewire\WireDirectiveKind;

/**
 * Livewire related checks.
 *
 * Template side (defense in depth): `wire:*` attributes a template emits are validated against the policy, action values must be a single statically known call with literal arguments. When Livewire
 * is installed, JavaScript surfaces that can reach `$wire` (Alpine attributes, inline event handlers, <script>) are denied unless allowAlpine() is set.
 *
 * Server side (authoritative): every incoming action call / property update of a sandboxed component is checked, because a browser can send requests regardless of what the template rendered.
 */
final class LivewireGuard extends Guard
{
    /** @noinspection RegExpUnnecessaryNonCapturingGroup */
    private const LITERAL = '(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?|true|false|null)';
    private const MAGIC_MODEL = ['$set', '$toggle'];
    private const MAGIC_EVENT = ['$dispatch', '$dispatchSelf', '$dispatchTo', '$dispatchUp', '$emit', '$emitSelf', '$emitTo', '$emitUp'];

    public function __construct(SandboxPolicy $policy, AuditLogger $audit, private readonly LivewireAdapter $adapter)
    {
        parent::__construct($policy, $audit);
    }

    public function enforcesJavaScript(): bool
    {
        return $this->adapter->isInstalled() && !$this->policy->allowsAlpine();
    }

    public static function isWireAttribute(string $name): bool
    {
        return str_starts_with(strtolower($name), 'wire:');
    }

    /** @param bool $componentTag on Blade component tags ":name" is a PHP binding, not Alpine. */
    public static function isJavaScriptAttribute(string $name, bool $componentTag = false): bool
    {
        $lower = strtolower($name);
        return str_starts_with($lower, 'x-') || str_starts_with($lower, '@') || (!$componentTag && str_starts_with($lower, ':')) || preg_match('/\Aon[a-z]+\z/', $lower) === 1;
    }

    /**
     * Validate one HTML attribute.
     *
     * @param string|null $value null when the attribute has no value
     * @param bool $dynamic the value (partly) comes from an echo/expression and is unknown at compile time
     */
    public function checkAttribute(string $name, ?string $value, bool $dynamic = false, bool $componentTag = false): void
    {
        if (self::isWireAttribute($name)) {
            $this->checkWireAttribute($name, $value, $dynamic);
            return;
        }

        if ($this->enforcesJavaScript() && self::isJavaScriptAttribute($name, $componentTag)) {
            throw $this->deny(ForbiddenLivewireDirectiveException::for(self::clip($name), 'JavaScript attribute; enable with allowAlpine()'));
        }
    }

    public function checkTag(string $tag): void
    {
        if ($this->enforcesJavaScript() && in_array(strtolower($tag), ['script', 'iframe', 'object', 'embed', 'template'], true)) {
            throw $this->deny(ForbiddenLivewireDirectiveException::for('<'.strtolower($tag).'>', 'JavaScript surface; enable with allowAlpine()'));
        }
    }

    public function checkWireAttribute(string $name, ?string $value, bool $dynamic): void
    {
        $directive = strtolower(substr($name, 5));
        $base = explode('.', $directive, 2)[0];
        if ($base === '') {
            throw $this->deny(ForbiddenLivewireDirectiveException::for('wire:'));
        }

        $kind = $this->adapter->directiveKind($base);
        $root = explode(':', $base, 2)[0];

        if ($kind === WireDirectiveKind::Internal) {
            throw $this->deny(ForbiddenLivewireDirectiveException::for('wire:'.$base, 'internal'));
        }

        if (!$this->policy->allowsLivewireDirective($base) && !$this->policy->allowsLivewireDirective($root)) {
            throw $this->deny(ForbiddenLivewireDirectiveException::for('wire:'.$base));
        }

        switch ($kind) {
            case WireDirectiveKind::Plain:
                return;
            case WireDirectiveKind::Expression:
                if (!$this->policy->allowsAlpine()) {
                    throw $this->deny(ForbiddenLivewireDirectiveException::for('wire:'.$base, 'evaluates JavaScript; enable with allowAlpine()'));
                }

                return;
            case WireDirectiveKind::Model:
                if ($dynamic || $value === null || preg_match('/\A\s*([A-Za-z_]\w*(?:\.\w+)*)\s*\z/', $value, $match) !== 1) {
                    throw $this->deny(ForbiddenLivewireDirectiveException::for('wire:'.$base, 'model must be a static property path'));
                }
                $this->assertModel($match[1]);

                return;
            default:
                if ($dynamic) {
                    throw $this->deny(ForbiddenLivewireDirectiveException::for('wire:'.$base, 'dynamic action'));
                }
                if ($value === null || trim($value) === '') {
                    if ($root === 'poll') {
                        $this->assertAction('$refresh');
                    }
                    return;
                }
                $this->checkActionExpression($value);
        }
    }

    public function checkActionExpression(string $expression): void
    {
        $pattern = '/\A\s*(\$?[A-Za-z_][A-Za-z0-9_]*)\s*(?:\(\s*((?:'.self::LITERAL.'\s*(?:,\s*'.self::LITERAL.'\s*)*)?)\))?\s*\z/s';
        if (preg_match($pattern, $expression, $match) !== 1) {
            throw $this->deny(ForbiddenLivewireActionException::for(self::clip($expression), 'only static calls with literal arguments are allowed'));
        }
        $this->assertCall($match[1], isset($match[2]) && trim($match[2]) !== '' ? self::literals($match[2]) : []);
    }

    /**
     * Server-side check of an incoming Livewire call.
     *
     * @param array<int, mixed> $parameters
     */
    public function assertCall(string $method, array $parameters): void
    {
        if (in_array($method, self::MAGIC_MODEL, true)) {
            $property = $parameters[0] ?? null;
            if (! is_string($property)) {
                throw $this->deny(ForbiddenLivewireActionException::for($method));
            }
            $this->assertModel($property);
            return;
        }

        if ($method === '__dispatch' || in_array($method, self::MAGIC_EVENT, true)) {
            $event = $method === '$dispatchTo' || $method === '$emitTo' ? ($parameters[1] ?? null) : ($parameters[0] ?? null);
            if (!is_string($event) || !$this->policy->allowsLivewireEvent($event)) {
                throw $this->deny(ForbiddenLivewireActionException::for('event '.(is_string($event) ? self::clip($event) : '?')));
            }
            return;
        }

        if (in_array($method, ['_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload'], true)) {
            $property = $parameters[0] ?? null;
            if (!is_string($property)) {
                throw $this->deny(ForbiddenLivewireActionException::for($method));
            }
            $this->assertModel($property);
            return;
        }

        if ($method === '__lazyLoad' || $method === '__lazyLoadIsland') {
            return;
        }
        $this->assertAction($method);
    }

    public function assertAction(string $action): void
    {
        if (!$this->policy->allowsLivewireAction($action)) {
            throw $this->deny(ForbiddenLivewireActionException::for(self::clip($action)));
        }
    }

    public function assertModel(string $path): void
    {
        if (!$this->policy->allowsLivewireModel($path)) {
            throw $this->deny(ForbiddenLivewireActionException::for('model '.self::clip($path)));
        }
    }

    public function assertComponent(string $component): void
    {
        if (!$this->policy->allowsLivewireComponent($component)) {
            throw $this->deny(ForbiddenLivewireDirectiveException::for('component '.self::clip($component)));
        }
        if (!$this->adapter->isInstalled()) {
            throw InvalidSandboxTemplateException::syntax('Livewire is not installed');
        }
    }

    public function checkAttributeString(string $html): void
    {
        preg_match_all('/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $match[2] ?? '';
            $value = $value !== '' ? $value : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? null));
            $this->checkAttribute(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        }
    }

    /** Validates markup produced at runtime ({!! !!}): attributes and tags are checked exactly like the template's own markup, and the fragment must not end inside a tag. */
    public function checkHtml(string $html): void
    {
        (new HtmlContextTracker(
            fn (string $name, ?string $value, bool $dynamic, int $line) => $this->checkAttribute($name, $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $dynamic),
            fn (string $tag, bool $dynamic, int $line) => $this->checkTag($tag),
        ))->feed($html, 1)->finish('raw output');
    }

    /** @return list<mixed> */
    private static function literals(string $arguments): array
    {
        preg_match_all('/'.self::LITERAL.'/', $arguments, $matches);
        $values = [];
        foreach ($matches[0] as $literal) {
            $values[] = match (true) {
                $literal === 'true' => true,
                $literal === 'false' => false,
                $literal === 'null' => null,
                $literal[0] === '\'' || $literal[0] === '"' => stripcslashes(substr($literal, 1, -1)),
                str_contains($literal, '.') => (float) $literal,
                default => (int) $literal,
            };
        }

        return $values;
    }

    private static function clip(string $value): string
    {
        $value = preg_replace('/[^\w\-\.:\$@]/', '?', $value) ?? '';

        return strlen($value) > 60 ? substr($value, 0, 60).'...' : $value;
    }
}
