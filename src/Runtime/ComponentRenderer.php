<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use SytxLabs\BladeSandbox\Compatibility\ComponentResolver;
use SytxLabs\BladeSandbox\Compiler\SandboxRewriteVisitor;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenComponentException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Support\Patterns;
use Throwable;
use TypeError;

/**
 * Renders allowed Blade components inside the sandbox.
 *
 *  - Anonymous components: their view is rendered by the sandbox (the component allowance implies
 *    its own view; views it includes are checked against the policy again).
 *  - Class components: instantiated WITHOUT the service container. Constructor arguments come only
 *    from the tag's attributes and parameter defaults; a parameter that would need container
 *    injection makes the component unusable unless an explicit factory was registered with
 *    allowComponent($name, $factory). Its view is rendered by the sandbox with the component's public
 *    properties as data; component methods are not exposed.
 */
final class ComponentRenderer
{
    /** Public properties of Illuminate\View\Component that are framework internals. */
    private const INTERNAL_PROPERTIES = ['componentName', 'attributes', 'except'];

    public function __construct(private readonly ComponentResolver $resolver)
    {
    }

    public function render(ComponentFrame $frame, ComponentSlot $slot, SandboxRuntime $runtime): string
    {
        if ($frame->type === 'view') {
            return $this->renderView($frame->name, array_merge($frame->attributes, $frame->slots, ['slot' => $slot]), $runtime);
        }

        $factory = $runtime->policy()->components()->factory($frame->name);
        $resolved = $factory === null ? $this->resolver->resolve($frame->name) : ['type' => 'class', 'target' => ''];

        if ($resolved['type'] === 'view') {
            $data = [];
            foreach ($frame->attributes as $name => $value) {
                $data[SandboxRuntime::camel((string) $name)] = $value;
            }

            return $this->renderView($resolved['target'], array_merge($data, $frame->slots, ['attributes' => new ComponentAttributeBag($frame->bagAttributes), 'slot' => $slot, 'componentName' => $frame->name]), $runtime);
        }

        [$component, $remaining] = $factory !== null ? $this->fromFactory($factory, $frame) : $this->instantiate($resolved['target'], $frame);
        $component->withName($frame->name);
        $component->withAttributes(array_intersect_key($frame->bagAttributes, $remaining));

        if (!$component->shouldRender()) {
            return '';
        }

        $data = array_merge($this->publicProperties($component), $frame->slots, ['attributes' => $component->attributes, 'slot' => $slot, 'componentName' => $frame->name]);
        $view = $component->render();

        if ($view instanceof ViewContract) {
            return $this->renderView($view->name(), array_merge(SandboxRuntime::scope($view->getData()), $data), $runtime);
        }
        if ($view instanceof Htmlable) {
            return $view->toHtml();
        }
        if (is_string($view)) {
            return $this->viewExists($view, $runtime) ? $this->renderView($view, $data, $runtime) : $runtime->renderInline($view, $data, 'component:'.$frame->name);
        }
        if ($view instanceof Closure) {
            throw new ForbiddenComponentException('Component '.$frame->name.' renders through a closure, which is not supported in the sandbox.', 'component', $frame->name);
        }

        throw new SandboxException('Component '.$frame->name.' returned an unsupported view.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Throwable
     */
    private function renderView(string $view, array $data, SandboxRuntime $runtime): string
    {
        if ($runtime->policy()->deniesView($view)) {
            $violation = ForbiddenViewException::for($view, 'denied');
            $runtime->audit()->record($violation, $view);

            throw $violation;
        }

        $runtime->context()->componentData[] = $data;

        try {
            return $runtime->renderer()->renderView($view, $data, $runtime);
        } finally {
            array_pop($runtime->context()->componentData);
        }
    }

    private function viewExists(string $view, SandboxRuntime $runtime): bool
    {
        return Patterns::isValidName($view) && $runtime->renderer()->exists($view);
    }

    /**
     * @return array{0: Component, 1: array<string, mixed>}
     */
    private function fromFactory(Closure $factory, ComponentFrame $frame): array
    {
        $component = $factory($frame->attributes);
        if (!$component instanceof Component) {
            throw new SandboxException('The factory of component '.$frame->name.' must return an Illuminate\View\Component.');
        }

        return [$component, $frame->attributes];
    }

    /**
     * @throws ReflectionException
     *
     * @return array{0: Component, 1: array<string, mixed>}
     */
    private function instantiate(string $class, ComponentFrame $frame): array
    {
        if (!is_subclass_of($class, Component::class)) {
            throw new ForbiddenComponentException('Component '.$frame->name.' is not a Blade component class.', 'component', $frame->name);
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ForbiddenComponentException('Component '.$frame->name.' cannot be instantiated.', 'component', $frame->name);
        }

        $remaining = $frame->attributes;
        $arguments = [];
        $constructor = $reflection->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
            $key = array_key_exists($name, $remaining) ? $name : (array_key_exists($kebab, $remaining) ? $kebab : null);

            if ($key !== null) {
                $arguments[$name] = $remaining[$key];
                unset($remaining[$key]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[$name] = null;
            } else {
                $type = $parameter->getType();
                throw new ForbiddenComponentException('Component '.$frame->name.' requires constructor parameter $'.$name.(($type instanceof ReflectionNamedType && !$type->isBuiltin()) ? ' (service injection is not available in the sandbox; register a factory with allowComponent())' : '').'.', 'component', $frame->name);
            }
        }

        try {
            $component = $reflection->newInstanceArgs($arguments);
        } catch (TypeError) {
            throw new ForbiddenComponentException('Invalid attribute types for component '.$frame->name.'.', 'component', $frame->name);
        }

        assert($component instanceof Component);

        return [$component, $remaining];
    }

    /** @return array<string, mixed> */
    private function publicProperties(Component $component): array
    {
        $data = [];
        foreach ((new ReflectionClass($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if ($property->isStatic() || in_array($name, self::INTERNAL_PROPERTIES, true) || SandboxRewriteVisitor::isReservedVariable($name)) {
                continue;
            }
            try {
                if ($property->isInitialized($component)) {
                    $data[$name] = $property->getValue($component);
                }
            } catch (Throwable) {
            }
        }

        return $data;
    }
}
