<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Validation;

use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;

final readonly class ValidationResult
{
    /**
     * @param list<Violation> $violations
     * @param list<string> $templates every template that was checked (including followed includes)
     */
    public function __construct(private array $violations = [], private array $templates = [])
    {
    }

    public function passes(): bool
    {
        return $this->violations === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return list<Violation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return list<string> */
    public function templates(): array
    {
        return $this->templates;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (Violation $violation): string => (string) $violation, $this->violations);
    }

    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations], array_values(array_unique([...$this->templates, ...$other->templates])));
    }

    public function throw(): self
    {
        $first = $this->violations[0] ?? null;
        if ($first !== null) {
            throw (new SecurityViolationException((string) $first, $first->capability, $first->subject))->atLine($first->line ?? 0);
        }
        return $this;
    }

    /** @return list<array{template: string, capability: string, subject: string, message: string, line: int|null}> */
    public function toArray(): array
    {
        return array_map(static fn (Violation $violation): array => $violation->toArray(), $this->violations);
    }
}
