<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Exception;
use Illuminate\Support\Facades\Queue;
use stdClass;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Jobs\RenderTemplate;
use SytxLabs\BladeSandbox\Sanitizers\HtmlSanitizer;
use SytxLabs\BladeSandbox\Tests\TestCase;
use Throwable;

final class QueuedRenderingTest extends TestCase
{
    /** @var list<array{0: mixed, 1: array<string, mixed>}> */
    public static array $received = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('blade-sandbox.fallback_report', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$received = [];
    }

    public function testClosureCallbackReceivesTheHtml(): void
    {
        $this->sandbox()->allowFunction('strtoupper')->queueRender(
            '<p>{{ strtoupper($name) }}</p>',
            ['name' => 'ada'],
            static function (string $html, array $context): void {
                QueuedRenderingTest::$received[] = [$html, $context];
            },
            ['recipient' => 42],
        );

        $this->assertSame([['<p>ADA</p>', ['recipient' => 42]]], self::$received);
    }

    public function testInvokableClassCallbackAndViews(): void
    {
        $this->sandbox()->allowView('deployer::emails.deploy')->queueRenderView(
            'deployer::emails.deploy',
            ['deployment' => (object) ['name' => 'api']],
            ReceiveRender::class,
            ['id' => 7],
        );

        $this->assertSame([["Deploy api\n", ['id' => 7]]], self::$received);
    }

    public function testFailuresReachTheCatchCallback(): void
    {
        try {
            $this->sandbox()->queueRender('{{ exec("id") }}', [], null, ['id' => 1], static function (Throwable $e, array $context): void {
                QueuedRenderingTest::$received[] = [$e::class, $context];
            });
        } catch (ForbiddenFunctionException) {
            // the sync driver rethrows after calling failed()
        }

        $this->assertSame([[ForbiddenFunctionException::class, ['id' => 1]]], self::$received);
    }

    public function testThePipelineRunsInTheWorker(): void
    {
        $this->sandbox()->sanitizeWith(new HtmlSanitizer())->renderFallback('empty')->queueRender(
            '<p style="x">ok</p>',
            [],
            static function (string $html): void {
                QueuedRenderingTest::$received[] = [$html, []];
            },
        );
        $this->sandbox()->renderFallback('empty')->queueRender('{{ exec("id") }}', [], static function (string $html): void {
            QueuedRenderingTest::$received[] = [$html, []];
        });

        $this->assertSame([['<p>ok</p>', []], ['', []]], self::$received);
    }

    public function testDispatchOptionsAndRejectedPayloads(): void
    {
        Queue::fake();

        $this->sandbox()->queueRender('x')->onQueue('renders');
        Queue::assertPushedOn('renders', RenderTemplate::class);

        try {
            $this->sandbox()->sanitizeWith(static fn (string $html): string => $html)->queueRender('x');
            $this->fail('A closure sanitizer cannot be queued.');
        } catch (SandboxException $e) {
            $this->assertStringContainsString('sanitizer', $e->getMessage());
        }

        $this->expectException(SandboxException::class);
        $this->sandbox()->queueRender('x', ['callback' => static fn () => 1]);
    }

    public function testQueuedJobRejectsInvalidPayloadsAndCallbacks(): void
    {
        $payload = base64_encode(serialize(['state' => [], 'job' => [], 'context' => []]));

        (new RenderTemplate($payload))->failed(new Exception('x'));

        try {
            (new RenderTemplate($payload, null, stdClass::class))->failed(new Exception('x'));
            $this->fail('Expected a non-invokable callback to be rejected.');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('is not invokable', $exception->getMessage());
        }

        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Invalid queued render payload.');
        (new RenderTemplate(base64_encode(serialize('nope'))))->failed(new Exception('x'));
    }

    public function testQueuedFileJobsRenderTheFileContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bs');
        file_put_contents($path, 'Hi {{ $name }}');

        try {
            $html = $this->sandbox()->allowView('*')->renderJobThroughPipeline(['kind' => 'file', 'path' => $path, 'view' => 'tmp-file'], ['name' => 'Bob']);
        } finally {
            unlink($path);
        }

        $this->assertSame('Hi Bob', $html);
    }
}

final class ReceiveRender
{
    /** @param array<string, mixed> $context */
    public function __invoke(string $html, array $context): void
    {
        QueuedRenderingTest::$received[] = [$html, $context];
    }
}
