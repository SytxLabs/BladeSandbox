<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use ArrayAccess;
use Illuminate\Support\Facades\Artisan;
use SytxLabs\BladeSandbox\PolicyConfiguration;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class LearningModeTest extends TestCase
{
    private const TEMPLATE = <<<'BLADE'
        <h1>{{ $article->title }}</h1>
        <p>{{ $article->summary() }} {{ strtoupper($tag) }}</p>
        @foreach($article->tags as $t){{ $t }},@endforeach
        @include('deployer::partials.footer')
        @csrf
        BLADE;

    protected function tearDown(): void
    {
        unset($GLOBALS['__learning_side_effect']);
        parent::tearDown();
    }

    public function testLearnCollectsWhatTheTemplateNeeds(): void
    {
        $suggestion = $this->sandbox()->learn(self::TEMPLATE, ['article' => new LearningArticle(), 'tag' => 'x']);
        $config = $suggestion->toConfig();

        $this->assertSame(['strtoupper'], $config['functions']);
        $this->assertSame(['deployer::partials.footer'], $config['views']);
        $this->assertSame(['csrf'], $config['directives']);
        $this->assertSame([LearningArticle::class => ['tags', 'title']], $config['properties']);
        $this->assertSame([LearningArticle::class => ['summary']], $config['methods']);
        $this->assertSame([], $suggestion->flagged());
        $this->assertStringContainsString("->allowFunction('strtoupper')", $suggestion->toPhp());
    }

    public function testTheSuggestionMakesTheTemplateRender(): void
    {
        $data = ['article' => new LearningArticle(), 'tag' => 'x', 'deployment' => new DeploymentDTO()];
        $suggestion = $this->sandbox()->learn(self::TEMPLATE, $data);
        $this->assertSame([], $suggestion->errors());
        $config = $suggestion->toConfig();

        $html = PolicyConfiguration::apply($this->sandbox(), $config)->render(self::TEMPLATE, $data);

        $this->assertStringContainsString('<h1>Hello</h1>', $html);
        $this->assertStringContainsString('<p>Short X</p>', $html);
        $this->assertStringContainsString('<footer>api: running</footer>', $html);
        $this->assertStringContainsString('a,b,', $html);
    }

    public function testDeniedOperationsAreNeverExecutedAndRiskyOnesAreFlagged(): void
    {
        $suggestion = $this->sandbox()->learn(
            '{{ $article->delete() }} {{ learning_side_effect() }} {{ exec("id") }} {{ $items->filter("system") }}',
            ['article' => new LearningArticle(), 'items' => collect([1])],
        );

        $this->assertArrayNotHasKey('__learning_side_effect', $GLOBALS, 'A denied operation was executed.');
        $flagged = implode("\n", $suggestion->flagged());
        $this->assertStringContainsString('delete()', $flagged);
        $this->assertStringContainsString('exec()', $flagged);
        $this->assertStringNotContainsString('exec', json_encode($suggestion->toConfig(), JSON_THROW_ON_ERROR));
        // filter() itself is missing; its callable argument would still be rejected once filter() is allowed.
        $this->assertSame(['filter'], $suggestion->toConfig()['methods'][\Illuminate\Support\Collection::class]);

        // Constructs that can never be compiled end the analysis and are reported.
        $php = $this->sandbox()->learn('@php echo 1; @endphp');
        $this->assertStringContainsString('@php', implode("\n", [...$php->flagged(), ...$php->errors()]));
    }

    public function testDeniedDeleteIsNotExecutedWhileLearning(): void
    {
        $article = new LearningArticle();
        $this->sandbox()->learn('{{ $a->delete() }}{{ learning_side_effect() }}', ['a' => $article]);

        $this->assertFalse($article->deleted);
        $this->assertArrayNotHasKey('__learning_side_effect', $GLOBALS);
    }

    public function testLearnView(): void
    {
        $suggestion = $this->sandbox()->learnView('deployer::partials.footer', ['deployment' => new DeploymentDTO()]);

        $this->assertContains('deployer::partials.footer', $suggestion->toConfig()['views'] ?? []);
        $this->assertSame([DeploymentDTO::class => ['getLabel']], $suggestion->toConfig()['methods'] ?? []);
    }

    public function testStaticSuggestionAndMerging(): void
    {
        $a = $this->sandbox()->suggestPolicy("{{ ucfirst('x') }}\n{!! \$html !!}\n@lang('x')");
        $b = $this->sandbox()->suggestPolicy('{{ route("shop.index") }}');

        $merged = $a->merge($b)->toConfig();
        $this->assertSame(['route', 'ucfirst'], $merged['functions']);
        $this->assertTrue($merged['raw_echo']);
        $this->assertStringContainsString('allowRawEcho()', $a->toPhp());
        $this->assertTrue($this->sandbox()->suggestPolicy('plain')->isEmpty());
    }

    public function testStaticSuggestionOfRoutesAndTranslationsOnceTheFunctionIsAllowed(): void
    {
        $routes = $this->sandbox()->allowFunction('route')->allowRoutes('shop.*')->suggestPolicy('{{ route("other.name") }}');
        $this->assertSame(['other.name'], $routes->toConfig()['routes']);

        $translations = $this->sandbox()->allowFunction('trans')->allowTranslations('cms.*')->suggestPolicy('{{ trans("some.key") }}');
        $this->assertSame(['some.key'], $translations->toConfig()['translations']);
    }

    public function testStaticSuggestionOfADisallowedLivewireAction(): void
    {
        $suggestion = $this->sandbox()->allowLivewireDirective('click')->suggestPolicy('<button wire:click="deleteEverything">x</button>');

        $this->assertSame(['deleteEverything'], $suggestion->toConfig()['livewire']['actions']);
        $this->assertStringContainsString("->allowLivewireAction('deleteEverything')", $suggestion->toPhp());
    }

    public function testApplicationDirectivesAreFlaggedRatherThanSuggested(): void
    {
        \Illuminate\Support\Facades\Blade::directive('reallyDangerous', static fn () => '<?php system("id"); ?>');

        $suggestion = $this->sandbox()->suggestPolicy('@reallyDangerous');

        $this->assertNotSame([], $suggestion->flagged());
    }

    public function testMergingSuggestionsForTheSameClassCombinesTheirMembers(): void
    {
        $a = $this->sandbox()->learn('{{ $o->foo() }}', ['o' => new LearningArticle()]);
        $b = $this->sandbox()->learn('{{ $o->summary() }}', ['o' => new LearningArticle()]);

        $merged = $a->merge($b)->toConfig();
        $this->assertSame(['foo', 'summary'], $merged['methods'][LearningArticle::class]);
    }

    public function testLearnFlagsUnroutableLoopPropertiesAndViewNamespaceViolations(): void
    {
        $suggestion = $this->sandbox()->learn(
            '@foreach([1] as $x){{ $loop->bogus }}@endforeach @include($v)',
            ['v' => 'unknown-namespace::page'],
        );

        $this->assertNotSame([], $suggestion->flagged());
    }

    public function testPolicySuggestionDispatchesEveryFindingCapability(): void
    {
        $suggestion = new \SytxLabs\BladeSandbox\Learning\PolicySuggestion([
            ['capability' => 'view-namespace', 'subject' => 'myns', 'message' => 'View namespace myns is not allowed in the sandbox.'],
            ['capability' => 'dto', 'subject' => 'Some\\Dto', 'message' => 'DTO Some\\Dto is not allowed in the sandbox.'],
            ['capability' => 'method', 'subject' => 'App\\Foo::bar()', 'message' => 'Method App\\Foo::bar() (facades are never available in the sandbox) is not allowed in the sandbox.'],
        ]);

        $config = $suggestion->toConfig();
        $this->assertSame(['myns'], $config['view_namespaces']);
        $this->assertNotSame([], $suggestion->flagged());
    }

    public function testSuggestionCoversArrayAccessAndStringConversionInToPhp(): void
    {
        $suggestion = $this->sandbox()->learn('{{ $o[0] }}{{ (string) $s }}', ['o' => new LearningArrayAccess(), 's' => new LearningStringable()]);

        $config = $suggestion->toConfig();
        $this->assertSame([LearningArrayAccess::class], $config['array_access']);
        $this->assertSame([LearningStringable::class], $config['string_conversion']);
        $this->assertStringContainsString('allowArrayAccess('.var_export(LearningArrayAccess::class, true).')', $suggestion->toPhp());
        $this->assertStringContainsString('allowStringConversion('.var_export(LearningStringable::class, true).')', $suggestion->toPhp());
    }

    public function testLearnExercisesAlternatePropertyOffsetAndIncludeForms(): void
    {
        $template = <<<'BLADE'
            {{ $user?->title }}
            {{ isset($user->title) ? 1 : 0 }}
            {{ isset($arr['x']) ? 1 : 0 }}
            {{ \DateTimeInterface::ATOM }}
            @foreach((array) $user as $k => $v){{ $k }}@endforeach
            @includeIf('deployer::partials.footer')
            @includeWhen(true, 'deployer::partials.footer')
            @includeUnless(false, 'deployer::partials.footer')
            @includeFirst(['missing::x', 'deployer::partials.footer'])
            BLADE;

        $suggestion = $this->sandbox()->learn($template, ['user' => null, 'arr' => ['x' => 1], 'deployment' => new DeploymentDTO()]);

        // None of these constructs are allowed by a fresh policy, so every attempt is caught and recorded
        // instead of crashing the analysis; the include directives all point at the same allowed-looking view.
        $this->assertFalse($suggestion->isEmpty());
        $this->assertContains('deployer::partials.footer', $suggestion->toConfig()['views'] ?? []);
    }

    public function testPolicyCommand(): void
    {
        Artisan::call('blade-sandbox:policy', ['targets' => ['deployer::test'], '--json' => true]);
        $output = json_decode(Artisan::output(), true);

        $this->assertIsArray($output);
        $this->assertContains('deployer::test', $output['config']['views']);

        $this->artisan('blade-sandbox:policy', ['targets' => ['deployer::test']])->assertSuccessful();
        $this->artisan('blade-sandbox:policy', ['targets' => ['not a target']])->assertFailed();

        // A template with nothing missing at all: the policy already covers everything it names literally.
        $plain = sys_get_temp_dir().'/blade-sandbox-policy-cmd-plain-'.getmypid().'.blade.php';
        file_put_contents($plain, 'just plain text');
        $this->artisan('blade-sandbox:policy', ['targets' => [$plain]])
            ->expectsOutputToContain('already allows everything');

        // A dangerous function is flagged (warned about) rather than suggested, and a compile error is reported.
        $file = sys_get_temp_dir().'/blade-sandbox-policy-cmd-'.getmypid().'.blade.php';
        file_put_contents($file, '{{ exec("id") }}');
        $this->artisan('blade-sandbox:policy', ['targets' => [$file]])
            ->expectsOutputToContain('Not suggested');
    }

    public function testPolicyCommandReportsTemplatesThatDoNotCompile(): void
    {
        $file = sys_get_temp_dir().'/blade-sandbox-policy-broken-'.getmypid().'.blade.php';
        file_put_contents($file, '@forelse($items as $i) x');

        try {
            $this->artisan('blade-sandbox:policy', ['targets' => [$file]])->expectsOutputToContain('forelse');
        } finally {
            unlink($file);
        }
    }
}

final class LearningArticle
{
    public string $title = 'Hello';

    /** @var list<string> */
    public array $tags = ['a', 'b'];

    public bool $deleted = false;

    public function summary(): string
    {
        return 'Short';
    }

    public function foo(): string
    {
        return 'foo';
    }

    public function delete(): void
    {
        $this->deleted = true;
    }
}

function learning_side_effect(): string
{
    $GLOBALS['__learning_side_effect'] = true;

    return 'executed';
}

final class LearningStringable
{
    public function __toString(): string
    {
        return 'x';
    }
}

final class LearningArrayAccess implements ArrayAccess
{
    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return 'x';
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}
