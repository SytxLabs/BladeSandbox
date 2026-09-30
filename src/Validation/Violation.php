<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Validation;

use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;

/**
 * One problem found while validating a template (structural information only, no template contents).
 */
final readonly class Violation
{
    public function __construct(public string $template, public string $capability, public string $subject, public string $message, public ?int $line = null)
    {
    }

    public function __toString(): string
    {
        return $this->template.($this->line !== null ? ':'.$this->line : '').' ['.$this->capability.'] '.$this->message;
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public static function fromException(SecurityViolationException $exception, string $template): self
    {
        return new self($template, $exception->capability(), $exception->subject(), $exception->getMessage(), $exception->templateLine());
    }

    /** @return array{template: string, capability: string, subject: string, message: string, line: int|null} */
    public function toArray(): array
    {
        return [
            'template' => $this->template,
            'capability' => $this->capability,
            'subject' => $this->subject,
            'message' => $this->message,
            'line' => $this->line,
        ];
    }
}
