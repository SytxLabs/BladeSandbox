<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use SytxLabs\BladeSandbox\Events\SandboxLimitExceeded;
use SytxLabs\BladeSandbox\Events\SecurityViolationDetected;
use SytxLabs\BladeSandbox\Events\TemplateRendered;
use SytxLabs\BladeSandbox\Events\TemplateRenderFailed;
use SytxLabs\BladeSandbox\Support\Patterns;
use Throwable;

final class SandboxRecorder
{
    /** @var list<object> */
    private array $events = [];

    public function record(object $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<object> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<TemplateRendered> */
    public function rendered(?string $view = null): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof TemplateRendered && ($view === null || self::matches($view, $event->view))));
    }

    /** @return list<TemplateRenderFailed> */
    public function failures(?string $view = null): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof TemplateRenderFailed && ($view === null || self::matches($view, $event->view))));
    }

    /** @return list<SecurityViolationDetected> */
    public function violations(?string $exception = null): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof SecurityViolationDetected && ($exception === null || $event->violation instanceof $exception)));
    }

    /** Asserts that a view (exact name or pattern like "cms::**") was rendered successfully, optionally $times times. */
    public function assertRendered(string $view, ?int $times = null): self
    {
        $count = count($this->rendered($view));
        $times === null ? PHPUnit::assertGreaterThan(0, $count, 'The sandbox view ['.$view.'] was not rendered.') : PHPUnit::assertSame($times, $count, 'The sandbox view ['.$view.'] was rendered '.$count.' times instead of '.$times.'.');
        return $this;
    }

    public function assertNotRendered(string $view): self
    {
        PHPUnit::assertSame([], $this->rendered($view), 'The sandbox view ['.$view.'] was rendered unexpectedly.');
        return $this;
    }

    public function assertNothingRendered(): self
    {
        PHPUnit::assertSame([], $this->rendered(), 'Sandbox views were rendered unexpectedly.');
        return $this;
    }

    /** @param class-string<Throwable>|null $exception */
    public function assertViolation(?string $exception = null): self
    {
        PHPUnit::assertNotSame([], $this->violations($exception), 'No sandbox security violation'.($exception !== null ? ' of type ['.$exception.']' : '').' was detected.');
        return $this;
    }

    public function assertNoViolations(): self
    {
        $violations = $this->violations();
        PHPUnit::assertSame([], $violations, $violations === [] ? '' : 'Unexpected sandbox security violation: '.$violations[0]->violation->getMessage());
        return $this;
    }

    public function assertLimitExceeded(): self
    {
        PHPUnit::assertNotSame([], array_filter($this->events, static fn (object $event): bool => $event instanceof SandboxLimitExceeded), 'No sandbox limit was exceeded.');
        return $this;
    }

    public function assertFailed(string $view): self
    {
        PHPUnit::assertNotSame([], $this->failures($view), 'The sandbox view ['.$view.'] did not fail.');
        return $this;
    }

    public function assertFallbackUsed(?string $view = null): self
    {
        PHPUnit::assertNotSame([], array_filter($this->failures($view), static fn (TemplateRenderFailed $event): bool => $event->fallback !== null), 'No sandbox fallback output was used'.($view !== null ? ' for ['.$view.']' : '').'.');
        return $this;
    }

    private static function matches(string $pattern, string $view): bool
    {
        return $pattern === $view || Patterns::matches($pattern, $view);
    }
}
