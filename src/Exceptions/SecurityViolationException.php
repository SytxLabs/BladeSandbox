<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

class SecurityViolationException extends SandboxException
{
    private ?int $templateLine = null;

    public function __construct(string $message, private readonly string $capability = 'unknown', private readonly string $subject = '')
    {
        parent::__construct($message);
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function templateLine(): ?int
    {
        return $this->templateLine;
    }

    public function atLine(int $line): static
    {
        $this->templateLine ??= $line;
        return $this;
    }
}
