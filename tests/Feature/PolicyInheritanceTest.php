<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use InvalidArgumentException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class PolicyInheritanceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('blade-sandbox.policies.base', [
            'functions' => ['strtoupper'],
            'views' => ['deployer::partials.*'],
            'deny_functions' => ['strtolower'],
        ]);
        $app['config']->set('blade-sandbox.policies.mail', [
            'extends' => 'base',
            'functions' => ['ucfirst', 'strtolower'],
            'directives' => ['lang'],
        ]);
        $app['config']->set('blade-sandbox.policies.newsletter', [
            'extends' => ['mail'],
            'raw_echo' => true,
        ]);
        $app['config']->set('blade-sandbox.policies.loop-a', ['extends' => 'loop-b']);
        $app['config']->set('blade-sandbox.policies.loop-b', ['extends' => 'loop-a']);
    }

    public function testConfigExtendsInheritsRulesRecursively(): void
    {
        $sandbox = BladeSandbox::policy('newsletter');

        $this->assertSame('AB', $sandbox->render('{{ strtoupper("a") }}{!! ucfirst("b") !!}'));
        $this->assertSame('<header>ok</header>', trim($sandbox->render("@include('deployer::partials.header')", ['deployment' => (object) ['status' => 'ok']])));
        $this->assertSame('newsletter', $sandbox->origin());
    }

    public function testDenyRulesOfParentsKeepWinning(): void
    {
        $this->expectException(ForbiddenFunctionException::class);

        // "mail" allows strtolower, but its parent "base" denies it.
        BladeSandbox::policy('mail')->render('{{ strtolower("A") }}');
    }

    public function testChildRulesDoNotLeakIntoTheParent(): void
    {
        $this->expectException(ForbiddenFunctionException::class);

        BladeSandbox::policy('base')->render('{{ ucfirst("a") }}');
    }

    public function testExtendWithSandboxesAndNames(): void
    {
        $parent = BladeSandbox::make()->allowFunction('strtoupper')->allowView('deployer::partials.*');
        $child = BladeSandbox::make()->extend($parent, 'mail');

        $this->assertSame('AB', $child->render('{{ strtoupper("a") }}{{ ucfirst("b") }}'));

        // The parent is not modified by the child.
        $this->expectException(ForbiddenFunctionException::class);
        $parent->render('{{ ucfirst("b") }}');
    }

    public function testDeniedDirectivesStayDenied(): void
    {
        $parent = BladeSandbox::make()->denyDirective('include');
        $child = BladeSandbox::make()->allowView('deployer::**')->extend($parent);

        $this->expectException(ForbiddenDirectiveException::class);
        $child->render("@include('deployer::partials.footer')");
    }

    public function testRestrictionsAndPermissionsAreCombined(): void
    {
        $child = BladeSandbox::make()->extend(BladeSandbox::make()->allowRawEcho()->nativeDtoConversion(false));

        $this->assertSame('<b>x</b>', $child->render('{!! $v !!}', ['v' => '<b>x</b>']));
        $this->assertFalse($child->policy()->usesNativeDtoConversion());
    }

    public function testDeniedViewsInTheParentWin(): void
    {
        $parent = BladeSandbox::make()->denyView('deployer::admin.**');
        $child = BladeSandbox::make()->allowViewNamespace('deployer')->extend($parent);

        $this->expectException(ForbiddenViewException::class);
        $child->renderView('deployer::admin.secret');
    }

    public function testInheritanceCyclesAreDetected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular blade sandbox policy inheritance: loop-a → loop-b → loop-a');

        $this->app->make(SandboxManager::class)->policy('loop-a');
    }
}
