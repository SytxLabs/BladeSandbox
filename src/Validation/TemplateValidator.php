<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Validation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Compatibility\ComponentResolver;
use SytxLabs\BladeSandbox\Compiler\SandboxBladeCompiler;
use SytxLabs\BladeSandbox\Compiler\TemplateReferences;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;
use SytxLabs\BladeSandbox\Support\Patterns;
use SytxLabs\BladeSandbox\Templates\TemplateSources;

/**
 * Checks templates against a policy WITHOUT rendering them (e.g. to reject a CMS / customer upload before it is stored, or in CI).
 *
 * Reported:
 *  - everything the sandbox compiler rejects (forbidden constructs and directives, raw PHP, syntax errors, wire:* / JavaScript attributes, HTML context violations) - the compiler stops at the first one;
 *  - literally named functions, static methods, constants, class constants, views, components and Livewire components that the policy does not allow;
 *  - recursively: views / layouts / components the template includes by literal name.
 *
 * Not decidable statically (checked at render time only): methods and properties of runtime values (`$user->delete()`), dynamic view / component names and anything depending on the data.
 */
final class TemplateValidator
{
    private const MAX_DEPTH = 32;

    public function __construct(private readonly SandboxBladeCompiler $compiler, private readonly TemplateSources $sources, private readonly ComponentResolver $components, private readonly Filesystem $files)
    {
    }

    public function validateSource(string $source, SecurityPolicy $policy, string $name = 'inline'): ValidationResult
    {
        $visited = [];

        return $this->source($source, $name, $policy, $visited, 0);
    }

    public function validateFile(string $path, SecurityPolicy $policy, ?string $name = null): ValidationResult
    {
        if (!$this->files->isFile($path)) {
            return new ValidationResult([new Violation($name ?? basename($path), 'file', basename($path), 'Template file does not exist.')]);
        }
        $visited = [];

        return $this->source($this->files->get($path), $name ?? $path, $policy, $visited, 0);
    }

    public function validateView(string $view, SecurityPolicy $policy): ValidationResult
    {
        $visited = [];

        return $this->view($view, $policy, $visited, 0, 0, true);
    }

    /** @param array<string, true> $visited */
    private function view(string $view, SecurityPolicy $policy, array &$visited, int $depth, int $line, bool $checkPolicy, string $from = ''): ValidationResult
    {
        if ($checkPolicy && !$policy->allowsView($view)) {
            return new ValidationResult([new Violation($from !== '' ? $from : $view, 'view', $view, 'View '.$view.' is not allowed in the sandbox.', $from !== '' ? $line : null)]);
        }

        $key = 'view:'.$view;
        if (isset($visited[$key]) || $depth > self::MAX_DEPTH) {
            return new ValidationResult();
        }
        $visited[$key] = true;
        if (!$this->sources->exists($view)) {
            return new ValidationResult([new Violation($from !== '' ? $from : $view, 'view', $view, 'View '.$view.' does not exist.', $from !== '' ? $line : null)]);
        }
        return $this->source($this->sources->source($view), $view, $policy, $visited, $depth);
    }

    /** @param array<string, true> $visited */
    private function source(string $source, string $name, SecurityPolicy $policy, array &$visited, int $depth): ValidationResult
    {
        $references = new TemplateReferences();

        try {
            $this->compiler->compile($source, $policy, new AuditLogger(), $name, $references);
        } catch (SecurityViolationException $violation) {
            return new ValidationResult([Violation::fromException($violation, $name)], [$name]);
        }

        $result = new ValidationResult([], [$name]);
        $violations = [];

        foreach ($references->all() as $reference) {
            $line = $reference['line'];
            $subject = $reference['subject'];

            switch ($reference['kind']) {
                case TemplateReferences::FUNCTION:
                    if (!$policy->allowsFunction($subject)) {
                        $violations[] = new Violation($name, 'function', $subject.'()', 'Function '.$subject.'() is not allowed in the sandbox.', $line);
                    }
                    break;
                case TemplateReferences::STATIC_METHOD:
                    $call = $subject.'::'.$reference['member'].'()';
                    if ((!$policy->allowsStaticMethod($subject, $reference['member']) && !$policy->allowsMacro($subject, $reference['member'])) || (class_exists($subject) && is_subclass_of($subject, Facade::class))) {
                        $violations[] = new Violation($name, 'method', $call, 'Method '.$call.' is not allowed in the sandbox.', $line);
                    }
                    break;
                case TemplateReferences::ROUTE:
                    if ($policy->allowsFunction('route') && !$policy->allowsRoute($subject)) {
                        $violations[] = new Violation($name, 'route', $subject, 'Route '.$subject.' is not allowed in the sandbox.', $line);
                    }
                    break;
                case TemplateReferences::TRANSLATION:
                    if (!$policy->allowsTranslation($subject)) {
                        $violations[] = new Violation($name, 'translation', $subject, 'Translation key '.$subject.' is not allowed in the sandbox.', $line);
                    }
                    break;
                case TemplateReferences::CONSTANT:
                    if (!$policy->allowsConstant($subject)) {
                        $violations[] = new Violation($name, 'property', 'constant '.$subject, 'Constant '.$subject.' is not allowed in the sandbox.', $line);
                    }
                    break;
                case TemplateReferences::CLASS_CONSTANT:
                    $constant = $subject.'::'.$reference['member'];
                    if (!$policy->allowsClassConstant($subject, $reference['member'])) {
                        $violations[] = new Violation($name, 'property', $constant, 'Constant '.$constant.' is not allowed in the sandbox.', $line);
                    }
                    break;
                case TemplateReferences::VIEW:
                    $result = $result->merge(Patterns::isValidName($subject) ? $this->view($subject, $policy, $visited, $depth + 1, $line, true, $name) : new ValidationResult([new Violation($name, 'view', $subject, 'Invalid view name.', $line)]));
                    break;
                case TemplateReferences::COMPONENT:
                    $result = $result->merge($this->component($subject, $name, $line, $policy, $visited, $depth));
                    break;
                case TemplateReferences::LIVEWIRE_COMPONENT:
                    if (!$policy->allowsLivewireComponent($subject)) {
                        $violations[] = new Violation($name, 'livewire-directive', 'component '.$subject, 'Livewire component '.$subject.' is not allowed in the sandbox.', $line);
                    }
                    break;
            }
        }

        return (new ValidationResult($violations, [$name]))->merge($result);
    }

    /** @param array<string, true> $visited */
    private function component(string $component, string $from, int $line, SecurityPolicy $policy, array &$visited, int $depth): ValidationResult
    {
        if (!$policy->allowsComponent($component)) {
            return new ValidationResult([new Violation($from, 'component', $component, 'Component '.$component.' is not allowed in the sandbox.', $line)]);
        }
        if ($policy->components()->factory($component) !== null) {
            return new ValidationResult();
        }
        try {
            $resolved = $this->components->resolve($component);
        } catch (SandboxException) {
            return new ValidationResult([new Violation($from, 'component', $component, 'Component '.$component.' does not exist.', $line)]);
        }
        return $resolved['type'] === 'view' ? $this->view($resolved['target'], $policy, $visited, $depth + 1, $line, false, $from) : new ValidationResult();
    }
}
