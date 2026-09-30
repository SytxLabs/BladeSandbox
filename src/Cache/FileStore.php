<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Cache;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Contracts\CompiledTemplateStore;

class FileStore implements CompiledTemplateStore
{
    private readonly string $directory;

    public function __construct(private readonly Filesystem $files, string $directory)
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new InvalidArgumentException('The blade-sandbox cache directory must not be empty.');
        }
        $this->directory = $directory;
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        return $this->files->exists($path) ? $path : null;
    }

    public function put(string $key, string $compiled): string
    {
        $path = $this->path($key);

        $this->files->ensureDirectoryExists($this->directory, 0755, true);
        $temporary = $path.'.'.Str::random(6).'.tmp';
        $this->files->put($temporary, $compiled);
        $this->files->move($temporary, $path);

        return $path;
    }

    public function flush(): void
    {
        foreach ($this->files->glob($this->directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $this->files->delete($file);
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private function path(string $key): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1) {
            throw new InvalidArgumentException('Invalid compiled template key.');
        }
        return $this->directory.DIRECTORY_SEPARATOR.$key.'.php';
    }
}
