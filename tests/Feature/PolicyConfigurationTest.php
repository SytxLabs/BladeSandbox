<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayObject;
use DateTimeInterface;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class PolicyConfigurationTest extends TestCase
{
    public function testEveryRemainingConfigKeyIsApplied(): void
    {
        $sandbox = PolicyConfiguration::apply($this->sandbox(), [
            'dto_namespaces' => ['SytxLabs\\BladeSandbox\\Tests\\Fixtures\\App\\DTO'],
            'native_dto_conversion' => false,
            'constants' => ['PHP_EOL'],
            'class_constants' => [DateTimeInterface::class => ['ATOM', 'RFC3339']],
            'static_methods' => [TestDTO::class => ['fromLivewire']],
            'iteration' => [ArrayObject::class],
            'array_access' => [ArrayObject::class],
            'string_conversion' => [\Illuminate\Support\Stringable::class],
            'components' => ['deployer::button'],
            'deny_methods' => [TestDTO::class => ['getLabel']],
            'deny_class_constants' => [DateTimeInterface::class => ['RFC3339']],
            'livewire' => [
                'directives' => ['click'],
                'actions' => ['save'],
                'models' => ['title'],
                'events' => ['saved'],
                'components' => ['counter'],
                'alpine' => true,
            ],
        ]);

        $policy = $sandbox->policy();
        $this->assertFalse($policy->usesNativeDtoConversion());
        $this->assertTrue($policy->allowsConstant('PHP_EOL'));
        $this->assertTrue($policy->allowsClassConstant(DateTimeInterface::class, 'ATOM'));
        $this->assertTrue($policy->allowsStaticMethod(TestDTO::class, 'fromLivewire'));
        $this->assertTrue($policy->allowsIteration(new ArrayObject()));
        $this->assertTrue($policy->allowsArrayAccess(new ArrayObject()));
        $this->assertTrue($policy->allowsStringConversion(new \Illuminate\Support\Stringable('x')));
        $this->assertTrue($policy->allowsComponent('deployer::button'));
        $this->assertFalse($policy->allowsMethod(new TestDTO(), 'getLabel'));
        $this->assertTrue($policy->allowsClassConstant(DateTimeInterface::class, 'ATOM'));
        $this->assertFalse($policy->allowsClassConstant(DateTimeInterface::class, 'RFC3339'));
        $this->assertTrue($policy->allowsLivewireDirective('click'));
        $this->assertTrue($policy->allowsLivewireAction('save'));
        $this->assertTrue($policy->allowsLivewireModel('title'));
        $this->assertTrue($policy->allowsLivewireEvent('saved'));
        $this->assertTrue($policy->allowsLivewireComponent('counter'));
        $this->assertTrue($policy->allowsAlpine());
    }
}
