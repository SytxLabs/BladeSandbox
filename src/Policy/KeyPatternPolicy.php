<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use Illuminate\Support\Str;

final readonly class KeyPatternPolicy
{
    /** @param list<string> $patterns */
    public function __construct(private bool $enabled = true, private array $patterns = [])
    {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function restricted(): bool
    {
        return $this->patterns !== [];
    }

    public function allows(string $key): bool
    {
        if (!$this->enabled || $key === '') {
            return false;
        }
        return $this->patterns === [] || Str::is($this->patterns, $key);
    }

    /** @return array{enabled: bool, patterns: list<string>} */
    public function describe(): array
    {
        $patterns = array_values(array_unique($this->patterns));
        sort($patterns);

        return ['enabled' => $this->enabled, 'patterns' => $patterns];
    }
}
