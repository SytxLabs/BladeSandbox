<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Security;

use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\TestCase;

abstract class SecurityTestCase extends TestCase
{
    /**
     * Asserts that rendering is rejected with a security violation and that nothing was executed.
     *
     * @param array<string, mixed> $data
     * @param class-string<SecurityViolationException> $exception
     */
    protected function assertBlocked(string $template, array $data = [], string $exception = SecurityViolationException::class, ?Sandbox $sandbox = null): void
    {
        $GLOBALS['__blade_sandbox_side_effect'] = false;

        try {
            $output = ($sandbox ?? $this->sandbox())->render($template, $data);
            $this->fail('Template was not blocked: '.$template.' (output: '.$output.')');
        } catch (SecurityViolationException $violation) {
            $this->assertInstanceOf($exception, $violation, $template.' -> '.$violation->getMessage());
        }

        $this->assertFalse($GLOBALS['__blade_sandbox_side_effect'], 'Side effect executed for '.$template);
    }
}
