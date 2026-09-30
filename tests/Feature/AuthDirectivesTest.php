<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class AuthDirectivesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('edit-post', static fn ($user, object $post): bool => $post->owner === $user->id);
        Gate::define('publish', static fn ($user): bool => false);
    }

    private function authSandbox(): Sandbox
    {
        return $this->sandbox()->allowAuthDirectives();
    }

    public function testDirectivesAreOptIn(): void
    {
        foreach (['@auth x @endauth', '@guest x @endguest', "@can('edit-post') x @endcan", "@cannot('x') y @endcannot", "@canany(['x']) y @endcanany"] as $template) {
            try {
                $this->sandbox()->render($template);
                $this->fail('must be opt-in: '.$template);
            } catch (ForbiddenDirectiveException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAuthAndGuest(): void
    {
        $template = '@auth in @elseauth(\'web\') web @endauth|@guest out @elseguest(\'web\') in-guest @endguest';

        $this->assertSame('| out', $this->normalize($this->authSandbox()->render($template)));

        $this->actingAs(new GenericUser(['id' => 1]));
        $this->assertSame('in |', $this->normalize($this->authSandbox()->render($template)));
        $this->assertSame('web', trim($this->authSandbox()->render("@auth('web') web @endauth")));
    }

    public function testCanCannotCanany(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        $sandbox = $this->authSandbox();
        $data = ['mine' => (object) ['owner' => 1], 'other' => (object) ['owner' => 2]];

        $this->assertSame('edit', trim($sandbox->render("@can('edit-post', \$mine) edit @elsecan('publish') publish @endcan", $data)));
        $this->assertSame('', trim($sandbox->render("@can('edit-post', \$other) edit @endcan", $data)));
        $this->assertSame('readonly', trim($sandbox->render("@cannot('edit-post', \$other) readonly @endcannot", $data)));
        $this->assertSame('any', trim($sandbox->render("@canany(['publish', 'edit-post'], \$mine) any @elsecanany(['publish']) no @endcanany", $data)));
        $this->assertSame('', trim($sandbox->render("@canany(['publish']) any @endcanany")));
    }

    public function testTheUserObjectIsNotExposedAndArgumentsAreChecked(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'password' => 'secret']));
        $sandbox = $this->authSandbox();

        foreach ([
            '{{ auth()->user()->password }}' => SecurityViolationException::class,
            '{{ $user->password }}' => SecurityViolationException::class,
            '@can($fn) x @endcan' => SandboxException::class,
            "@can('edit-post', \$fn) x @endcan" => SecurityViolationException::class,
            '@auth($guard) x @endauth' => SandboxException::class,
            "@canany('edit-post') x @endcanany" => SandboxException::class,
        ] as $template => $exception) {
            try {
                $sandbox->render($template, ['fn' => static fn () => true, 'guard' => '../x', 'user' => auth()->user()]);
                $this->fail('must be rejected: '.$template);
            } catch (SandboxException $thrown) {
                $this->assertInstanceOf($exception, $thrown, $template);
            }
        }
    }

    public function testBlocksKeepTheHtmlContextRules(): void
    {
        $this->expectException(InvalidSandboxTemplateException::class);
        $this->authSandbox()->render('@auth <div title=" @endauth ">');
    }

    public function testSingleFlagAndVariadicCalls(): void
    {
        foreach (['auth', 'endauth', 'elseguest', 'can', 'elsecannot', 'endcanany'] as $directive) {
            $this->assertTrue($this->sandbox()->allowAuthDirectives()->policy()->allowsDirective($directive), $directive);
        }

        $policy = $this->sandbox()->allowDirective('auth', 'can')->allowFunction('strtoupper', 'strtolower')->policy();
        $this->assertTrue($policy->allowsDirective('endcan'));
        $this->assertFalse($policy->allowsDirective('guest'));
        $this->assertTrue($policy->allowsFunction('strtolower'));

        config()->set('blade-sandbox.policies.members-all', ['auth_directives' => true]);
        $this->assertTrue($this->app->make(SandboxManager::class)->policy('members-all')->policy()->allowsDirective('canany'));
    }

    public function testConfig(): void
    {
        config()->set('blade-sandbox.policies.members', ['directives' => ['auth', 'can']]);
        $sandbox = $this->app->make(SandboxManager::class)->policy('members');

        $this->assertSame('', trim($sandbox->render('@auth member @endauth')));
        $this->assertTrue($sandbox->policy()->allowsDirective('endcan'));
        $this->assertFalse($sandbox->policy()->allowsDirective('guest'));
    }

    private function normalize(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', $html) ?? '');
    }
}
