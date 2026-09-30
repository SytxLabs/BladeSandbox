<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\Options;

final class Magic
{
    public static function __callStatic(string $name, array $arguments): string
    {
        $GLOBALS['__blade_sandbox_side_effect'] = true;

        return 'magic';
    }
}
