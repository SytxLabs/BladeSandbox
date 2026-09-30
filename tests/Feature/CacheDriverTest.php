<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Cache\FileStore;
use SytxLabs\BladeSandbox\Cache\MemoryStore;
use SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class CacheDriverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/blade-sandbox-cache-driver/'.getmypid().'-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory(dirname($this->directory));

        parent::tearDown();
    }

    /** @param array<string, mixed> $cache */
    private function manager(array $cache): SandboxManager
    {
        $this->app['config']->set('blade-sandbox.cache', $cache);
        $this->app->forgetInstance(SandboxManager::class);

        return $this->app->make(SandboxManager::class);
    }

    public function testFileDriverWithConfiguredPath(): void
    {
        $manager = $this->manager(['driver' => 'file', 'path' => $this->directory]);

        $this->assertSame('ok', $manager->make()->render('{{ "ok" }}'));
        $this->assertInstanceOf(FileStore::class, $manager->cacheStore());
        $this->assertSame($this->directory, $manager->cacheStore()->directory());
        $this->assertCount(1, glob($this->directory.'/*.php') ?: []);

        $manager->cache()->flush();
        $this->assertCount(0, glob($this->directory.'/*.php') ?: []);
    }

    public function testLegacyCachePathIsUsedWithoutCachePathSetting(): void
    {
        $this->app['config']->set('blade-sandbox.cache_path', $this->directory);
        $manager = $this->manager(['driver' => 'file', 'path' => null]);

        $this->assertSame($this->directory, $manager->cacheStore()->directory());
    }

    public function testDiskDriverUsesALocalDisk(): void
    {
        $this->app['config']->set('filesystems.disks.sandbox-cache', ['driver' => 'local', 'root' => $this->directory]);
        $manager = $this->manager(['driver' => 'disk', 'disk' => 'sandbox-cache', 'path' => 'compiled/sandbox']);

        $this->assertSame('disk', $manager->make()->render('{{ "disk" }}'));
        $this->assertSame(realpath($this->directory.'/compiled/sandbox'), realpath($manager->cacheStore()->directory()));
        $this->assertCount(1, glob($this->directory.'/compiled/sandbox/*.php') ?: []);
    }

    public function testMemoryDriverRemovesItsDirectory(): void
    {
        $store = new MemoryStore(new Filesystem(), $this->directory);
        $path = $store->put(str_repeat('a', 64), '<?php return 1;');
        $directory = $store->directory();

        $this->assertFileExists($path);
        $this->assertSame($path, $store->get(str_repeat('a', 64)));

        unset($store);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testMemoryDriverRenders(): void
    {
        $manager = $this->manager(['driver' => 'memory', 'path' => $this->directory]);

        $this->assertSame('memory', $manager->make()->render('{{ "memory" }}'));
        $this->assertInstanceOf(MemoryStore::class, $manager->cacheStore());
        $this->assertStringStartsWith($this->directory.DIRECTORY_SEPARATOR.'blade-sandbox-', $manager->cacheStore()->directory());
    }

    public function testCustomDriverViaExtendCache(): void
    {
        $manager = $this->manager(['driver' => 'custom', 'path' => $this->directory]);
        $manager->extendCache('custom', static fn (array $config): CompiledTemplateStore => new FileStore(new Filesystem(), $config['path'].'/custom'));

        $this->assertSame('custom', $manager->make()->render('{{ "custom" }}'));
        $this->assertCount(1, glob($this->directory.'/custom/*.php') ?: []);
    }

    public function testCustomDriverClass(): void
    {
        $this->app->bind(RecordingStore::class, fn (): RecordingStore => new RecordingStore(new Filesystem(), $this->directory.'/class'));
        $manager = $this->manager(['driver' => RecordingStore::class]);

        $this->assertSame('class', $manager->make()->render('{{ "class" }}'));
        $this->assertSame(1, $manager->cacheStore() instanceof RecordingStore ? $manager->cacheStore()->writes : 0);
    }

    public function testUseCacheSwitchesThePathAtRuntime(): void
    {
        $manager = $this->manager(['driver' => 'file', 'path' => $this->directory.'/a']);
        $manager->make()->render('{{ "a" }}');

        $manager->useCache($this->directory.'/b');
        $manager->make()->render('{{ "b" }}');

        $this->assertCount(1, glob($this->directory.'/a/*.php') ?: []);
        $this->assertCount(1, glob($this->directory.'/b/*.php') ?: []);
    }

    public function testUnknownDriverIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown blade-sandbox cache driver');

        $this->manager(['driver' => 'redis'])->make()->render('x');
    }

    public function testStoreRejectsKeysThatAreNotDigests(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileStore(new Filesystem(), $this->directory))->put('../../evil', '<?php');
    }

    public function testStoreRejectsAnEmptyDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new FileStore(new Filesystem(), '');
    }

    public function testClearCommandReportsTheDirectory(): void
    {
        $this->manager(['driver' => 'file', 'path' => $this->directory]);

        $this->artisan('blade-sandbox:clear')->assertSuccessful();
    }

    public function testCompiledCacheFallsBackToTheViewCompiledDirectory(): void
    {
        $directory = sys_get_temp_dir().'/blade-sandbox-compiled-'.getmypid();
        $this->app['config']->set('blade-sandbox.cache_path', null);
        $this->app['config']->set('blade-sandbox.cache', ['driver' => 'file', 'path' => null]);
        $this->app['config']->set('view.compiled', $directory);
        $this->app->forgetInstance(SandboxManager::class);

        $this->assertSame(realpath($directory) ?: $directory.DIRECTORY_SEPARATOR.'blade-sandbox', $this->app->make(SandboxManager::class)->cacheStore()->directory());
    }

    public function testCompiledTemplateCacheExposesItsStore(): void
    {
        $manager = $this->app->make(SandboxManager::class);

        $this->assertSame($manager->cacheStore(), $manager->cache()->store());
    }

    public function testDiskDriverNeedsTheFilesystemManager(): void
    {
        $manager = $this->manager(['driver' => 'disk']);
        unset($this->app['filesystem']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FilesystemManager');
        $manager->make()->render('x');
    }
}

final class RecordingStore extends FileStore
{
    public int $writes = 0;

    public function put(string $key, string $compiled): string
    {
        $this->writes++;

        return parent::put($key, $compiled);
    }
}
