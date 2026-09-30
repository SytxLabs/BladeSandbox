<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Exceptions\TemplateIntegrityException;
use SytxLabs\BladeSandbox\Facades\BladeSandbox;
use SytxLabs\BladeSandbox\Integrity\IntegrityManifest;
use SytxLabs\BladeSandbox\Livewire\LivewireAdapter;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO\DeploymentDTO;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class IntegrityTest extends TestCase
{
    private string $directory;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/blade-sandbox-integrity-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/partials', 0777, true);
        file_put_contents($this->directory.'/page.blade.php', "<h1>{{ \$title }}</h1>@include('signed::partials.footer')");
        file_put_contents($this->directory.'/partials/footer.blade.php', '<footer>ok</footer>');
        View::addNamespace('signed', $this->directory);

        $this->manifest = $this->directory.'/../integrity-'.basename($this->directory).'.json';
        $this->artisan('blade-sandbox:hash', ['namespaces' => ['signed'], '--output' => $this->manifest])->assertSuccessful();
    }

    private function render(): string
    {
        return $this->sandbox()->allowViewNamespace('signed')->verifyIntegrity($this->manifest)
            ->renderView('signed::page', ['title' => 'Hi']);
    }

    public function testUnchangedViewsRender(): void
    {
        $this->assertSame('<h1>Hi</h1><footer>ok</footer>', $this->render());
        $this->assertTrue(IntegrityManifest::load($this->manifest)->isSigned());
    }

    public function testModifiedIncludedViewIsRefused(): void
    {
        file_put_contents($this->directory.'/partials/footer.blade.php', '<footer>changed</footer>');

        $this->expectException(TemplateIntegrityException::class);
        $this->expectExceptionMessage('signed::partials.footer (contents changed)');
        $this->render();
    }

    public function testNewViewIsRefused(): void
    {
        file_put_contents($this->directory.'/partials/footer.blade.php', "@include('signed::partials.extra')");
        file_put_contents($this->directory.'/partials/extra.blade.php', 'extra');
        $this->artisan('blade-sandbox:hash', ['namespaces' => ['signed'], '--output' => $this->manifest])->assertSuccessful();
        file_put_contents($this->directory.'/partials/added.blade.php', 'added');
        file_put_contents($this->directory.'/partials/footer.blade.php', "@include('signed::partials.added')");

        $this->expectException(TemplateIntegrityException::class);
        $this->render();
    }

    public function testTamperedOrUnsignedManifestIsRefused(): void
    {
        $data = json_decode((string) file_get_contents($this->manifest), true);
        $data['views']['signed::partials.footer'] = hash('sha256', '<footer>evil</footer>');
        file_put_contents($this->manifest, json_encode($data));

        try {
            $this->render();
            $this->fail('tampered manifest must be refused');
        } catch (TemplateIntegrityException $exception) {
            $this->assertStringContainsString('invalid signature', $exception->getMessage());
        }

        $this->artisan('blade-sandbox:hash', ['namespaces' => ['signed'], '--output' => $this->manifest, '--unsigned' => true])->assertSuccessful();
        try {
            $this->render();
            $this->fail('unsigned manifest must be refused by default');
        } catch (TemplateIntegrityException $exception) {
            $this->assertStringContainsString('signature missing', $exception->getMessage());
        }

        $this->assertSame('<h1>Hi</h1><footer>ok</footer>', $this->sandbox()->allowViewNamespace('signed')
            ->verifyIntegrity($this->manifest, requireSignature: false)->renderView('signed::page', ['title' => 'Hi']));
    }

    public function testIntegrityAppliesToBoundNamespacesAndValidation(): void
    {
        BladeSandbox::sandboxNamespace('signed', $this->sandbox()->allowViewNamespace('signed')->verifyIntegrity($this->manifest));
        $this->assertSame('<h1>Hi</h1><footer>ok</footer>', view('signed::page', ['title' => 'Hi'])->render());

        file_put_contents($this->directory.'/page.blade.php', 'changed');
        $result = $this->sandbox()->allowViewNamespace('signed')->verifyIntegrity($this->manifest)->validateView('signed::page');
        $this->assertSame('integrity', $result->violations()[0]->capability);

        $this->expectException(TemplateIntegrityException::class);
        view('signed::page', ['title' => 'Hi'])->render();
    }

    public function testRawTemplatesAreNotAffected(): void
    {
        $this->assertSame('x', $this->sandbox()->verifyIntegrity($this->manifest)->render('x'));
        $this->assertSame('api', $this->sandbox()->verifyIntegrity(null)->allowDto(DeploymentDTO::class)->render('{{ $d->name }}', ['d' => new DeploymentDTO()]));
    }

    public function testPolicyConfigOption(): void
    {
        config()->set('blade-sandbox.policies.signed', ['view_namespaces' => ['signed'], 'integrity_manifest' => $this->manifest]);
        $manager = new SandboxManager($this->app, config('blade-sandbox'), $this->app->make(LivewireAdapter::class));

        file_put_contents($this->directory.'/page.blade.php', 'changed');
        $this->expectException(TemplateIntegrityException::class);
        $manager->policy('signed')->renderView('signed::page');
    }

    public function testHashCommandEdgeCases(): void
    {
        $this->artisan('blade-sandbox:hash', ['namespaces' => ['no-such-namespace-xyz']])
            ->expectsOutputToContain('No views found')
            ->assertFailed();

        config()->set('app.key', '');
        $this->artisan('blade-sandbox:hash', ['namespaces' => ['signed']])
            ->expectsOutputToContain('No integrity key available')
            ->assertFailed();

        config()->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $this->artisan('blade-sandbox:hash', ['namespaces' => ['signed'], '--unsigned' => true])->assertSuccessful();
    }

    public function testGettersAndInvalidManifestFormats(): void
    {
        $manifest = IntegrityManifest::load($this->manifest);
        $this->assertTrue($manifest->has('signed::page'));
        $this->assertFalse($manifest->has('signed::nope'));
        $this->assertArrayHasKey('signed::page', $manifest->hashes());

        try {
            IntegrityManifest::fromArray(['algorithm' => 'md5', 'views' => []]);
            $this->fail('an unknown algorithm must be rejected');
        } catch (TemplateIntegrityException $exception) {
            $this->assertStringContainsString('invalid format', $exception->getMessage());
        }

        try {
            IntegrityManifest::fromArray(['views' => ['x' => 'not-a-hash']]);
            $this->fail('an invalid hash must be rejected');
        } catch (TemplateIntegrityException $exception) {
            $this->assertStringContainsString('invalid hash', $exception->getMessage());
        }

        try {
            IntegrityManifest::load($this->directory.'/no-such-manifest.json');
            $this->fail('a missing manifest file must be rejected');
        } catch (TemplateIntegrityException $exception) {
            $this->assertStringContainsString('cannot be read', $exception->getMessage());
        }

        $malformed = $this->directory.'/malformed.json';
        file_put_contents($malformed, '{not json');
        try {
            IntegrityManifest::load($malformed);
            $this->fail('malformed JSON must be rejected');
        } catch (TemplateIntegrityException $exception) {
            $this->assertStringContainsString('cannot be read', $exception->getMessage());
        }
    }

    public function testValidateViewPassesWhenIntegrityIsUnchanged(): void
    {
        $result = $this->sandbox()->allowViewNamespace('signed')->verifyIntegrity($this->manifest)->validateView('signed::page');

        $this->assertTrue($result->passes());
    }

    public function testIntegrityManifestRejectsUnknownViews(): void
    {
        $this->expectException(TemplateIntegrityException::class);
        (new IntegrityManifest(['known' => hash('sha256', 'a')]))->assert('unknown', 'a');
    }

    public function testIntegrityManifestCannotHashUnreadableFiles(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(InvalidArgumentException::class);
            IntegrityManifest::fromFiles(['gone' => __DIR__.'/no-such-file.blade.php']);
        } finally {
            restore_error_handler();
        }
    }
}
