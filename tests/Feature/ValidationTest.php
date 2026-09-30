<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use SytxLabs\BladeSandbox\Exceptions\SecurityViolationException;
use SytxLabs\BladeSandbox\Rules\SandboxTemplate;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;
use SytxLabs\BladeSandbox\Validation\Violation;

final class ValidationTest extends TestCase
{
    public function testValidTemplatePasses(): void
    {
        $result = $this->sandbox()->validate('<h1>{{ $name }}</h1> @foreach($items as $i) {{ $i }} @endforeach');

        $this->assertTrue($result->passes());
        $this->assertSame([], $result->violations());
    }

    public function testCompileErrorsAreReportedWithLine(): void
    {
        $result = $this->sandbox()->validate("<p>ok</p>\n\n{{ eval('x') }}", 'upload');

        $this->assertTrue($result->fails());
        $violation = $result->violations()[0];
        $this->assertSame('upload', $violation->template);
        $this->assertSame('construct', $violation->capability);
        $this->assertSame(3, $violation->line);
    }

    public function testForbiddenDirectivesAndLivewireAttributesHaveLines(): void
    {
        $this->assertSame(2, $this->sandbox()->validate("a\n@php echo 1; @endphp")->violations()[0]->line);
        $this->assertSame(3, $this->sandbox()->validate("a\nb\n<button wire:click=\"deleteEverything\">")->violations()[0]->line);
    }

    public function testAllStaticallyNamedCapabilitiesAreReported(): void
    {
        $template = <<<'BLADE'
{{ system('id') }}
{{ strtoupper($a) }}
{{ Cache::get('x') }}
{{ PHP_OS }}
{{ \SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running }}
@include('deployer::admin.secret')
<x-admin.secret />
BLADE;
        $result = $this->sandbox()->allowFunction('strtoupper')->validate($template);

        $this->assertSame(
            [
                ['function', 'system()', 1],
                ['method', 'Cache::get()', 3],
                ['property', 'constant PHP_OS', 4],
                ['property', 'SytxLabs\BladeSandbox\Tests\Fixtures\App\Options\DeploymentStatus::Running', 5],
                ['view', 'deployer::admin.secret', 6],
                ['component', 'admin.secret', 7],
            ],
            array_map(static fn (Violation $v): array => [$v->capability, $v->subject, $v->line], $result->violations()),
        );
    }

    public function testViewValidationFollowsLiteralIncludesLayoutsAndComponents(): void
    {
        $sandbox = $this->sandbox()->allowView('deployer::nested')->allowView('deployer::partials.**');

        $result = $sandbox->validateView('deployer::nested');
        $this->assertTrue($result->fails());
        $this->assertSame('deployer::partials.nested-deeper', $result->violations()[0]->template);
        $this->assertSame('system()', $result->violations()[0]->subject);
        $this->assertContains('deployer::partials.header', $result->templates());

        $page = $this->sandbox()->allowView('deployer::page')->allowView('deployer::layout')->allowView('deployer::partials.*')
            ->validateView('deployer::page');
        $this->assertTrue($page->passes(), implode("\n", $page->messages()));
        $this->assertContains('deployer::layout', $page->templates());

        $this->assertTrue($this->sandbox()->allowView('deployer::emails.evil')->validateView('deployer::emails.evil')->fails());
        $this->assertTrue($this->sandbox()->validateView('deployer::test')->fails());
    }

    public function testComponentViewsAreValidated(): void
    {
        $directory = sys_get_temp_dir().'/blade-sandbox-validate-'.getmypid();
        @mkdir($directory.'/components', 0777, true);
        file_put_contents($directory.'/components/evil.blade.php', "ok\n{{ exec('id') }}");
        View::addNamespace('vcheck', $directory);

        $result = $this->sandbox()->allowComponent('vcheck::evil')->validate('<x-vcheck::evil />');

        $this->assertTrue($result->fails());
        $this->assertSame('vcheck::components.evil', $result->violations()[0]->template);
        $this->assertSame(2, $result->violations()[0]->line);
    }

    public function testFileValidationAndThrow(): void
    {
        $file = sys_get_temp_dir().'/blade-sandbox-upload-'.getmypid().'.blade.php';
        file_put_contents($file, '{{ $deployment->name }} {{ app("config") }}');

        $result = $this->sandbox()->validateFile($file, 'upload.blade.php');
        $this->assertSame(['app()'], array_map(static fn (Violation $v) => $v->subject, $result->violations()));

        $this->expectException(SecurityViolationException::class);
        $result->throw();
    }

    public function testValidateFileReportsAMissingFile(): void
    {
        $result = $this->sandbox()->validateFile('/no/such/file-xyz.blade.php');

        $this->assertTrue($result->fails());
        $this->assertSame('file', $result->violations()[0]->capability);
    }

