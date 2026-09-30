<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Unit;

use ArrayObject;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use SytxLabs\BladeSandbox\Policy\ComponentPolicy;
use SytxLabs\BladeSandbox\Policy\DtoPolicy;
use SytxLabs\BladeSandbox\Policy\FunctionPolicy;
use SytxLabs\BladeSandbox\Policy\ObjectAccessPolicy;
use SytxLabs\BladeSandbox\Policy\PolicyBuilder;
use SytxLabs\BladeSandbox\Policy\SecurityPolicy;
use SytxLabs\BladeSandbox\Policy\ViewPolicy;
use SytxLabs\BladeSandbox\Support\ClassMetadataCache;
use SytxLabs\BladeSandbox\Support\Patterns;
use SytxLabs\BladeSandbox\Validation\Violation;

final class PolicyTest extends TestCase
{
    public function testDenyAllByDefault(): void
    {
        $policy = SecurityPolicy::denyAll();

        $this->assertFalse($policy->allowsFunction('strlen'));
        $this->assertFalse($policy->allowsMethod(stdClass::class, 'x'));
        $this->assertFalse($policy->allowsProperty(stdClass::class, 'x'));
        $this->assertFalse($policy->allowsView('welcome'));
        $this->assertFalse($policy->allowsComponent('alert'));
        $this->assertFalse($policy->allowsDto(stdClass::class));
        $this->assertFalse($policy->allowsRawEcho());
        $this->assertFalse($policy->allowsLivewireAction('save'));
        $this->assertFalse($policy->allowsLivewireDirective('click'));
        $this->assertFalse($policy->allowsDirective('csrf'));
        $this->assertTrue($policy->allowsDirective('if'));
    }

    public function testViewPatterns(): void
    {
        $this->assertTrue(Patterns::matches('deployer::emails.*', 'deployer::emails.deploy'));
        $this->assertFalse(Patterns::matches('deployer::emails.*', 'deployer::emails.nested.deploy'));
        $this->assertTrue(Patterns::matches('deployer::emails.**', 'deployer::emails.nested.deploy'));
        $this->assertFalse(Patterns::matches('deployer::emails.*', 'deployer::admin.secret'));
        $this->assertFalse(Patterns::matches('deployer::emails.*', 'deployer::emailsx.deploy'));
        $this->assertFalse(Patterns::isValidName('deployer::../secret'));
        $this->assertFalse(Patterns::isValidName('deployer::a/b'));
        $this->assertFalse(Patterns::isValidName("a\0b"));
        $this->assertTrue(Patterns::isValidName('deployer::emails.deploy-now_2'));
    }

    public function testViewPolicy(): void
    {
        $policy = (new PolicyBuilder())->allowView('deployer::emails.*')->allowViewNamespace('plugin')->build();

        $this->assertTrue($policy->allowsView('deployer::emails.deploy'));
        $this->assertFalse($policy->allowsView('deployer::admin.secret'));
        $this->assertTrue($policy->allowsView('plugin::anything.deep'));
        $this->assertTrue($policy->allowsViewNamespace('plugin'));
        $this->assertFalse($policy->allowsViewNamespace('deployer'));
    }

    public function testClassRulesAreInherited(): void
    {
        $policy = (new PolicyBuilder())->allowMethod(DateTimeInterface::class, 'format')->build();

        $this->assertTrue($policy->allowsMethod(new DateTimeImmutable(), 'format'));
        $this->assertTrue($policy->allowsMethod(new DateTimeImmutable(), 'FORMAT'));
        $this->assertFalse($policy->allowsMethod(new DateTimeImmutable(), 'modify'));
    }

