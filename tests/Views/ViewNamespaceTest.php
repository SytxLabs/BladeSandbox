<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Views;

use Illuminate\Support\Facades\View;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewNamespaceException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class ViewNamespaceTest extends TestCase
{
    public function testDeployerTestViewWithSandboxedInclude(): void
    {
        $html = $this->sandbox()
            ->allowDto(DeploymentDTO::class)
            ->allowView('deployer::test')
            ->allowView('deployer::partials.*')
            ->renderView('deployer::test', ['deployment' => new DeploymentDTO()]);

        $this->assertSame("<h1>api</h1>\n<header>running</header>\n", $html);
    }

    public function testEntryViewMustBeAllowed(): void
    {
        $this->expectException(ForbiddenViewException::class);

        $this->sandbox()->allowView('deployer::partials.*')->renderView('deployer::test', ['deployment' => new DeploymentDTO()]);
    }

    public function testNamespaceThatIsNotAllowedAtAll(): void
    {
        $this->expectException(ForbiddenViewNamespaceException::class);

        $this->sandbox()->allowView('other::x')->renderView('deployer::test');
    }

    public function testIncludeIsNotAllowedImplicitly(): void
    {
        $this->expectException(ForbiddenViewException::class);

        $this->sandbox()->allowDto(DeploymentDTO::class)->allowView('deployer::test')
            ->renderView('deployer::test', ['deployment' => new DeploymentDTO()]);
    }

    public function testWildcardDoesNotReachSiblingDirectories(): void
    {
        $sandbox = $this->sandbox()->allowView('deployer::emails.*');

        $this->assertSame("Deploy api\n", $sandbox->allowDto(DeploymentDTO::class)->renderView('deployer::emails.deploy', ['deployment' => new DeploymentDTO()]));

        $this->expectException(ForbiddenViewException::class);
        $sandbox->renderView('deployer::emails.evil');
    }

    public function testDynamicIncludeNamesAreCheckedAtRuntime(): void
    {
        $sandbox = $this->sandbox()->allowView('deployer::emails.*');

        $this->assertSame("Deploy api\n", $sandbox->allowDto(DeploymentDTO::class)->renderView('deployer::emails.dynamic', [
            'target' => 'deployer::emails.deploy',
            'deployment' => new DeploymentDTO(),
        ]));

        foreach (['deployer::admin.secret', 'secret', 'deployer::../admin/secret', '/etc/passwd', 'deployer::emails/../admin/secret'] as $target) {
            try {
                $sandbox->renderView('deployer::emails.dynamic', ['target' => $target]);
                $this->fail('Dynamic include of '.$target.' must be denied');
            } catch (ForbiddenViewException|ForbiddenViewNamespaceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testViewNamespaceAllowance(): void
    {
        $html = $this->sandbox()->allowViewNamespace('deployer')->allowDto(DeploymentDTO::class)
            ->renderView('deployer::test', ['deployment' => new DeploymentDTO()]);

        $this->assertStringContainsString('<header>running</header>', $html);
    }

    public function testLayoutsSectionsAndStacksStaySandboxed(): void
    {
        $html = $this->sandbox()->allowView('deployer::page')->allowView('deployer::layout')->allowView('deployer::partials.*')
            ->allowDto(DeploymentDTO::class)
            ->renderView('deployer::page', ['deployment' => new DeploymentDTO()]);

        $this->assertStringContainsString('<title>Page api</title>', $html);
        $this->assertStringContainsString('<main>running</main>', $html);
        $this->assertStringContainsString('<footer>api: running</footer>', $html);
        $this->assertStringContainsString('<i>pushed</i>', $html);
    }

    public function testExtendsOfForbiddenLayout(): void
    {
        $this->expectException(ForbiddenViewException::class);

        $this->sandbox()->allowView('deployer::evil-layout')->renderView('deployer::evil-layout');
    }

    public function testNestedIncludesNeverLeaveTheSandbox(): void
    {
        $this->expectException(ForbiddenFunctionException::class);

        $this->sandbox()->allowView('deployer::nested')->allowView('deployer::partials.**')->allowDto(DeploymentDTO::class)
            ->renderView('deployer::nested', ['deployment' => new DeploymentDTO()]);
    }

    public function testRecursiveIncludesHitTheDepthLimit(): void
    {
        $this->expectException(SandboxLimitExceededException::class);

        $this->sandbox()->allowView('deployer::recursive')->maxDepth(10)->renderView('deployer::recursive');
    }

    public function testViewResolutionIsNotTrustPlainPhpViewsAreCompiledByTheSandbox(): void
    {
        $this->expectException(InvalidSandboxTemplateException::class);

        $this->sandbox()->allowView('deployer::raw')->renderView('deployer::raw');
    }

    public function testInvalidViewNamesAreRejected(): void
    {
        foreach (['deployer::../admin/secret', 'deployer::admin/secret', "deployer::test\0", '../../etc/passwd'] as $name) {
            try {
                $this->sandbox()->allowViewNamespace('deployer')->renderView($name);
                $this->fail('Invalid name must be rejected: '.$name);
            } catch (ForbiddenViewException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOutputLimit(): void
    {
        $this->expectException(SandboxLimitExceededException::class);

        $this->sandbox()->maxOutputBytes(10)->render('{{ $v }}', ['v' => str_repeat('x', 100)]);
    }

    public function testPrependAndReplaceNamespaceAreRespected(): void
    {
        $override = sys_get_temp_dir().'/blade-sandbox-override-'.getmypid();
        @mkdir($override.'/partials', 0777, true);
        file_put_contents($override.'/partials/header.blade.php', "<header>override {{ \$deployment->name }}</header>\n");

        View::prependNamespace('deployer', $override);

        $html = $this->sandbox()->allowViewNamespace('deployer')->allowDto(DeploymentDTO::class)
            ->renderView('deployer::test', ['deployment' => new DeploymentDTO()]);
        $this->assertStringContainsString('<header>override api</header>', $html);

        View::replaceNamespace('deployer', [__DIR__.'/../Fixtures/views/deployer']);
        View::getFinder()->flush(); // FileViewFinder caches resolved paths
        $html = $this->sandbox()->allowViewNamespace('deployer')->allowDto(DeploymentDTO::class)
            ->renderView('deployer::test', ['deployment' => new DeploymentDTO()]);
        $this->assertStringContainsString('<header>running</header>', $html);
    }

    public function testBoundNamespaceIsSandboxedThroughTheNormalViewHelper(): void
    {
        BladeSandbox::sandboxNamespace('deployer', $this->sandbox()->allowViewNamespace('deployer')->allowDto(DeploymentDTO::class));

        $this->assertSame("<h1>api</h1>\n<header>running</header>\n", view('deployer::test', ['deployment' => new DeploymentDTO()])->render());

        // Rendering a sandboxed view from a trusted application view keeps the sandbox for that view.
        $this->assertStringContainsString('<header>running</header>', view('includes-sandboxed', ['deployment' => new DeploymentDTO()])->render());

        // Trusted views are untouched.
        $this->assertSame("<p>trusted Bob</p>\n", view('trusted', ['name' => 'Bob'])->render());

        $this->expectException(InvalidSandboxTemplateException::class);
        view('deployer::raw')->render();
    }

    public function testBoundNamespaceEscapeAttemptViaNormalView(): void
    {
        BladeSandbox::sandboxNamespace('deployer', $this->sandbox()->allowView('deployer::emails.*'));

        $this->expectException(ForbiddenViewException::class);
        view('deployer::emails.evil')->render();
    }

    public function testSandboxViewReturnsARegularView(): void
    {
        $view = $this->sandbox()->allowView('deployer::emails.deploy')->allowDto(DeploymentDTO::class)
            ->view('deployer::emails.deploy');

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view);
        $this->assertSame("Deploy api\n", $view->with('deployment', new DeploymentDTO())->render());
    }

    #[DefineEnvironment('sandboxDeployerNamespace')]
    public function testConfiguredNamespacesAreSandboxedOnBoot(): void
    {
        $this->assertArrayHasKey('deployer', $this->app->make(SandboxManager::class)->sandboxedNamespaces());
    }

    protected function sandboxDeployerNamespace($app): void
    {
        $app['config']->set('blade-sandbox.namespaces', ['deployer' => null]);
    }
}
