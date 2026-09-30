<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Tests\Feature;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Artisan;
use SytxLabs\BladeSandbox\Tests\TestCase;

final class AboutCommandTest extends TestCase
{
    public function testAboutShowsThePackageSection(): void
    {
        if (! class_exists(AboutCommand::class)) {
            $this->markTestSkipped('The about command is not available.');
        }

        Artisan::call('about', ['--json' => true]);
        $about = json_decode(Artisan::output(), true);

        $section = $about['sytx_labs_blade_sandbox'] ?? null;
        $this->assertIsArray($section);
        $this->assertSame('SytxLabs', $section['author']);
        $this->assertArrayHasKey('version', $section);
        $this->assertSame('default', $section['default_policy']);
        $this->assertStringStartsWith('file (', $section['compiled_templates']);
    }
}
