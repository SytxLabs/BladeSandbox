<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\SerializableClosure\SerializableClosure;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use Throwable;

final class RenderTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $payload, public readonly SerializableClosure|string|null $then = null, public readonly SerializableClosure|string|null $catch = null)
    {
    }

    public static function callback(Closure|string|null $callback): SerializableClosure|string|null
    {
        if (!$callback instanceof Closure) {
            return $callback;
        }
        if (!class_exists(SerializableClosure::class)) {
            throw new SandboxException('Queued rendering with closure callbacks needs laravel/serializable-closure; pass an invokable class name instead.');
        }
        return new SerializableClosure($callback);
    }

    public function handle(SandboxManager $manager): void
    {
        ['state' => $state, 'job' => $job, 'data' => $data, 'context' => $context] = $this->decode();
        $this->call($this->then, Sandbox::fromIsolationState($manager, $state)->renderJobThroughPipeline($job, $data), $context);
    }

    public function failed(Throwable $exception): void
    {
        $this->call($this->catch, $exception, $this->decode()['context']);
    }

    /** @param array<string, mixed> $context */
    private function call(SerializableClosure|string|null $callback, mixed $value, array $context): void
    {
        if ($callback === null) {
            return;
        }
        $callable = $callback instanceof SerializableClosure ? $callback->getClosure() : app($callback);
        if (!is_callable($callable)) {
            throw new SandboxException('The queued render callback ['.(is_string($callback) ? $callback : 'closure').'] is not invokable.');
        }
        $callable($value, $context);
    }

    /**
     * @return array{state: array{builder: string|null, origin: string|null, limits: string, integrity: string|null, debug: bool, log_channel?: string|null, log_level?: string|null, output?: string, output_isolation?: array{timeout: int, memory_limit: string}|null, output_author?: string|null}, job: array{kind: string, template?: string, name?: string, view?: string, path?: string}, data: array<string, mixed>, context: array<string, mixed>}
     */
    private function decode(): array
    {
        $decoded = unserialize((string) base64_decode($this->payload, true), ['allowed_classes' => true]);
        if (!is_array($decoded) || !is_array($decoded['state'] ?? null) || !is_array($decoded['job'] ?? null)) {
            throw new SandboxException('Invalid queued render payload.');
        }
        return [
            'state' => $decoded['state'],
            'job' => $decoded['job'],
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
        ];
    }
}
