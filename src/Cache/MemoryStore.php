<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Cache;

use Illuminate\Filesystem\Filesystem;

final class MemoryStore extends FileStore
{
    private readonly string $root;

    public function __construct(private readonly Filesystem $filesystem, ?string $base = null)
    {
        $this->root = rtrim($base ?? sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'blade-sandbox-'.bin2hex(random_bytes(8));
        parent::__construct($filesystem, $this->root);
    }

    public function __destruct()
    {
        $this->filesystem->deleteDirectory($this->root);
    }

    public function put(string $key, string $compiled): string
    {
        if (!$this->filesystem->isDirectory($this->root)) {
            $this->filesystem->ensureDirectoryExists($this->root, 0700, true);
        }
        return parent::put($key, $compiled);
    }
}
