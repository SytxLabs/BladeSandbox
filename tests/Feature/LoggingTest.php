<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use stdClass;
use Stringable;
use SytxLabs\BladeSandbox\Audit\AuditLogger;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class LoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MemoryLogger::$entries = [];
    }

    public function testLoggerClassFromConfig(): void
    {
        BladeSandbox::configure(['logging' => ['enabled' => true, 'logger' => MemoryLogger::class, 'level' => 'error']]);

        $this->violate(BladeSandbox::make(), '{{ exec("id") }} secret-source');

        $this->assertCount(1, MemoryLogger::$entries);
        [$level, $message, $context] = MemoryLogger::$entries[0];
        $this->assertSame('error', $level);
        $this->assertSame('[blade-sandbox] Forbidden function exec()', $message);
        $this->assertSame('function', $context['capability']);
        $this->assertStringNotContainsString('secret-source', json_encode(MemoryLogger::$entries) ?: '');
    }

    public function testLoggingIsOffByDefault(): void
    {
        $this->app->instance(MemoryLogger::class, new MemoryLogger());
        BladeSandbox::configure(['logging' => ['logger' => MemoryLogger::class]]);

        $this->violate(BladeSandbox::make(), '{{ exec("id") }}');

        $this->assertSame([], MemoryLogger::$entries);
    }

    public function testUseLoggerEnablesLogging(): void
    {
        BladeSandbox::useLogger(new MemoryLogger(), 'notice');

        $this->violate(BladeSandbox::make(), '{{ route("admin.users") }}');

        $this->assertSame('notice', MemoryLogger::$entries[0][0]);
        $this->assertSame('[blade-sandbox] Forbidden function route()', MemoryLogger::$entries[0][1]);
    }

    public function testLogUsingPerSandbox(): void
    {
        $this->violate($this->sandbox()->allowFunction('__')->allowTranslations('cms.*')->logUsing(new MemoryLogger(), 'info'), '{{ __("admin.secret") }}');

        $this->assertSame('info', MemoryLogger::$entries[0][0]);
        $this->assertSame('[blade-sandbox] Forbidden translation key admin.secret', MemoryLogger::$entries[0][1]);
    }

    public function testLegacyAuditKeysStillEnableLogging(): void
    {
        config()->set('blade-sandbox.audit', ['enabled' => true]);
        BladeSandbox::configure(['logging' => ['logger' => MemoryLogger::class]]);

        $this->violate(BladeSandbox::make(), '{{ exec("id") }}');

        $this->assertCount(1, MemoryLogger::$entries);
    }

    public function testInvalidLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BladeSandbox::useLogger(new MemoryLogger(), 'loud');
    }

    public function testLoggerClassMustImplementPsr3(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->violate($this->sandbox()->logUsing(stdClass::class), '{{ exec("id") }}');
    }

    public function testChannelNameIsCarriedToIsolatedRenders(): void
    {
        $state = $this->sandbox()->logUsing('security', 'error')->isolationState();

        $this->assertSame('security', $state['log_channel']);
        $this->assertSame('error', $state['log_level']);
        $this->assertNull($this->sandbox()->logUsing(new MemoryLogger())->isolationState()['log_channel']);
    }

    public function testSummaryCoversEveryViolationCapability(): void
    {
        BladeSandbox::useLogger(new MemoryLogger());

        $this->violate(BladeSandbox::make(), "@include('deployer::admin.secret')");
        $this->assertStringContainsString('Forbidden view ', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);

        $this->violate(BladeSandbox::make(), '@include($v)', ['v' => 'unknownns::page']);
        $this->assertStringContainsString('Forbidden view namespace', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);

        $this->violate(BladeSandbox::make(), '<x-totally-unknown-component />');
        $this->assertStringContainsString('Forbidden component', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);

        $this->violate(BladeSandbox::make(), '@auth x @endauth');
        $this->assertStringContainsString('Forbidden directive', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);

        $this->violate(BladeSandbox::make()->allowLivewireDirective('click'), '<button wire:click="deleteEverything">x</button>');
        $this->assertStringContainsString('Forbidden Livewire action', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);

        $this->violate(BladeSandbox::make(), '<button wire:click="save">x</button>');
        $this->assertStringContainsString('Forbidden Livewire directive', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);

        $dto = new \SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO();
        $this->violate(BladeSandbox::make()->allowDto(\SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\TestDTO::class)->nativeDtoConversion(false), '{{ $d->toArray() }}', ['d' => $dto]);
        $this->assertStringContainsString('Forbidden DTO', MemoryLogger::$entries[array_key_last(MemoryLogger::$entries)][1]);
    }

    private function violate(Sandbox $sandbox, string $template, array $data = []): void
    {
        try {
            $sandbox->render($template, $data);
            $this->fail('expected a violation');
        } catch (SecurityViolationException) {
        }
    }

    #[DataProvider('summaries')]
    public function testAuditSummaryNamesTheCapability(string $capability, string $expected): void
    {
        $this->assertSame($expected.' subject', AuditLogger::summary(new SecurityViolationException('x', $capability, 'subject')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function summaries(): iterable
    {
        yield 'property' => ['property', 'Forbidden property'];
        yield 'view' => ['view', 'Forbidden view'];
        yield 'route' => ['route', 'Forbidden route'];
        yield 'unknown capability' => ['gizmo', 'Forbidden gizmo'];
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public static array $entries = [];

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        self::$entries[] = [$level, (string) $message, $context];
    }
}