    public function testMagicMethodsAndNeverDirectivesCannotBeAllowed(): void
    {
        foreach ([
            static fn () => (new PolicyBuilder())->allowMethod(stdClass::class, '__toString'),
            static fn () => (new PolicyBuilder())->allowDirective('php'),
            static fn () => (new PolicyBuilder())->allowDirective('inject'),
            static fn () => (new PolicyBuilder())->allowDirective('madeUp'),
            static fn () => (new PolicyBuilder())->directive('if', static fn () => ''),
            static fn () => (new PolicyBuilder())->allowDtoNamespace('\\'),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFingerprintChangesWithEveryCapability(): void
    {
        $base = (new PolicyBuilder())->build()->fingerprint();

        $variants = [
            (new PolicyBuilder())->allowView('a'),
            (new PolicyBuilder())->allowFunction('strlen'),
            (new PolicyBuilder())->allowRawEcho(),
            (new PolicyBuilder())->allowDirective('csrf'),
            (new PolicyBuilder())->allowLivewireDirective('click'),
            (new PolicyBuilder())->allowAlpine(),
            (new PolicyBuilder())->allowDto(stdClass::class),
            (new PolicyBuilder())->nativeDtoConversion(false),
            (new PolicyBuilder())->allowClassNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options'),
            (new PolicyBuilder())->allowStaticMethod(stdClass::class, 'x'),
            (new PolicyBuilder())->directive('money', static fn () => ''),
        ];

        $fingerprints = [$base];
        foreach ($variants as $builder) {
            $fingerprints[] = $builder->build()->fingerprint();
        }

        $this->assertCount(count($fingerprints), array_unique($fingerprints));
        $this->assertSame($base, (new PolicyBuilder())->build()->fingerprint());
    }

    public function testLivewireModelMatchesRootProperty(): void
    {
        $policy = (new PolicyBuilder())->allowLivewireModel('form')->build();

        $this->assertTrue($policy->allowsLivewireModel('form.title'));
        $this->assertFalse($policy->allowsLivewireModel('formx'));
    }

    public function testMoreInvalidBuilderCallsAreRejected(): void
    {
        foreach ([
            static fn () => (new PolicyBuilder())->allowStaticMethod(stdClass::class, '__invoke'),
            static fn () => (new PolicyBuilder())->allowClassNamespace(''),
            static fn () => (new PolicyBuilder())->allowRoutes(''),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLivewireComponentIsAllowedOnceRegistered(): void
    {
        $policy = (new PolicyBuilder())->allowLivewireComponent('counter')->build();

        $this->assertTrue($policy->allowsLivewireComponent('counter'));
        $this->assertFalse($policy->allowsLivewireComponent('other'));
    }

    public function testAllowsClassNamespaceIsAPublicCheck(): void
    {
        $policy = (new PolicyBuilder())->allowClassNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options')->build();

        $this->assertTrue($policy->allowsClassNamespace('SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\Options\\Magic'));
        $this->assertFalse($policy->allowsClassNamespace(stdClass::class));
    }

    public function testStringConversionIsDeniedWhenTheClassIsDenied(): void
    {
        $policy = (new PolicyBuilder())->allowStringConversion(ArrayObject::class)->denyClass(ArrayObject::class)->build();

        $this->assertFalse($policy->allowsStringConversion(new ArrayObject()));
    }

    public function testAllowsMethodExplicitlyRejectsMagicMethodNames(): void
    {
        $policy = SecurityPolicy::denyAll();

        $this->assertFalse($policy->allowsMethodExplicitly(stdClass::class, '__wakeup'));
    }

    public function testUnknownStaticMethodTargetClassFallsBackToItsLowercasedName(): void
    {
        // A class name that does not exist at all is still handled: it degrades to a single "type" (its lowercased name).
        $this->assertSame(['nosuchclassxyz'], ObjectAccessPolicy::typesOf('NoSuchClassXyz'));
    }

    public function testArrayAccessIsDeniedForAnUnrelatedClass(): void
    {
        $policy = (new PolicyBuilder())->allowArrayAccess(ArrayObject::class)->build();

        $this->assertTrue($policy->allowsArrayAccess(new ArrayObject()));
        $this->assertFalse($policy->allowsArrayAccess(new ComponentAttributeBag()));
    }

    public function testDtoFunctionAndViewPoliciesRejectInvalidInputDirectly(): void
    {
        $dto = new DtoPolicy(['stdclass']);
        $this->assertFalse($dto->allows('NoSuchDtoClassXyz'));

        $functions = new FunctionPolicy();
        $this->assertFalse($functions->allowsStaticMethod(stdClass::class, '__destruct'));

        $view = new ViewPolicy(['welcome']);
        $this->assertFalse($view->allowsView('bad name!'));
    }

    public function testComponentPolicyFindsAFactoryByWildcardPattern(): void
    {
        $factory = static fn (): string => 'made';
        $policy = new ComponentPolicy(['plugin::widgets.*'], ['plugin::widgets.*' => $factory]);

        $this->assertSame($factory, $policy->factory('plugin::widgets.foo'));
        $this->assertNull($policy->factory('plugin::other'));
    }

    public function testClassMetadataCacheCanBeFlushed(): void
    {
        ClassMetadataCache::flush();
        $this->addToAssertionCount(1);
    }

    public function testViolationConvertsToAnArray(): void
    {
        $violation = new Violation('welcome', 'function', 'exec()', 'Function exec() is not allowed.', 3);

        $this->assertSame([
            'template' => 'welcome',
            'capability' => 'function',
            'subject' => 'exec()',
            'message' => 'Function exec() is not allowed.',
            'line' => 3,
        ], $violation->toArray());
    }
}
