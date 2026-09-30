<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\Deployment\Region;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class DenyRulesTest extends TestCase
{
    private function optionsSandbox(): Sandbox
    {
        return $this->sandbox()->allowClassNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options');
    }

    private function assertDenied(Sandbox $sandbox, string $template, array $data = [], string $exception = SecurityViolationException::class): void
    {
        try {
            $sandbox->render($template, $data);
        } catch (SecurityViolationException $violation) {
            $this->assertInstanceOf($exception, $violation, $template);

            return;
        }

        $this->fail('Expected '.$template.' to be denied.');
    }

    public function testDenyClassInsideAnAllowedNamespace(): void
    {
        $sandbox = $this->optionsSandbox()->denyClass(DeploymentStatus::class);
        $status = DeploymentStatus::Running;

        $this->assertDenied($sandbox, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->value }}');
        $this->assertDenied($sandbox, '{{ count(\SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::cases()) }}');
        $this->assertDenied($sandbox, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::options()["running"] }}');
        $this->assertDenied($sandbox, '{{ $s->label() }}', ['s' => $status]);
        $this->assertDenied($sandbox, '{{ $s->value }}', ['s' => $status]);

        // Other classes of the namespace stay available.
        $this->assertSame('eu', $sandbox->render('{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\Deployment\Region::Eu->value }}'));
    }

    public function testDenyClassNamespaceCarvesOutASubNamespace(): void
    {
        $sandbox = $this->optionsSandbox()->denyClassNamespace('SytxLabs/BladeSandbox/Tests/Fixtures/App/Options/Deployment');

        $this->assertSame('Running', $sandbox->render('{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->label() }}'));
        $this->assertDenied($sandbox, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\Deployment\Region::Eu->value }}');
        $this->assertDenied($sandbox, '{{ $r->name }}', ['r' => Region::Us]);
    }

    public function testDenyMembers(): void
    {
        $sandbox = $this->optionsSandbox()
            ->denyMethod(DeploymentStatus::class, 'color')
            ->denyStaticMethod(DeploymentStatus::class, 'options')
            ->denyClassConstant(DeploymentStatus::class, 'Failed')
            ->denyProperty(Region::class, 'name');

        $this->assertSame('Running', $sandbox->render('{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->label() }}'));
        $this->assertDenied($sandbox, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->color() }}', [], ForbiddenMethodException::class);
        $this->assertDenied($sandbox, '{{ count(\SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::options()) }}', [], ForbiddenMethodException::class);
        $this->assertDenied($sandbox, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Failed->value }}');
        $this->assertSame('us', $sandbox->render('{{ $r->value }}', ['r' => Region::Us]));
        $this->assertDenied($sandbox, '{{ $r->name }}', ['r' => Region::Us]);
    }

    public function testDenyWinsOverExplicitAndWildcardAllows(): void
    {
        $object = new ArrayObject([1, 2]);

        $sandbox = $this->sandbox()->allowMethod(ArrayObject::class, '*')->denyMethod(ArrayObject::class, 'count');
        $this->assertSame('0', $sandbox->render('{{ $o->getFlags() }}', ['o' => $object]));
        $this->assertDenied($sandbox, '{{ $o->count() }}', ['o' => $object], ForbiddenMethodException::class);

        $explicit = $this->sandbox()->allowMethod(ArrayObject::class, 'count')->denyMethod(ArrayObject::class, '*');
        $this->assertDenied($explicit, '{{ $o->count() }}', ['o' => $object], ForbiddenMethodException::class);
    }

    public function testDenyClassOverridesValueObjectDefaultsAndIsInherited(): void
    {
        $items = collect(['a', 'b']);
        $this->assertSame('2', $this->sandbox()->render('{{ $items->count() }}', ['items' => $items]));

        // Denying the interface affects every implementor (Collection implements Enumerable).
        $sandbox = $this->sandbox()->denyClass(Enumerable::class);
        $this->assertDenied($sandbox, '{{ $items->count() }}', ['items' => $items]);
        $this->assertDenied($sandbox, '@foreach($items as $item){{ $item }}@endforeach', ['items' => $items]);
    }

    public function testDenyMacro(): void
    {
        Collection::macro('sandboxDenyShout', fn (): string => 'LOUD');
        $sandbox = $this->sandbox()->allowMacro(Collection::class)->denyMethod(Collection::class, 'sandboxDenyShout');

        $this->assertDenied($sandbox, '{{ $items->sandboxDenyShout() }}', ['items' => collect()], ForbiddenMethodException::class);
    }

    public function testDenyDto(): void
    {
        $dto = new TestDTO(['name' => 'api', 'status' => 'ok']);
        $sandbox = $this->sandbox()->allowDtoNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\DTO');
        $this->assertSame('api', $sandbox->render('{{ $dto->name }}', ['dto' => $dto]));

        $this->assertDenied($sandbox->denyClass(TestDTO::class), '{{ $dto->name }}', ['dto' => $dto]);
    }

    public function testDenyFunctionsAndStaticMethodsOutOfHelperSets(): void
    {
        $sandbox = $this->sandbox()->allowSafeHelpers()->denyFunction('date', 'STRTOUPPER')->denyStaticMethod(Str::class, 'slug');

        $this->assertSame('abc', $sandbox->render('{{ strtolower("ABC") }}'));
        $this->assertDenied($sandbox, '{{ date("Y") }}', [], ForbiddenFunctionException::class);
        $this->assertDenied($sandbox, '{{ strtoupper("x") }}', [], ForbiddenFunctionException::class);
        $this->assertDenied($sandbox, '{{ \Illuminate\Support\Str::slug("A B") }}', [], ForbiddenMethodException::class);
        $this->assertSame('A B', $sandbox->render('{{ \Illuminate\Support\Str::title("a b") }}'));
    }

    public function testDenyConstant(): void
    {
        $sandbox = $this->sandbox()->allowConstant('PHP_EOL')->denyConstant('PHP_EOL');

        $this->assertDenied($sandbox, '{{ PHP_EOL }}');
    }

    public function testDenyViewsInsideAnAllowedNamespace(): void
    {
        $sandbox = $this->sandbox()->allowViewNamespace('deployer')->denyView('deployer::partials.**', 'deployer::components.button');
        $data = ['deployment' => (object) ['name' => 'api', 'status' => 'ok']];

        $this->assertDenied($sandbox, "@include('deployer::partials.header')", $data, ForbiddenViewException::class);
        $this->assertTrue($sandbox->validate("@include('deployer::partials.header')")->fails());

        try {
            $sandbox->renderView('deployer::partials.header', $data);
            $this->fail('A denied view must not render.');
        } catch (ForbiddenViewException) {
        }

        // Component views are denied even when the component itself is allowed.
        $this->assertDenied($sandbox->allowComponent('deployer::button'), '<x-deployer::button>Go</x-deployer::button>', [], ForbiddenViewException::class);
        $this->assertStringContainsString('card', $sandbox->allowComponent('deployer::card')->render('<x-deployer::card title="t">b</x-deployer::card>'));
    }

    public function testOrderDoesNotMatterAndFingerprintChanges(): void
    {
        $before = $this->sandbox()->denyClass(DeploymentStatus::class)->allowClassNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options');
        $this->assertDenied($before, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->value }}');

        $this->assertNotSame($this->optionsSandbox()->policy()->fingerprint(), $this->optionsSandbox()->denyClass(DeploymentStatus::class)->policy()->fingerprint());
    }

    public function testConfigKeys(): void
    {
        $sandbox = PolicyConfiguration::apply($this->sandbox(), [
            'class_namespaces' => ['SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options'],
            'helpers' => ['safe'],
            'deny_classes' => [DeploymentStatus::class],
            'deny_functions' => ['date'],
            'deny_static_methods' => [Str::class => ['slug']],
            'deny_properties' => [Region::class => 'name'],
            'deny_views' => ['deployer::**'],
        ]);

        $this->assertDenied($sandbox, '{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->value }}');
        $this->assertDenied($sandbox, '{{ date("Y") }}');
        $this->assertDenied($sandbox, '{{ \Illuminate\Support\Str::slug("x") }}');
        $this->assertDenied($sandbox, '{{ $r->name }}', ['r' => Region::Eu]);
        $this->assertSame('eu', $sandbox->render('{{ $r->value }}', ['r' => Region::Eu]));
    }

    public function testDenyingAnUnloadedClassDoesNotAutoloadIt(): void
    {
        $class = 'SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\NeverLoaded'.bin2hex(random_bytes(4));
        $autoloaded = false;
        $loader = static function (string $name) use ($class, &$autoloaded): void {
            if ($name === $class) {
                $autoloaded = true;
            }
        };
        spl_autoload_register($loader);

        try {
            $sandbox = $this->optionsSandbox()->denyClass($class);
            $this->assertDenied($sandbox, '{{ \\'.$class.'::X }}');
            $this->assertFalse($autoloaded);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testGlobalNamespaceCannotBeDenied(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sandbox()->denyClassNamespace('\\');
    }
}
