<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Isolation;

use JsonException;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use Throwable;

final readonly class IsolatedRenderWorker
{
    public function __construct(private SandboxManager $manager)
    {
    }

    public function run(string $payload): string
    {
        try {
            $decoded = unserialize((string) base64_decode(trim($payload), true), ['allowed_classes' => true]);
            return (!is_array($decoded) || !is_array($decoded['state'] ?? null) || !is_array($decoded['job'] ?? null) || !is_array($decoded['data'] ?? null)) ?
                self::json(['ok' => false, 'class' => SandboxException::class, 'message' => 'Invalid isolated render payload.']) :
                self::json(['ok' => true, 'html' => Sandbox::fromIsolationState($this->manager, $decoded['state'])->renderJob($decoded['job'], $decoded['data'])]);
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage()];
            if ($exception instanceof SecurityViolationException) {
                $result += ['capability' => $exception->capability(), 'subject' => $exception->subject(), 'line' => $exception->templateLine()];
            }
            return self::json($result);
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function json(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SandboxException('Isolated render result is not JSON-serializable: '.$exception->getMessage());
        }
    }
}
