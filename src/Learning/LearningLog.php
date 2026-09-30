<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Learning;

use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;

final class LearningLog
{
    /** @var array<string, array{capability: string, subject: string, message: string}> */
    private array $findings = [];

    public function record(SecurityViolationException $violation): void
    {
        $this->findings[$violation->capability().'|'.$violation->subject()] ??= ['capability' => $violation->capability(), 'subject' => $violation->subject(), 'message' => $violation->getMessage()];
    }

    /** @return list<array{capability: string, subject: string, message: string}> */
    public function findings(): array
    {
        return array_values($this->findings);
    }
}
