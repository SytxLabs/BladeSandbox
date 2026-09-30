<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use Countable;
use Illuminate\Support\LazyCollection;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\SandboxLimitExceededException;

final class SandboxContext
{
    /** @var array<string, string> */
    public array $sections = [];

    /** @var list<string> */
    public array $sectionStack = [];

    /** @var array<string, list<string>> */
    public array $pushes = [];

    /** @var array<string, list<string>> */
    public array $prepends = [];

    /** @var list<string> */
    public array $pushStack = [];

    /** Open @markdown blocks. */
    public int $markdownDepth = 0;

    /** @var list<LoopState> */
    public array $loops = [];

    /** @var array<string, true> */
    public array $once = [];

    /** @var list<ComponentFrame> */
    public array $components = [];

    /** @var list<array<string, mixed>> data of the components currently being rendered (for @aware) */
    public array $componentData = [];

    /** @var list<string> */
    private array $templates = [];

    private int $iterations = 0;

    private readonly float $startedAt;

    private readonly int $startMemory;

    public readonly int $maxDepth;

    public readonly int $maxOutputBytes;

    public function __construct(public readonly SandboxLimits $limits = new SandboxLimits())
    {
        $this->maxDepth = $limits->maxDepth;
        $this->maxOutputBytes = $limits->maxOutputBytes;
        $this->startedAt = hrtime(true) / 1e6;
        $this->startMemory = memory_get_usage();
    }

    public function tick(bool $iteration = true): void
    {
        $limits = $this->limits;
        if ($iteration && $limits->maxIterations > 0 && ++$this->iterations > $limits->maxIterations) {
            throw new SandboxLimitExceededException('Maximum of '.$limits->maxIterations.' loop iterations per render exceeded (possible infinite loop).');
        }
        if ($limits->timeoutMs > 0 && (hrtime(true) / 1e6) - $this->startedAt > $limits->timeoutMs) {
            throw new SandboxLimitExceededException('Sandbox render time limit of '.$limits->timeoutMs.' ms exceeded (possible infinite loop).');
        }
        if ($limits->maxMemoryBytes > 0 && memory_get_usage() - $this->startMemory > $limits->maxMemoryBytes) {
            throw new SandboxLimitExceededException('Sandbox memory limit of '.$limits->maxMemoryBytes.' bytes exceeded.');
        }
        if ($limits->maxOutputBytes > 0 && (int) ob_get_length() > $limits->maxOutputBytes) {
            throw new SandboxLimitExceededException('Sandbox output exceeds the limit of '.$limits->maxOutputBytes.' bytes.');
        }
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    public function enter(string $template): void
    {
        if (count($this->templates) >= $this->maxDepth) {
            throw new SandboxLimitExceededException('Maximum sandbox include depth of '.$this->maxDepth.' exceeded.');
        }
        $this->templates[] = $template;
        $this->tick(false);
    }

    public function leave(): void
    {
        array_pop($this->templates);
    }

    public function depth(): int
    {
        return count($this->templates);
    }

    public function currentTemplate(): string
    {
        return $this->templates[count($this->templates) - 1] ?? '';
    }

    public function assertOutputSize(string $output): string
    {
        if ($this->maxOutputBytes > 0 && strlen($output) > $this->maxOutputBytes) {
            throw new SandboxLimitExceededException('Sandbox output exceeds the limit of '.$this->maxOutputBytes.' bytes.');
        }
        return $output;
    }

    //#region Loops ($loop)

    /**
     * @param iterable<mixed, mixed> $data
     *
     * @return iterable<mixed, mixed>
     */
    public function addLoop(iterable $data): iterable
    {
        $this->loops[] = new LoopState(is_array($data) || ($data instanceof Countable && !$data instanceof LazyCollection) ? count($data) : null, count($this->loops) + 1, $this->loops[count($this->loops) - 1] ?? null);
        return $data;
    }

    public function incrementLoop(): void
    {
        $this->tick();

        $loop = $this->loops[count($this->loops) - 1] ?? null;
        if ($loop === null) {
            throw new SandboxException('Loop state is corrupted.');
        }

        $loop->iteration++;
        $loop->index = $loop->iteration - 1;
        $loop->first = $loop->iteration === 1;
        $loop->odd = $loop->iteration % 2 === 1;
        $loop->even = $loop->iteration % 2 === 0;
        if ($loop->count !== null) {
            $loop->remaining = $loop->count - $loop->iteration;
            $loop->last = $loop->iteration === $loop->count;
        }
    }

    public function popLoop(): void
    {
        array_pop($this->loops);
    }

    public function currentLoop(): ?LoopState
    {
        return $this->loops[count($this->loops) - 1] ?? null;
    }
    //#endregion Loops ($loop)
}
