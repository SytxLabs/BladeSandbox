<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayObject;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenMethodException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenTranslationException;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class TranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $translator = $this->app->make('translator');
        $translator->addLines(['cms.title' => 'Hello :name', 'cms.items' => '{0} none|{1} one|[2,*] :count items', 'internal.secret' => 'secret'], 'en');
        $translator->setLocale('en');
    }

    public function testTranslationsAreAvailableByDefault(): void
    {
        $sandbox = $this->sandbox();

        $this->assertSame('Hello Ada', $sandbox->render('{{ __("cms.title", ["name" => "Ada"]) }}'));
        $this->assertSame('Hello Ada', $sandbox->render('{{ trans("cms.title", ["name" => $n]) }}', ['n' => 'Ada']));
        $this->assertSame('3 items', $sandbox->render('{{ trans_choice("cms.items", 3) }}'));
        $this->assertSame('Hello &lt;b&gt;', $sandbox->render("@lang('cms.title', ['name' => '<b>'])"));
        $this->assertSame('one', $sandbox->render("@choice('cms.items', 1)"));
        $this->assertSame('Hello &lt;b&gt;', $sandbox->render('{{ __("cms.title", ["name" => "<b>"]) }}'));
    }

    public function testTranslationsCanBeSwitchedOff(): void
    {
        $sandbox = $this->sandbox()->withoutTranslations();

        try {
            $sandbox->render('{{ __("cms.title") }}');
            $this->fail('__() must be denied.');
        } catch (ForbiddenFunctionException) {
        }

        $this->expectException(ForbiddenDirectiveException::class);
        $sandbox->render("@lang('cms.title')");
    }

    public function testPatternsAndDenyRules(): void
    {
        $sandbox = $this->sandbox()->allowTranslations('cms.*');
        $this->assertSame('Hello x', $sandbox->render('{{ __("cms.title", ["name" => "x"]) }}'));

        foreach (['{{ __("internal.secret") }}', "@lang('internal.secret')", '{{ __($k) }}'] as $template) {
            try {
                $sandbox->render($template, ['k' => 'internal.secret']);
                $this->fail($template.' must be denied.');
            } catch (ForbiddenTranslationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ForbiddenTranslationException::class);
        $this->sandbox()->denyTranslation('internal.*')->render('{{ trans("internal.secret") }}');
    }

    public function testTheTranslatorAndGroupsAreNeverReturned(): void
    {
        try {
            $this->sandbox()->render('{{ trans()->get("x") }}');
            $this->fail('trans() without a key must be denied.');
        } catch (ForbiddenFunctionException) {
        }

        // A group key returns the key, never the whole translation group.
        $this->assertSame('cms', $this->sandbox()->render('{{ __("cms") }}'));
    }

    public function testReplacementObjectsAreChecked(): void
    {
        $this->expectException(ForbiddenMethodException::class);

        $this->sandbox()->render('{{ __("cms.title", ["name" => $o]) }}', ['o' => new ArrayObject()]);
    }

    public function testValidationReportsDeniedLiteralKeys(): void
    {
        $result = $this->sandbox()->allowTranslations('cms.*')->validate("{{ __('cms.title') }}\n@lang('internal.secret')\n{{ trans_choice('internal.x', 1) }}");

        $this->assertSame([2, 3], array_map(static fn ($v) => $v->line, $result->violations()));
        $this->assertSame('translation', $result->violations()[0]->capability);
    }

    public function testConfigKeys(): void
    {
        $off = PolicyConfiguration::apply($this->sandbox(), ['translations' => false]);
        $this->assertTrue($off->validate('{{ __("cms.title") }}')->fails());

        $restricted = PolicyConfiguration::apply($this->sandbox(), ['translations' => ['cms.*'], 'deny_translations' => ['cms.items']]);
        $this->assertTrue($restricted->validate('{{ __("cms.title") }}')->passes());
        $this->assertTrue($restricted->validate('{{ __("cms.items") }}')->fails());
    }

    public function testDenyingTheDirectiveStillWins(): void
    {
        $this->expectException(ForbiddenDirectiveException::class);

        $this->sandbox()->denyDirective('lang')->render("@lang('cms.title')");
    }
}
