<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Views;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use stdClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenComponentException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Tests\Fixtures\Components\Alert;
use SytxLabs\BladeSandbox\Tests\Fixtures\Components\NeedsService;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class ComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Blade::component('alert', Alert::class);
        Blade::component('needs-service', NeedsService::class);
    }

    public function testExternalAnonymousComponent(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')
            ->render('<x-deployer::button type="submit" class="primary" data-id="{{ $id }}">Deploy {{ $name }}</x-deployer::button>', ['id' => 5, 'name' => '<b>api</b>']);

        $this->assertSame('<button type="submit" class="btn primary" data-id="5">Deploy &lt;b&gt;api&lt;/b&gt;</button>', trim($html));
    }

    public function testSelfClosingComponentAndDefaultProps(): void
    {
        $html = $this->sandbox()->allowComponent('deployer::button')->render('<x-deployer::button />');

        $this->assertSame('<button type="button" class="btn"></button>', trim($html));
    }

    public function testBoundAttributesAndNamedSlots(): void
    {
        $template = <<<'BLADE'
<x-deployer::card :title="$deployment['name']">
    Body
    <x-slot:footer>Footer {{ $deployment['status'] }}</x-slot>
</x-deployer::card>
BLADE;
        $html = $this->sandbox()->allowComponent('deployer::card')->render($template, ['deployment' => ['name' => 'api', 'status' => 'ok']]);

        $this->assertSame('<div class="card"><h2>api</h2>Body<footer>Footer ok</footer></div>', trim($html));

        $legacy = '<x-deployer::card title="x"><x-slot name="footer">F</x-slot>B</x-deployer::card>';
        $this->assertSame('<div class="card"><h2>x</h2>B<footer>F</footer></div>', trim($this->sandbox()->allowComponent('deployer::card')->render($legacy)));
    }

    public function testComponentsMustBeAllowed(): void
    {
        $this->expectException(ForbiddenComponentException::class);

        $this->sandbox()->render('<x-deployer::button />');
    }

    public function testAdminComponentEscape(): void
    {
        $this->expectException(ForbiddenComponentException::class);

        $this->sandbox()->allowComponent('deployer::*')->render('<x-admin.secret />');
    }

    public function testDynamicComponentNamesAreCheckedAtRuntime(): void
    {
        $sandbox = $this->sandbox()->allowComponent('deployer::button');

        $this->assertStringContainsString('<button', $sandbox->render('<x-dynamic-component :component="$c" />', ['c' => 'deployer::button']));

        $this->expectException(ForbiddenComponentException::class);
        $sandbox->render('<x-dynamic-component :component="$c" />', ['c' => 'admin.secret']);
    }

    public function testClassComponentIsBuiltWithoutTheContainer(): void
    {
        $html = $this->sandbox()->allowComponent('alert')->render('<x-alert type="error" :message="$m">!</x-alert>', ['m' => 'Failed']);

        $this->assertSame('<div class="alert alert-error">Failed !</div>', trim($html));
    }

    public function testClassComponentMethodsAreNotExposed(): void
    {
        $this->expectException(InvalidSandboxTemplateException::class);

        // Laravel exposes public component methods as invokable variables; the sandbox does not.
        $this->sandbox()->allowComponent('alert')->allowView('deployer::components.alert')
            ->render('{{ $secretMethod() }}', ['secretMethod' => 'x']);
    }

    public function testConstructorServiceInjectionIsNotAvailable(): void
    {
        try {
            $this->sandbox()->allowComponent('needs-service')->render('<x-needs-service />');
            $this->fail('Container injection must not happen implicitly');
        } catch (ForbiddenComponentException $exception) {
            $this->assertStringContainsString('service injection', $exception->getMessage());
        }

        // An explicit factory decides what the component receives.
        $html = $this->sandbox()
            ->allowComponent('needs-service', static fn (array $attributes) => new NeedsService(new Filesystem()))
            ->render('<x-needs-service label="ok" />');
        $this->assertSame('files: none', trim($html));
    }

    public function testComponentViewsAreSandboxedToo(): void
    {
        $directory = sys_get_temp_dir().'/blade-sandbox-components-'.getmypid();
        @mkdir($directory.'/components', 0777, true);
        file_put_contents($directory.'/components/evil.blade.php', "{{ system('id') }}");
        View::addNamespace('evil', $directory);

        $this->expectException(ForbiddenFunctionException::class);
        $this->sandbox()->allowComponent('evil::evil')->render('<x-evil::evil />');
    }

    public function testAttributeBagApiIsLimited(): void
    {
        $directory = sys_get_temp_dir().'/blade-sandbox-bag-'.getmypid();
        @mkdir($directory.'/components', 0777, true);
        file_put_contents($directory.'/components/bag.blade.php', "{{ \$attributes->filter('system') }}");
        View::addNamespace('bag', $directory);

        $this->expectException(ForbiddenMethodException::class);
        $this->sandbox()->allowComponent('bag::bag')->render('<x-bag::bag a="id" />');
    }

    public function testLegacyComponentDirectiveUsesTheViewPolicy(): void
    {
        $html = $this->sandbox()->allowView('deployer::components.card')
            ->render("@component('deployer::components.card', ['title' => 'T']) Body @slot('footer') F @endslot @endcomponent");
        $this->assertSame('<div class="card"><h2>T</h2>Body<footer>F</footer></div>', trim($html));

        $this->expectException(ForbiddenViewException::class);
        $this->sandbox()->render("@component('deployer::admin.secret') x @endcomponent");
    }

    public function testFactoryMatchedByWildcardPatternIsUsed(): void
    {
        $html = $this->sandbox()
            ->allowComponent('needs-*', static fn (array $attributes) => new NeedsService(new Filesystem()))
            ->render('<x-needs-service />');

        $this->assertSame('files: none', trim($html));
    }

    public function testComponentThatDeclinesToRenderProducesNothing(): void
    {
        Blade::component('should-not-render', ShouldNotRenderComponent::class);

        $this->assertSame('', $this->sandbox()->allowComponent('should-not-render')->render('<x-should-not-render />'));
    }

    public function testComponentRenderingHtmlableMarkupDirectly(): void
    {
        Blade::component('htmlable-render', HtmlableRenderComponent::class);

        $this->assertSame('<b>trusted</b>', $this->sandbox()->allowComponent('htmlable-render')->render('<x-htmlable-render />'));
    }

    public function testComponentRenderingAClosureIsRejected(): void
    {
        Blade::component('closure-render', ClosureRenderComponent::class);

        $this->expectException(ForbiddenComponentException::class);
        $this->sandbox()->allowComponent('closure-render')->render('<x-closure-render />');
    }

    public function testComponentRenderingAnUnsupportedValueIsRejected(): void
    {
        Blade::component('unsupported-render', UnsupportedRenderComponent::class);

        $this->expectException(SandboxException::class);
        $this->sandbox()->allowComponent('unsupported-render')->render('<x-unsupported-render />');
    }

    public function testFactoryMustReturnAComponentInstance(): void
    {
        try {
            $this->sandbox()->allowComponent('bad-factory', static fn (array $attributes) => new stdClass())->render('<x-bad-factory />');
            $this->fail('a factory that returns a non-Component must be rejected');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('must return an Illuminate\View\Component', $exception->getMessage());
        }
    }

    public function testClassComponentTargetMustExtendComponent(): void
    {
        Blade::component('not-a-component', NotAComponentClass::class);

        $this->expectException(ForbiddenComponentException::class);
        $this->sandbox()->allowComponent('not-a-component')->render('<x-not-a-component />');
    }

    public function testAbstractComponentClassCannotBeInstantiated(): void
    {
        Blade::component('abstract-component', AbstractTestComponent::class);

        try {
            $this->sandbox()->allowComponent('abstract-component')->render('<x-abstract-component />');
            $this->fail('an abstract component class must be rejected');
        } catch (ForbiddenComponentException $exception) {
            $this->assertStringContainsString('cannot be instantiated', $exception->getMessage());
        }
    }

    public function testConstructorDefaultAndNullableParametersAreFilledIn(): void
    {
        Blade::component('defaults-component', DefaultsComponent::class);

        $this->assertSame('def|', trim($this->sandbox()->allowComponent('defaults-component')->render('<x-defaults-component />')));
    }

    public function testInvalidAttributeTypeCausesAForbiddenComponentException(): void
    {
        Blade::component('typed-component', TypedComponent::class);

        try {
            $this->sandbox()->allowComponent('typed-component')->render('<x-typed-component count="not-a-number" />');
            $this->fail('an attribute that cannot be cast to the constructor type must be rejected');
        } catch (ForbiddenComponentException $exception) {
            $this->assertStringContainsString('Invalid attribute types', $exception->getMessage());
        }
    }

    public function testReservedPropNamesAreRejected(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Invalid component property name.');
        $this->sandbox()->allowDirective('props')->render("@props(['attributes'])");
    }
}

final class ShouldNotRenderComponent extends Component
{
    public function shouldRender(): bool
    {
        return false;
    }

    public function render()
    {
        return 'never rendered';
    }
}

final class HtmlableRenderComponent extends Component
{
    public function render()
    {
        return new HtmlString('<b>trusted</b>');
    }
}

final class ClosureRenderComponent extends Component
{
    public function render()
    {
        return static fn (): string => 'x';
    }
}

final class UnsupportedRenderComponent extends Component
{
    public function render()
    {
        return 42;
    }
}

class NotAComponentClass
{
}

abstract class AbstractTestComponent extends Component
{
}

final class DefaultsComponent extends Component
{
    public function __construct(public string $withDefault = 'def', public ?string $nullable = null)
    {
    }

    public function render()
    {
        return '{{ $withDefault }}|{{ $nullable }}';
    }
}

final class TypedComponent extends Component
{
    public function __construct(public int $count)
    {
    }

    public function render()
    {
        return '{{ $count }}';
    }
}
