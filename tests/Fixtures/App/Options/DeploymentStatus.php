<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\Options;

enum DeploymentStatus: string
{
    case Running = 'running';
    case Failed = 'failed';

    public const DEFAULT = self::Running;

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return $this === self::Failed ? 'red' : 'green';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    private static function secret(): string
    {
        return 'secret';
    }
}
