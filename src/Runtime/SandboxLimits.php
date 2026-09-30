<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

final readonly class SandboxLimits
{
    /**
     * @param int $maxDepth nested includes / layouts / components
     * @param int $maxOutputBytes 0 = unlimited
     * @param int $maxIterations loop iterations per render, 0 = unlimited
     * @param int $timeoutMs wall-clock time per render in milliseconds, 0 = unlimited
     * @param int $maxMemoryBytes memory growth during the render, 0 = unlimited
     */
    public function __construct(public int $maxDepth = 32, public int $maxOutputBytes = 0, public int $maxIterations = 1_000_000, public int $timeoutMs = 10_000, public int $maxMemoryBytes = 0)
    {
    }

    /** @param array<string, mixed> $config the "blade-sandbox" configuration ("limits" group or the legacy flat keys) */
    public static function fromConfig(array $config): self
    {
        $value = static fn (string $key, int $default): int => (int) ($config[$key] ?? (is_array($config['limits'] ?? null) ? $config['limits'] : [])[$key] ?? $default);
        return new self(max(1, $value('max_depth', 32)), max(0, $value('max_output_bytes', 0)), max(0, $value('max_iterations', 1_000_000)), max(0, $value('timeout_ms', 10_000)), max(0, $value('max_memory_bytes', 0)));
    }

    /** @param array<string, int> $changes */
    public function with(array $changes): self
    {
        return new self(max(1, $changes['maxDepth'] ?? $this->maxDepth), max(0, $changes['maxOutputBytes'] ?? $this->maxOutputBytes), max(0, $changes['maxIterations'] ?? $this->maxIterations), max(0, $changes['timeoutMs'] ?? $this->timeoutMs), max(0, $changes['maxMemoryBytes'] ?? $this->maxMemoryBytes));
    }
}
