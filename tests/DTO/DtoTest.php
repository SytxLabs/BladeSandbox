<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\DTO;

use SytxLabs\BladeSandbox\Exceptions\ForbiddenDtoException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenPropertyException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\BaseDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DangerousDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\ProjectDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class DtoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createUsers();
    }

    private function dto(): TestDTO
    {
        return new TestDTO(['name' => 'api', 'status' => 'running']);
    }

    private function allowing(string $class = TestDTO::class): Sandbox
    {
        return $this->sandbox()->allowDto($class);
    }

    public function testDtoPublicApiWithoutMemberAllowlists(): void
    {
        $sandbox = $this->allowing();

        $this->assertSame('api', $sandbox->render('{{ $dto->name }}', ['dto' => $this->dto()]));
        $this->assertSame('running', $sandbox->render('{{ $dto->status }}', ['dto' => $this->dto()]));
        $this->assertSame('api: running', $sandbox->render('{{ $dto->getLabel() }}', ['dto' => $this->dto()]));
        $this->assertSame('api', $sandbox->render("{{ \$dto['name'] }}", ['dto' => $this->dto()]));
    }

    public function testDtoStringableIsEscaped(): void
    {
        $this->assertSame(
            '{&quot;name&quot;:&quot;api&quot;,&quot;status&quot;:&quot;running&quot;,&quot;getLabel&quot;:&quot;api: running&quot;}',
            $this->allowing()->render('{{ $dto }}', ['dto' => $this->dto()]),
        );
    }

    public function testDtoIteration(): void
    {
        $html = $this->allowing()->render('@foreach($dto as $key => $value){{ $key }}: {{ $value }};@endforeach', ['dto' => $this->dto()]);

        $this->assertSame('name: api;status: running;getLabel: api: running;', $html);
    }

    public function testDtoMustBeAllowed(): void
    {
        $this->expectException(ForbiddenPropertyException::class);

        $this->sandbox()->render('{{ $dto->name }}', ['dto' => $this->dto()]);
    }

    public function testBaseClassAllowsAllSubclasses(): void
    {
        $sandbox = $this->sandbox()->allowDto(BaseDTO::class);

        $this->assertSame('api Blade Sandbox', $sandbox->render('{{ $d->name }} {{ $d->getProject()->title }}', ['d' => new DeploymentDTO()]));
    }

    public function testDtoNamespace(): void
    {
        $this->assertSame('api', $this->sandbox()->allowDtoNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\DTO\\')->render('{{ $d->name }}', ['d' => $this->dto()]));
    }

    public function testMagicGetStyleAccessCallsZeroArgumentMethods(): void
    {
        $sandbox = $this->allowing(DeploymentDTO::class);

        $this->assertSame('Label api', $sandbox->render('{{ $d->label }}', ['d' => new DeploymentDTO()]));
        $this->assertSame('api: running', $sandbox->render('{{ $d->getLabel }}', ['d' => new DeploymentDTO()]));
        $this->assertSame('Hello you', $sandbox->render('{{ $d->greet }}', ['d' => new DeploymentDTO()]));
        $this->assertSame('Hello Bob', $sandbox->render("{{ \$d->greet('Bob') }}", ['d' => new DeploymentDTO()]));
    }

    public function testUnknownMembersAreNotDelegatedToMagicGet(): void
    {
        $this->assertDenied('{{ $d->unknown }}', ForbiddenPropertyException::class);
        $this->assertDenied("{{ \$d['unknown'] }}", ForbiddenPropertyException::class);
    }

    public function testPrivateMembersAreNotReachableThroughTheVisibilityBlindBaseDto(): void
    {
        // BaseDTO::__get/offsetGet use property_exists()/method_exists() from class scope: the sandbox must not delegate.
        $this->assertDenied('{{ $d->secret }}', ForbiddenPropertyException::class);
        $this->assertDenied("{{ \$d['secret'] }}", ForbiddenPropertyException::class);
        $this->assertDenied('{{ $d->hiddenMethod }}', ForbiddenPropertyException::class);
        $this->assertDenied('{{ $d->hiddenMethod() }}', ForbiddenMethodException::class);
        $this->assertDenied("{{ \$d->offsetGet('secret') }}", ForbiddenMethodException::class);
        $this->assertDenied("{{ \$d->__get('secret') }}", ForbiddenMethodException::class);
    }

    public function testStaticAndInfrastructureMethodsAreNotPartOfTheTemplateApi(): void
    {
        foreach (['getName', 'getProperties', 'getMethods', 'getModelClass', 'fromModel', 'fromLivewire', 'toLivewire', 'serialize', '__serialize', 'unserialize', 'offsetSet', '__set', '__debugInfo'] as $method) {
            $this->assertDenied('{{ $d->'.$method.'() }}', ForbiddenMethodException::class);
        }
        $this->assertDenied('{{ $d->getName }}', ForbiddenPropertyException::class);
    }

    public function testIssetUsesSandboxVisibleNamesOnly(): void
    {
        $sandbox = $this->allowing(DeploymentDTO::class);
        $data = ['d' => new DeploymentDTO()];

        $this->assertSame('yes', $sandbox->render("{{ isset(\$d->name) ? 'yes' : 'no' }}", $data));
        $this->assertSame('no', $sandbox->render("{{ isset(\$d->secret) ? 'yes' : 'no' }}", $data));
        $this->assertSame('no', $sandbox->render("{{ isset(\$d['secret']) ? 'yes' : 'no' }}", $data));
        $this->assertSame('fallback', $sandbox->render("{{ \$d->secret ?? 'fallback' }}", $data));
    }

    public function testReturnValueThatIsAnAllowedDto(): void
    {
        $this->assertSame('BLADE SANDBOX', $this->sandbox()->allowDto(DeploymentDTO::class)->allowDto(ProjectDTO::class)
            ->render('{{ $d->getProject()->shout() }}', ['d' => new DeploymentDTO()]));
    }

    public function testReturnValueDtoThatIsNotAllowed(): void
    {
        $this->assertDenied('{{ $d->getProject()->title }}', ForbiddenPropertyException::class);
    }

    public function testReturnedModelsAreNotTrusted(): void
    {
        $this->assertDenied('{{ $d->getUser()->name }}', ForbiddenPropertyException::class);
        $this->assertDenied('{{ $d->getUser()->delete() }}', ForbiddenMethodException::class);
        $this->assertDenied('{{ $d->getSomething()->save() }}', ForbiddenMethodException::class);
        $this->assertDenied('{{ $d->getUser() }}', ForbiddenMethodException::class);
        $this->assertSame(1, $this->userCount());
    }

    public function testReturnedModelsCanBeAllowedMemberByMember(): void
    {
        $sandbox = $this->allowing(DeploymentDTO::class)
            ->allowProperty(User::class, 'name')
            ->allowMethod(User::class, 'getName');

        $this->assertSame('Alice Alice', $sandbox->render('{{ $d->getUser()->name }} {{ $d->getUser()->getName() }}', ['d' => new DeploymentDTO()]));

        try {
            $sandbox->render('{{ $d->getUser()->delete() }}', ['d' => new DeploymentDTO()]);
            $this->fail('delete() must stay forbidden');
        } catch (ForbiddenMethodException) {
            $this->assertSame(1, $this->userCount());
        }
    }

    public function testReturnedCollectionsFollowTheCollectionPolicyAndContainedDtosTheDtoPolicy(): void
    {
        $sandbox = $this->allowing(DeploymentDTO::class)->allowDto(ProjectDTO::class);

        $this->assertSame('one,two,', $sandbox->render('@foreach($d->getCollection() as $p){{ $p->title }},@endforeach', ['d' => new DeploymentDTO()]));
        $this->assertSame('2', $sandbox->render('{{ $d->getCollection()->count() }}', ['d' => new DeploymentDTO()]));

        $this->expectException(ForbiddenMethodException::class);
        $sandbox->render("{{ \$d->getCollection()->map('strtoupper') }}", ['d' => new DeploymentDTO()]);
    }

    public function testCollectionItemsThatAreNotAllowedDtosStayRestricted(): void
    {
        $this->assertDenied('@foreach($d->getCollection() as $p){{ $p->title }}@endforeach', ForbiddenPropertyException::class);
    }

    public function testReturnedDatesAreValueObjects(): void
    {
        $sandbox = $this->allowing(DeploymentDTO::class);

        $this->assertSame('02.01.2026', $sandbox->render("{{ \$d->createdAt->format('d.m.Y') }}", ['d' => new DeploymentDTO()]));
        $this->assertSame('26.09.2026 2026', $sandbox->render("{{ \$d->getDate()->format('d.m.Y') }} {{ \$d->getDate()->year }}", ['d' => new DeploymentDTO()]));
        $this->assertDenied("{{ \$d->getDate()->modify('+1 day') }}", ForbiddenMethodException::class);
        $this->assertDenied('{{ $d->getDate()->setTestNow() }}', ForbiddenMethodException::class);
    }

    public function testDangerousDtoModelEscape(): void
    {
        $sandbox = $this->sandbox()->allowDto(DangerousDTO::class);

        try {
            $sandbox->render('{{ $dto->dangerousModel()->delete() }}', ['dto' => new DangerousDTO()]);
            $this->fail('The returned model must be checked by the object policy');
        } catch (ForbiddenMethodException $exception) {
            $this->assertStringContainsString('User::delete()', $exception->getMessage());
        }

        $this->assertDenied('{{ $dto->dangerousModel->forceDelete() }}', ForbiddenMethodException::class, $sandbox, ['dto' => new DangerousDTO()]);
        $this->assertSame(1, $this->userCount());
    }

    public function testNativeConversionCanBeDisabled(): void
    {
        $sandbox = $this->allowing(DeploymentDTO::class)->nativeDtoConversion(false);
        $data = ['d' => new DeploymentDTO()];

        // Native BaseDTO::__toString() would call every public method and expose the private $secret.
        $string = $sandbox->render('{{ $d }}', $data);
        $this->assertStringNotContainsString('secret', $string);
        $this->assertStringContainsString('&quot;name&quot;:&quot;api&quot;', $string);

        $this->assertSame('name,status,createdAt,', $sandbox->render('@foreach($d as $k => $v){{ $k }},@endforeach', $data));
        $this->assertDenied('{{ $d->toArray() }}', ForbiddenDtoException::class, $sandbox, $data);
        $this->assertDenied('{{ $d->getIterator() }}', ForbiddenDtoException::class, $sandbox, $data);
    }

    public function testWireableRoundTripAndSerializationAreNotTemplateCapabilities(): void
    {
        $dto = $this->dto();
        $payload = $dto->toLivewire();
        $this->assertSame('api', $payload['name']);
        $this->assertSame('api: running', TestDTO::fromLivewire($payload)->getLabel());

        $this->assertDenied('{{ $d->toLivewire() }}', ForbiddenMethodException::class, $this->allowing(), ['d' => $dto]);
        $this->assertDenied('{{ serialize($d) }}', ForbiddenFunctionException::class, $this->allowing(), ['d' => $dto]);
        $this->assertDenied("{{ unserialize('O:8:\"stdClass\":0:{}') }}", ForbiddenFunctionException::class, $this->allowing(), ['d' => $dto]);
    }

    public function testWritesAreImpossible(): void
    {
        foreach (["{{ \$d->name = 'x' }}", "{{ \$d['name'] = 'x' }}", '{{ $d->name .= 1 }}', '{{ unset($d->name) }}'] as $template) {
            try {
                $this->allowing()->render($template, ['d' => $this->dto()]);
                $this->fail('write must be impossible: '.$template);
            } catch (SecurityViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param class-string<Throwable> $exception
     * @param array<string, mixed>|null $data
     */
    private function assertDenied(string $template, string $exception, ?Sandbox $sandbox = null, ?array $data = null): void
    {
        try {
            ($sandbox ?? $this->allowing(DeploymentDTO::class))->render($template, $data ?? ['d' => new DeploymentDTO()]);
            $this->fail('Expected '.$exception.' for '.$template);
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, $template.' -> '.$thrown->getMessage());
        }
    }
}
