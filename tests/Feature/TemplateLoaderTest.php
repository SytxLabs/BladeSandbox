<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use stdClass;
use SytxLabs\BladeSandbox\Compatibility\IlluminateViewFinderAdapter;
use SytxLabs\BladeSandbox\Contracts\TemplateLoader;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenFunctionException;
use SytxLabs\BladeSandbox\Exceptions\ForbiddenViewException;
use SytxLabs\BladeSandbox\Exceptions\TemplateIntegrityException;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Support\ViewFiles;
use SytxLabs\BladeSandbox\Templates\ArrayTemplateLoader;
use SytxLabs\BladeSandbox\Templates\EloquentTemplateLoader;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class TemplateLoaderTest extends TestCase
{
    private function manager(): SandboxManager
    {
        return $this->app->make(SandboxManager::class);
    }

    public function testArrayLoaderWithIncludesBetweenLoaderAndFileViews(): void
    {
        $this->manager()->loader('mail', new ArrayTemplateLoader([
            'welcome' => "<h1>Hello {{ \$name }}</h1>@include('mail::footer')@include('deployer::partials.header')",
            'footer' => '<footer>{{ $name }}</footer>',
        ]));

        $html = $this->sandbox()
            ->allowViewNamespace('mail')
            ->allowView('deployer::partials.header')
            ->renderView('mail::welcome', ['name' => 'Ada', 'deployment' => (object) ['name' => 'api', 'status' => 'running']]);

        $this->assertStringContainsString('<h1>Hello Ada</h1><footer>Ada</footer>', $html);
        $this->assertStringContainsString('<header>', $html);
    }

    public function testLoaderViewsAreSandboxedAndMustBeAllowed(): void
    {
        $this->manager()->loader('mail', new ArrayTemplateLoader(['evil' => '{{ system("id") }}', 'ok' => 'ok']));

        try {
            $this->sandbox()->renderView('mail::ok');
            $this->fail('Loader views must be allowed like every other view.');
        } catch (ForbiddenViewException) {
        }

        $this->expectException(ForbiddenFunctionException::class);
        $this->sandbox()->allowViewNamespace('mail')->renderView('mail::evil');
    }

    public function testCallbackLoaderAndMissingViews(): void
    {
        $this->manager()->loader('cb', static fn (string $name): ?string => $name === 'hello' ? 'Hi {{ $who }}' : null);
        $sandbox = $this->sandbox()->allowViewNamespace('cb');

        $this->assertSame('Hi you', $sandbox->renderView('cb::hello', ['who' => 'you']));
        $this->assertSame('', $sandbox->render("@includeIf('cb::missing')"));

        $this->expectException(InvalidArgumentException::class);
        $sandbox->renderView('cb::missing');
    }

    public function testEloquentLoader(): void
    {
        Schema::create('sandbox_templates', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('identifier');
            $table->text('content');
            $table->boolean('active')->default(true);
        });
        SandboxTemplate::query()->insert([
            ['identifier' => 'page', 'content' => "<main>{{ \$title }}</main>@include('cms::block')", 'active' => true],
            ['identifier' => 'block', 'content' => '<aside>block</aside>', 'active' => true],
            ['identifier' => 'draft', 'content' => 'draft', 'active' => false],
        ]);

        $loader = new EloquentTemplateLoader(SandboxTemplate::class, 'identifier', 'content', static fn (Builder $query) => $query->where('active', true));
        $this->manager()->loader('cms', $loader);
        $sandbox = $this->sandbox()->allowViewNamespace('cms');

        $this->assertSame('<main>Home</main><aside>block</aside>', $sandbox->renderView('cms::page', ['title' => 'Home']));
        $this->assertFalse($loader->exists('draft'));
        $this->assertTrue($sandbox->validateView('cms::page')->passes());

        SandboxTemplate::query()->where('identifier', 'block')->update(['content' => '{{ exec("id") }}']);
        $loader->flush();
        $this->assertTrue($sandbox->validateView('cms::page')->fails());
    }

    public function testEloquentLoaderRejectsNonModels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EloquentTemplateLoader(stdClass::class);
    }

    public function testLoadersFromConfigAndCustomDrivers(): void
    {
        $this->app['config']->set('blade-sandbox.loaders', [
            'arr' => ['driver' => 'array', 'templates' => ['a' => 'from array']],
            'custom' => ['driver' => 'upper', 'text' => 'custom'],
            'klass' => FixedLoader::class,
        ]);
        $this->app->forgetInstance(SandboxManager::class);
        $manager = $this->manager();
        $manager->extendLoader('upper', static fn (array $config): TemplateLoader => new ArrayTemplateLoader(['x' => strtoupper((string) $config['text'])]));

        $sandbox = $manager->make()->allowViewNamespace('arr')->allowViewNamespace('custom')->allowViewNamespace('klass');

        $this->assertSame('from array', $sandbox->renderView('arr::a'));
        $this->assertSame('CUSTOM', $sandbox->renderView('custom::x'));
        $this->assertSame('fixed', $sandbox->renderView('klass::anything'));
    }

    public function testUnknownLoaderDriver(): void
    {
        $this->app['config']->set('blade-sandbox.loaders', ['x' => ['driver' => 'nope']]);
        $this->app->forgetInstance(SandboxManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->manager()->sources();
    }

    public function testViewHelperAndIntegrityForLoaderViews(): void
    {
        $loader = new ArrayTemplateLoader(['page' => '<p>{{ $n }}</p>']);
        $this->manager()->loader('db', $loader);
        $sandbox = $this->sandbox()->allowViewNamespace('db');

        $this->assertSame('<p>1</p>', $sandbox->view('db::page', ['n' => 1])->render());

        $manifest = $this->manager()->manifest(['algorithm' => 'sha256', 'views' => ['db::page' => hash('sha256', '<p>{{ $n }}</p>')], 'signature' => null], false);
        $this->assertSame('<p>2</p>', $sandbox->verifyIntegrity($manifest)->renderView('db::page', ['n' => 2]));

        $loader->put('page', '<p>{{ $n }} changed</p>');
        $this->expectException(TemplateIntegrityException::class);
        $sandbox->renderView('db::page', ['n' => 3]);
    }

    public function testCallbackTemplateLoaderMemoizesAndCanBeFlushed(): void
    {
        $calls = 0;
        $loader = new \SytxLabs\BladeSandbox\Templates\CallbackTemplateLoader(function (string $name) use (&$calls): ?string {
            $calls++;

            return $name === 'x' ? 'hello' : null;
        });

        $this->assertTrue($loader->exists('x'));
        $this->assertTrue($loader->exists('x'));
        $this->assertSame(1, $calls);

        $loader->flush();
        $loader->exists('x');
        $this->assertSame(2, $calls);
    }

    public function testArrayTemplateLoader(): void
    {
        $loader = (new ArrayTemplateLoader())->put('a', 'body');

        $this->assertTrue($loader->exists('a'));
        $this->assertFalse($loader->exists('b'));
        $this->assertSame('body', $loader->source('a'));

        $this->expectException(InvalidArgumentException::class);
        $loader->source('b');
    }

    public function testTemplateSourcesExposeLoadersAndRejectInvalidNamespaces(): void
    {
        $manager = $this->app->make(SandboxManager::class);
        $sources = $manager->sources();
        $loader = new ArrayTemplateLoader(['a' => 'body']);
        $sources->addLoader('mem', $loader);

        $this->assertSame($loader, $sources->loaders()['mem']);
        $this->assertNull($sources->path('mem::a'));
        $this->assertSame($sources->finder(), $manager->renderer()->finder());
        $this->assertSame($sources, $manager->renderer()->sources());

        $this->expectException(InvalidArgumentException::class);
        $sources->addLoader('bad namespace', $loader);
    }

    public function testViewFilesIgnoreMissingDirectories(): void
    {
        $this->assertSame([], (new ViewFiles($this->app->make(SandboxManager::class)->sources()->finder()))->inDirectory(__DIR__.'/no-such-directory'));
    }

    public function testViewFactoriesWithoutAFinderAreRejected(): void
    {
        $adapter = new IlluminateViewFinderAdapter($this->createMock(FactoryContract::class));

        $this->expectException(InvalidArgumentException::class);
        $adapter->find('anything');
    }
}

final class SandboxTemplate extends Model
{
    protected $table = 'sandbox_templates';

    public $timestamps = false;
}

final class FixedLoader implements TemplateLoader
{
    public function exists(string $name): bool
    {
        return true;
    }

    public function source(string $name): string
    {
        return 'fixed';
    }
}
