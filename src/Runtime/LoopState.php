<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

final class LoopState
{
    public int $iteration = 0;

    public int $index = 0;

    public ?int $remaining;

    public ?bool $last;

    public bool $first = true;

    public bool $odd = false;

    public bool $even = true;

    public function __construct(public readonly ?int $count, public readonly int $depth, public readonly ?self $parent)
    {
        $this->remaining = $count;
        $this->last = $count !== null ? $count === 1 : null;
    }
}
