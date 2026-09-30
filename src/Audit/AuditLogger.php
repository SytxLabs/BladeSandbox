<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Audit;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;

final class AuditLogger
{
    /** @var list<array{capability: string, subject: string, template: string}> */
    private array $recorded = [];

    public function __construct(private readonly ?LoggerInterface $logger = null, private readonly bool $enabled = false, private readonly string $level = LogLevel::WARNING)
    {
    }

    public function level(): string
    {
        return $this->level;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->logger, $enabled, $this->level);
    }

    public function record(SecurityViolationException $violation, string $template = ''): void
    {
        if (!$this->enabled) {
            return;
        }
        $entry = ['capability' => $violation->capability(), 'subject' => $violation->subject(), 'template' => $template];
        $this->recorded[] = $entry;
        $this->logger?->log($this->level, '[blade-sandbox] '.self::summary($violation), $entry);
    }

    /** @return list<array{capability: string, subject: string, template: string}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public static function summary(SecurityViolationException $violation): string
    {
        return match ($violation->capability()) {
            'method' => 'Forbidden method',
            'property' => 'Forbidden property',
            'function' => 'Forbidden function',
            'view' => 'Forbidden view',
            'view-namespace' => 'Forbidden view namespace',
            'component' => 'Forbidden component',
            'directive' => 'Forbidden directive',
            'livewire-directive' => 'Forbidden Livewire directive',
            'livewire-action' => 'Forbidden Livewire action',
            'dto' => 'Forbidden DTO',
            'route' => 'Forbidden route',
            'translation' => 'Forbidden translation key',
            default => 'Forbidden '.$violation->capability(),
        } . ' ' . $violation->subject();
    }
}