    public function testValidateViewReportsAMissingSourceOnceTheViewIsAllowed(): void
    {
        $result = $this->sandbox()->allowViewNamespace('deployer')->validateView('deployer::totally-missing');

        $this->assertTrue($result->fails());
        $this->assertStringContainsString('does not exist', $result->violations()[0]->message);
    }

    public function testValidateSourceReportsADisallowedLivewireComponentTag(): void
    {
        $result = $this->sandbox()->validate('<livewire:missing-component />');

        $this->assertTrue($result->fails());
        $this->assertSame('livewire-directive', $result->violations()[0]->capability);
    }

    public function testValidateSourceSkipsFurtherChecksForAFactoryBackedComponentAndReportsAMissingOne(): void
    {
        $passes = $this->sandbox()->allowComponent('needs-service', static fn (array $attributes) => null)->validate('<x-needs-service />');
        $this->assertTrue($passes->passes());

        $missing = $this->sandbox()->allowComponent('totally-fake-component')->validate('<x-totally-fake-component />');
        $this->assertTrue($missing->fails());
        $this->assertStringContainsString('does not exist', $missing->violations()[0]->message);
    }

    public function testThrowOnAPassingResultIsANoop(): void
    {
        $result = $this->sandbox()->validate('plain text');

        $this->assertSame($result, $result->throw());
    }

    public function testRuntimeOnlyChecksAreNotReportedStatically(): void
    {
        // Method calls on data can only be checked when the value is known (at render time).
        $this->assertTrue($this->sandbox()->validate('{{ $user->delete() }}')->passes());
        $this->assertTrue($this->sandbox()->allowDto(DeploymentDTO::class)->validate('{{ $d->getLabel() }}')->passes());
    }

    public function testLaravelValidationRule(): void
    {
        $rule = new SandboxTemplate($this->sandbox());

        $this->assertTrue(Validator::make(['body' => '<p>{{ $name }}</p>'], ['body' => [$rule]])->passes());

        $validator = Validator::make(['body' => "<p>\n{{ system('id') }}</p>"], ['body' => [$rule]]);
        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('Line 2: Function system() is not allowed', $validator->errors()->first('body'));
    }

    public function testCheckCommand(): void
    {
        $this->artisan('blade-sandbox:check', ['targets' => ['deployer::emails.deploy']])->assertFailed();

        config()->set('blade-sandbox.policies.mail', ['views' => ['deployer::emails.*'], 'dtos' => [DeploymentDTO::class]]);
        $this->artisan('blade-sandbox:check', ['targets' => ['deployer::emails.deploy'], '--policy' => 'mail'])->assertSuccessful();

        // The whole namespace: raw.php, nested-deeper (system()) etc. are reported.
        $this->artisan('blade-sandbox:check', ['targets' => ['deployer::']])
            ->expectsOutputToContain('deployer::partials.nested-deeper:2')
            ->expectsOutputToContain('deployer::raw')
            ->assertFailed();

        // Directories: files are named relative to the directory; literal includes are followed.
        $this->artisan('blade-sandbox:check', ['targets' => [__DIR__.'/../Fixtures/views/app']])
            ->expectsOutputToContain('includes-sandboxed:1 [view] View deployer::test is not allowed')
            ->assertFailed();
        $this->artisan('blade-sandbox:check', ['targets' => [__DIR__.'/../Fixtures/views/app/trusted.blade.php']])->assertSuccessful();

        $this->artisan('blade-sandbox:check', ['targets' => ['deployer::emails.deploy'], '--policy' => 'mail', '--json' => true])
            ->expectsOutputToContain('"passed": true')
            ->assertSuccessful();

        $this->artisan('blade-sandbox:check', ['targets' => ['not a target']])
            ->expectsOutputToContain('Unknown target')
            ->assertFailed();
    }

    public function testViolationStringAndArray(): void
    {
        $violation = new Violation('tpl', 'function', 'system', 'nope', 3);

        $this->assertSame('tpl:3 [function] nope', (string) $violation);
        $this->assertSame($violation->toArray(), $violation->__debugInfo());
        $this->assertSame('tpl [function] nope', (string) new Violation('tpl', 'function', 'system', 'nope'));
    }

    public function testSandboxTemplateRuleRejectsNonStringsAndReportsViolations(): void
    {
        $rule = new SandboxTemplate($this->sandbox());
        $messages = [];
        $fail = static function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        $rule->validate('body', ['array'], $fail);
        $rule->validate('body', "ok\n{{ eval('x') }}", $fail);

        $this->assertSame('The :attribute must be a template string.', $messages[0]);
        $this->assertStringStartsWith('Line 2: ', $messages[1]);
    }
}
