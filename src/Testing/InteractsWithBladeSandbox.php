<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Sandbox;
use Throwable;

trait InteractsWithBladeSandbox
{
    /** @param array<string, mixed> $data */
    protected function assertSandboxRenders(Sandbox $sandbox, string $template, string $expected, array $data = []): void
    {
        PHPUnit::assertSame($expected, $sandbox->render($template, $data));
    }

    /** @param array<string, mixed> $data */
    protected function assertSandboxRendersView(Sandbox $sandbox, string $view, string $expected, array $data = []): void
    {
        PHPUnit::assertSame($expected, $sandbox->renderView($view, $data));
    }

    /**
     * @param class-string<Throwable> $exception
     * @param array<string, mixed> $data
     */
    protected function assertSandboxDenies(Sandbox $sandbox, string $template, string $exception = SecurityViolationException::class, array $data = []): void
    {
        try {
            $sandbox->render($template, $data);
        } catch (Throwable $thrown) {
            PHPUnit::assertInstanceOf($exception, $thrown, 'The sandbox threw ['.$thrown::class.'] instead of ['.$exception.']: '.$thrown->getMessage());
            return;
        }
        PHPUnit::fail('The sandbox rendered the template although ['.$exception.'] was expected.');
    }

    protected function assertSandboxTemplateValid(Sandbox $sandbox, string $template): void
    {
        $result = $sandbox->validate($template);
        PHPUnit::assertTrue($result->passes(), 'The template is not valid: '.implode(' ', $result->messages()));
    }

    protected function assertSandboxTemplateInvalid(Sandbox $sandbox, string $template): void
    {
        PHPUnit::assertTrue($sandbox->validate($template)->fails(), 'The template is valid although violations were expected.');
    }
}
