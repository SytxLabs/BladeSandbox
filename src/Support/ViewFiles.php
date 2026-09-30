<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SytxLabs\BladeSandbox\Compatibility\ViewFinderAdapter;

final readonly class ViewFiles
{
    public function __construct(private ViewFinderAdapter $finder)
    {
    }

    /** @return array<string, string> view name => path */
    public function forNamespace(string $namespace): array
    {
        $names = [];
        foreach ($this->finder->namespaceDirectories($namespace) as $directory) {
            foreach ($this->inDirectory($directory, $namespace.'::') as $name => $path) {
                $names[$name] = true;
            }
        }
        $views = [];
        foreach (array_keys($names) as $name) {
            if (Patterns::isValidName($name) && $this->finder->exists($name)) {
                $views[$name] = $this->finder->find($name);
            }
        }
        ksort($views);
        return $views;
    }

    /** @return array<string, string> name => path */
    public function inDirectory(string $directory, string $prefix = ''): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $views = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            foreach ($this->finder->extensions() as $extension) {
                if (str_ends_with($relative, '.'.$extension)) {
                    $views[$prefix.str_replace(DIRECTORY_SEPARATOR, '.', substr($relative, 0, -strlen($extension) - 1))] = $file->getPathname();
                    break;
                }
            }
        }
        ksort($views);

        return $views;
    }
}
