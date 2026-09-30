<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use SytxLabs\BladeSandbox\Contracts\ViolationLimiter;
use SytxLabs\BladeSandbox\Events\AuthorLocked;
use SytxLabs\BladeSandbox\Exceptions\AuthorLockedException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Rules\SandboxTemplate;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class AuthorLockoutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('blade-sandbox.author_lockout', ['enabled' => true, 'max_violations' => 3, 'decay_minutes' => 10]);
        $app['config']->set('blade-sandbox.fallback_report', false);
    }

    private function author(string $author = 'user-1'): Sandbox
    {
        return $this->sandbox()->forAuthor($author);
    }

    private function violate(Sandbox $sandbox): void
    {
        try {
            $sandbox->render('{{ exec("id") }}');
        } catch (ForbiddenFunctionException) {
        }
    }

    public function testAuthorsAreLockedAfterTooManyViolations(): void
    {
        Event::fake([AuthorLocked::class]);
        $lockout = $this->app->make(SandboxManager::class)->lockout();

        $this->violate($this->author());
        $this->violate($this->author());
        $this->assertSame(2, $lockout->hits('user-1'));
        $this->assertSame('ok', $this->author()->render('ok'));

        $this->violate($this->author());
        $this->assertTrue($lockout->isLocked('user-1'));
        Event::assertDispatched(AuthorLocked::class, static fn (AuthorLocked $e): bool => $e->author === 'user-1' && $e->violations === 3);

        try {
            $this->author()->render('ok');
            $this->fail('A locked author must not render.');
        } catch (AuthorLockedException $e) {
            $this->assertStringContainsString('user-1', $e->getMessage());
        }

        // Other authors and untagged sandboxes are unaffected.
        $this->assertSame('ok', $this->author('user-2')->render('ok'));
        $this->assertSame('ok', $this->sandbox()->render('ok'));

        $lockout->clear('user-1');
        $this->assertSame('ok', $this->author()->render('ok'));
    }

    public function testFallbackAppliesToLockedAuthors(): void
    {
        foreach (range(1, 3) as $i) {
            $this->violate($this->author());
        }

        $this->assertSame('', $this->author()->renderFallback('empty')->render('ok'));
    }

    public function testValidationCountsAndReportsTheLock(): void
    {
        foreach (range(1, 3) as $i) {
            $this->assertTrue($this->author()->validate('{{ exec("id") }}')->fails());
        }

        $result = $this->author()->validate('fine');
        $this->assertTrue($result->fails());
        $this->assertSame('author', $result->violations()[0]->capability);

        $validator = Validator::make(['body' => 'fine'], ['body' => [new SandboxTemplate($this->author())]]);
        $this->assertTrue($validator->fails());

        $preview = $this->author()->preview('fine');
        $this->assertInstanceOf(AuthorLockedException::class, $preview->error);
    }

    public function testDisabledLockoutDoesNotCount(): void
    {
        $this->app['config']->set('blade-sandbox.author_lockout.enabled', false);
        $this->app->forgetInstance(SandboxManager::class);

        foreach (range(1, 5) as $i) {
            $this->violate($this->author());
        }

        $this->assertSame('ok', $this->author()->render('ok'));
    }

    public function testCustomLimiter(): void
    {
        $limiter = new class implements ViolationLimiter
        {
            /** @var array<string, int> */
            public array $hits = [];

            public function hit(string $author): int
            {
                return $this->hits[$author] = ($this->hits[$author] ?? 0) + 1;
            }

            public function hits(string $author): int
            {
                return $this->hits[$author] ?? 0;
            }

            public function isLocked(string $author): bool
            {
                return $this->hits($author) >= 1;
            }

            public function clear(string $author): void
            {
                unset($this->hits[$author]);
            }

            public function maxViolations(): int
            {
                return 1;
            }
        };
        $this->app->make(SandboxManager::class)->useViolationLimiter($limiter);

        $this->violate($this->author('tenant-9'));

        $this->assertSame(['tenant-9' => 1], $limiter->hits);
        $this->expectException(AuthorLockedException::class);
        $this->author('tenant-9')->render('ok');
    }

    public function testAuthorGetter(): void
    {
        $this->assertSame('user-42', $this->author('user-42')->author());
        $this->assertNull($this->sandbox()->author());
    }
}
