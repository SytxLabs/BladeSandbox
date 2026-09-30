<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use LogicException;
use ReflectionMethod;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Isolation\IsolatedRenderer;
use SytxLabs\BladeSandbox\Isolation\IsolatedRenderWorker;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class IsolationInternalsTest extends TestCase
{
    private function manager(): SandboxManager
    {
        return $this->app->make(SandboxManager::class);
    }

    public function testWorkerRunsAValidPayloadInProcess(): void
    {
        $sandbox = $this->sandbox()->allowFunction('strtoupper');
        $payload = base64_encode(serialize([
            'state' => $sandbox->isolationState(),
            'job' => ['kind' => 'string', 'template' => '{{ strtoupper($n) }}', 'name' => 'inline'],
            'data' => ['n' => 'x'],
        ]));

        $result = json_decode((new IsolatedRenderWorker($this->manager()))->run($payload), true);

        $this->assertTrue($result['ok']);
        $this->assertSame('X', $result['html']);
    }

    public function testWorkerRejectsAnInvalidPayload(): void
    {
        $payload = base64_encode(serialize('just a string, not the expected array shape'));
        $result = json_decode((new IsolatedRenderWorker($this->manager()))->run($payload), true);

        $this->assertFalse($result['ok']);
        $this->assertSame(SandboxException::class, $result['class']);
        $this->assertStringContainsString('Invalid isolated render payload', $result['message']);
    }

    public function testWorkerReportsASecurityViolationWithItsCapabilityAndLine(): void
    {
        $payload = base64_encode(serialize([
            'state' => $this->sandbox()->isolationState(),
            'job' => ['kind' => 'string', 'template' => "line1\n{{ exec('id') }}", 'name' => 'inline'],
            'data' => [],
        ]));

        $result = json_decode((new IsolatedRenderWorker($this->manager()))->run($payload), true);

        $this->assertFalse($result['ok']);
        $this->assertSame(ForbiddenFunctionException::class, $result['class']);
        $this->assertSame('function', $result['capability']);
        $this->assertSame('exec()', $result['subject']);
        $this->assertArrayHasKey('line', $result);
    }

    public function testWorkerReportsAPlainExceptionWithoutCapabilityFields(): void
    {
        $payload = base64_encode(serialize([
            'state' => $this->sandbox()->isolationState(),
            'job' => ['view' => 'deployer::totally-missing-view'],
            'data' => [],
        ]));

        $result = json_decode((new IsolatedRenderWorker($this->manager()))->run($payload), true);

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('capability', $result);
    }

    private function rendererWith(array $command): IsolatedRenderer
    {
        return new IsolatedRenderer($command);
    }

    public function testRendererTranslatesAMemoryExhaustionStderrMessage(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('memory limit');

        $renderer = $this->rendererWith([PHP_BINARY, '-r', 'fwrite(STDERR, "Allowed memory size of X bytes exhausted"); exit(255);']);
        $renderer->render($this->sandbox(), ['kind' => 'template', 'template' => 'x'], [], ['timeout' => 5, 'memory_limit' => '32M']);
    }

    public function testRendererTranslatesATimeoutStderrMessage(): void
    {
        $this->expectException(SandboxLimitExceededException::class);
        $this->expectExceptionMessage('time limit');

        $renderer = $this->rendererWith([PHP_BINARY, '-r', 'fwrite(STDERR, "Maximum execution time of 1 second exceeded"); exit(255);']);
        $renderer->render($this->sandbox(), ['kind' => 'template', 'template' => 'x'], [], ['timeout' => 5, 'memory_limit' => '32M']);
    }

    public function testRendererReportsAGenericFailureWithTheExitCodeAndStderrTail(): void
    {
        try {
            $renderer = $this->rendererWith([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);']);
            $renderer->render($this->sandbox(), ['kind' => 'template', 'template' => 'x'], [], ['timeout' => 5, 'memory_limit' => '32M']);
            $this->fail('a non-JSON child failure must be reported');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('exit code 3', $exception->getMessage());
            $this->assertStringContainsString('boom', $exception->getMessage());
        }
    }

    public function testRendererReconstructsASecurityViolationFromTheChildsJson(): void
    {
        $json = json_encode(['ok' => false, 'class' => ForbiddenFunctionException::class, 'message' => 'Function exec() is not allowed.', 'capability' => 'function', 'subject' => 'exec()', 'line' => 7]);
        $renderer = $this->rendererWith([PHP_BINARY, '-r', 'echo '.var_export($json, true).';']);

        try {
            $renderer->render($this->sandbox(), ['kind' => 'template', 'template' => 'x'], [], ['timeout' => 5, 'memory_limit' => '32M']);
            $this->fail('the reconstructed exception must be thrown');
        } catch (ForbiddenFunctionException $exception) {
            $this->assertSame('function', $exception->capability());
            $this->assertSame('exec()', $exception->subject());
            $this->assertSame(7, $exception->templateLine());
        }
    }

    public function testRendererReconstructsAPlainSandboxExceptionFromTheChildsJson(): void
    {
        $json = json_encode(['ok' => false, 'class' => SandboxException::class, 'message' => 'boom from the child']);
        $renderer = $this->rendererWith([PHP_BINARY, '-r', 'echo '.var_export($json, true).';']);

        try {
            $renderer->render($this->sandbox(), ['kind' => 'template', 'template' => 'x'], [], ['timeout' => 5, 'memory_limit' => '32M']);
            $this->fail('the reconstructed exception must be thrown');
        } catch (SandboxException $exception) {
            $this->assertSame('boom from the child', $exception->getMessage());
        }
    }

    public function testRendererFallsBackToASandboxExceptionForAnUnknownClass(): void
    {
        $json = json_encode(['ok' => false, 'class' => 'TotallyUnknownExceptionClassXyz', 'message' => 'weird failure']);
        $renderer = $this->rendererWith([PHP_BINARY, '-r', 'echo '.var_export($json, true).';']);

        try {
            $renderer->render($this->sandbox(), ['kind' => 'template', 'template' => 'x'], [], ['timeout' => 5, 'memory_limit' => '32M']);
            $this->fail('the reconstructed exception must be thrown');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('weird failure', $exception->getMessage());
        }
    }

    public function testRemoteExceptionsThatCannotBeRebuiltFallBackToASandboxException(): void
    {
        $exception = (new ReflectionMethod(IsolatedRenderer::class, 'exception'))->invoke(null, ['class' => UnbuildableException::class, 'message' => 'remote failure']);

        $this->assertSame(SandboxException::class, $exception::class);
        $this->assertSame('remote failure', $exception->getMessage());
    }
}

final class UnbuildableException extends SandboxException
{
    public function __construct(string $message)
    {
        throw new LogicException($message);
    }
}
