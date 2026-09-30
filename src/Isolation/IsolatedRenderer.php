<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Isolation;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Sandbox;
use Throwable;

/**
 * Parent side of isolated rendering: runs the sandboxed render of one job in a child PHP process
 * (by default `php artisan blade-sandbox:render`) with a hard timeout and memory limit, and turns the
 * child's result back into HTML or the original exception.
 */
final readonly class IsolatedRenderer
{
    /** @param list<string> $command PHP binary followed by the script and its arguments */
    public function __construct(private array $command)
    {
    }

    /**
     * @param array{kind: string, template?: string, name?: string, view?: string, path?: string} $job
     * @param array<string, mixed> $data
     * @param array{timeout: int, memory_limit: string} $isolation
     *
     * @throws Throwable
     */
    public function render(Sandbox $sandbox, array $job, array $data, array $isolation): string
    {
        if (!class_exists(Process::class)) {
            throw new SandboxException('Isolated rendering needs symfony/process (composer require symfony/process).');
        }

        try {
            $payload = base64_encode(serialize(['state' => $sandbox->isolationState(), 'job' => $job, 'data' => $data]));
        } catch (SandboxException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SandboxException('Isolated rendering needs serializable template data: '.$exception->getMessage());
        }

        $command = $this->command;
        $process = new Process([array_shift($command) ?? PHP_BINARY, '-d', 'memory_limit='.$isolation['memory_limit'], '-d', 'max_execution_time='.$isolation['timeout'], '-d', 'display_errors=stderr', ...$command], null, null, $payload, (float) $isolation['timeout']);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new SandboxLimitExceededException('Isolated render exceeded the time limit of '.$isolation['timeout'].' s (process killed).');
        }

        $result = self::result($process->getOutput());
        if ($result === null) {
            $error = $process->getErrorOutput();
            if (str_contains($error, 'Allowed memory size')) {
                throw new SandboxLimitExceededException('Isolated render exceeded the memory limit of '.$isolation['memory_limit'].'.');
            }
            if (str_contains($error, 'Maximum execution time')) {
                throw new SandboxLimitExceededException('Isolated render exceeded the time limit of '.$isolation['timeout'].' s.');
            }

            throw new SandboxException('Isolated render failed (exit code '.$process->getExitCode().'): '.trim(substr($error, -500)));
        }

        if (($result['ok'] ?? false) === true) {
            return (string) ($result['html'] ?? '');
        }

        throw self::exception($result);
    }

    /** @return array<string, mixed>|null the last JSON line the child printed */
    private static function result(string $output): ?array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        try {
            $decoded = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $result */
    private static function exception(array $result): Throwable
    {
        $class = (string) ($result['class'] ?? '');
        $message = (string) ($result['message'] ?? 'Isolated render failed.');

        if (is_a($class, SecurityViolationException::class, true)) {
            $exception = new $class($message, (string) ($result['capability'] ?? 'unknown'), (string) ($result['subject'] ?? ''));
            return is_int($result['line'] ?? null) ? $exception->atLine($result['line']) : $exception;
        }

        if (is_a($class, SandboxException::class, true)) {
            try {
                return new $class($message);
            } catch (Throwable) {
                return new SandboxException($message);
            }
        }
        return new SandboxException('Isolated render failed: '.$class.': '.$message);
    }
}
