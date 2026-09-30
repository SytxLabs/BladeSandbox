<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\Deployment\Region;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class ClassNamespaceTest extends TestCase
{
    private function enumSandbox(): Sandbox
    {
        return $this->sandbox()->allowClassNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options');
    }

    public function testEnumCasesConstantsAndMethods(): void
    {
        $sandbox = $this->enumSandbox();

        $this->assertSame('Running', $sandbox->render('{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->label() }}'));
        $this->assertSame('failed', $sandbox->render('{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Failed->value }}'));
        $this->assertSame('running', $sandbox->render('{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::DEFAULT->value }}'));
        $this->assertSame('red', $sandbox->render('{{ $status->color() }}', ['status' => DeploymentStatus::Failed]));
    }

    public function testStaticEnumMethods(): void
    {
        $sandbox = $this->enumSandbox();

        $this->assertSame('running,failed,', $sandbox->render('@foreach(SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::cases() as $case){{ $case->value }},@endforeach'));
        $this->assertSame('Failed', $sandbox->render("{{ SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\DeploymentStatus::from('failed')->label() }}"));
        $this->assertSame('none', $sandbox->render("{{ SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\DeploymentStatus::tryFrom('x')?->label() ?? 'none' }}"));
        $this->assertSame('running=Running;failed=Failed;', $sandbox->render('@foreach(SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::options() as $v => $l){{ $v }}={{ $l }};@endforeach'));
    }

    public function testSubNamespacesAndNotations(): void
    {
        foreach (['SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options', 'SytxLabs/BladeSandbox/Tests/Fixtures/App/Options', '\\SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\', 'sytxlabs\\bladesandbox\\tests\\fixtures\\app\\options'] as $namespace) {
            $this->assertSame('eu', $this->sandbox()->allowClassNamespace($namespace)->render('{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\Deployment\Region::Eu->value }}'));
        }
        $this->assertSame('us', $this->enumSandbox()->render('{{ $r->value }}', ['r' => Region::Us]));
    }

    public function testClassesOutsideTheNamespaceStayDenied(): void
    {
        $sandbox = $this->enumSandbox();

        foreach ([
            '{{ SytxLabs\BladeSandbox\Tests\Fixtures\Models\User::query() }}' => ForbiddenMethodException::class,
            '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\OptionsX\Foo::bar() }}' => ForbiddenMethodException::class,
            '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO::fromLivewire([]) }}' => ForbiddenMethodException::class,
            '{{ SytxLabs\BladeSandbox\Tests\Fixtures\Models\User::TABLE }}' => ForbiddenPropertyException::class,
        ] as $template => $exception) {
            $this->assertViolation($sandbox, $template, $exception);
        }
    }

    public function testDefaultDeniesEnums(): void
    {
        $this->assertViolation($this->sandbox(), '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::cases() }}', ForbiddenMethodException::class);
        $this->assertViolation($this->sandbox(), '{{ $s->label() }}', ForbiddenMethodException::class, ['s' => DeploymentStatus::Running]);
    }

    public function testMagicPrivateAndDynamicStaticCallsAreNeverAvailable(): void
    {
        $sandbox = $this->enumSandbox();
        $GLOBALS['__blade_sandbox_side_effect'] = false;

        $this->assertViolation($sandbox, '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\Magic::anything() }}', ForbiddenMethodException::class);
        $this->assertViolation($sandbox, '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::secret() }}', ForbiddenMethodException::class);
        $this->assertViolation($sandbox, '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::__callStatic("x", []) }}', ForbiddenMethodException::class);
        $this->assertViolation($sandbox, '{{ $class::cases() }}', InvalidSandboxTemplateException::class, ['class' => DeploymentStatus::class]);
        $this->assertViolation($sandbox, '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::{$m}() }}', InvalidSandboxTemplateException::class, ['m' => 'cases']);
        $this->assertViolation($sandbox, '{{ static::cases() }}', InvalidSandboxTemplateException::class);
        $this->assertViolation($sandbox, '{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::cases(...) }}', InvalidSandboxTemplateException::class);
        $this->assertFalse($GLOBALS['__blade_sandbox_side_effect']);
    }

    public function testFacadesAreNeverAvailableEvenWhenTheirNamespaceIsAllowed(): void
    {
        Cache::put('secret', 'value');
        $sandbox = $this->sandbox()->allowClassNamespace('Illuminate\\Support\\Facades')
            ->allowStaticMethod(Cache::class, 'get');

        $this->assertViolation($sandbox, "{{ Illuminate\\Support\\Facades\\Cache::get('secret') }}", ForbiddenMethodException::class);
    }

    public function testSingleStaticMethodsAndConfig(): void
    {
        $this->assertSame('running,failed,', $this->sandbox()->allowStaticMethod(DeploymentStatus::class, 'cases')->allowProperty(DeploymentStatus::class, 'value')
            ->render('@foreach(SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::cases() as $c){{ $c->value }},@endforeach'));
        $this->assertViolation($this->sandbox()->allowStaticMethod(DeploymentStatus::class, 'cases'), '{{ SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\DeploymentStatus::options() }}', ForbiddenMethodException::class);

        config()->set('blade-sandbox.policies.enums', ['class_namespaces' => ['SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options']]);
        $manager = new SandboxManager($this->app, config('blade-sandbox'), $this->app->make(LivewireAdapter::class));
        $this->assertSame('Running', $manager->policy('enums')->render('{{ SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running->label() }}'));
    }

    public function testStaticCallArgumentsAreChecked(): void
    {
        $this->assertViolation($this->enumSandbox(), '{{ SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\DeploymentStatus::from($fn) }}', SecurityViolationException::class, ['fn' => static fn () => 'running']);
    }

    /**
     * @param class-string<Throwable> $exception
     * @param array<string, mixed> $data
     */
    private function assertViolation(Sandbox $sandbox, string $template, string $exception, array $data = []): void
    {
        try {
            $sandbox->render($template, $data);
            $this->fail('Expected '.$exception.' for '.$template);
        } catch (SecurityViolationException $violation) {
            $this->assertInstanceOf($exception, $violation, $template.' -> '.$violation->getMessage());
        }
    }
}
