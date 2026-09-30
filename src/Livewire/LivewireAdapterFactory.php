<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

use Composer\InstalledVersions;
use Livewire\Finder\Finder;
use Livewire\Livewire;

final class LivewireAdapterFactory
{
    public static function detect(): LivewireAdapter
    {
        if (!class_exists(Livewire::class)) {
            return new NullLivewireAdapter();
        }
        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('livewire/livewire') ? (string) InstalledVersions::getVersion('livewire/livewire') : '';
        return self::forVersion($version !== '' ? $version : (class_exists(Finder::class) ? '4.0.0' : '3.0.0'));
    }

    public static function forVersion(string $version): LivewireAdapter
    {
        $major = (int) ltrim($version, 'v');

        return match (true) {
            $major >= 4 => new Livewire4Adapter(),
            $major === 3 => new Livewire3Adapter(),
            default => new NullLivewireAdapter(),
        };
    }
}
