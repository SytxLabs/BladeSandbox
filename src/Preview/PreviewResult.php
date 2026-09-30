<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Preview;

use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Validation\ValidationResult;
use SytxLabs\BladeSandbox\Validation\Violation;
use Throwable;

final readonly class PreviewResult
{
    public function __construct(public string $view, public ?string $html, public ValidationResult $validation, public ?Throwable $error = null, public float $durationMs = 0.0)
    {
    }

    public function passes(): bool
    {
        return $this->error === null && $this->validation->passes();
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function html(): string
    {
        return $this->html ?? '';
    }

    /** @return list<array{message: string, line: int|null, capability: string|null}> */
    public function errors(): array
    {
        $errors = array_map(static fn (Violation $violation): array => [
            'message' => $violation->message,
            'line' => $violation->line,
            'capability' => $violation->capability,
        ], $this->validation->violations());

        if ($this->error !== null) {
            $message = $this->error->getMessage();
            $duplicate = array_filter($errors, static fn (array $error): bool => $error['message'] === $message);
            if ($duplicate === []) {
                $errors[] = [
                    'message' => $message,
                    'line' => $this->error instanceof SecurityViolationException ? $this->error->templateLine() : null,
                    'capability' => $this->error instanceof SecurityViolationException ? $this->error->capability() : null,
                ];
            }
        }

        return $errors;
    }

    /** @return array{view: string, passes: bool, html: string|null, errors: list<array{message: string, line: int|null, capability: string|null}>, duration_ms: float} */
    public function toArray(): array
    {
        return ['view' => $this->view, 'passes' => $this->passes(), 'html' => $this->html, 'errors' => $this->errors(), 'duration_ms' => $this->durationMs];
    }
}
