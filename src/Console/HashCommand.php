<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Console;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use SytxLabs\BladeSandbox\Integrity\IntegrityManifest;
use SytxLabs\BladeSandbox\SandboxManager;

/**
 * Creates an integrity manifest (SHA-256 of every view file of the given namespaces), signed with the integrity key (APP_KEY by default). Use it with Sandbox::verifyIntegrity() or the "integrity_manifest" policy option.
 */
final class HashCommand extends Command
{
    protected $name = 'blade-sandbox:hash';

    protected $description = 'Create a signed integrity manifest for sandboxed view namespaces';

    protected function getArguments(): array
    {
        return [
            new InputArgument('namespaces', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'View namespaces to include (e.g. "plugin")'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('output', null, InputOption::VALUE_OPTIONAL, 'Write the manifest to this file instead of printing it'),
            new InputOption('unsigned', null, InputOption::VALUE_NONE, 'Do not sign the manifest'),
        ];
    }

    /**
     * @throws JsonException
     */
    public function handle(SandboxManager $manager): int
    {
        $files = [];
        foreach ((array) $this->argument('namespaces') as $namespace) {
            $namespace = rtrim((string) $namespace, ':');
            $views = $manager->viewFiles()->forNamespace($namespace);
            if ($views === []) {
                $this->components->error('No views found for namespace ['.$namespace.'].');
                return self::FAILURE;
            }
            $files += $views;
        }

        $key = $this->option('unsigned') ? null : $manager->integrityKey();
        if ($key === null && !$this->option('unsigned')) {
            $this->components->error('No integrity key available (set APP_KEY or blade-sandbox.integrity.key), or pass --unsigned.');
            return self::FAILURE;
        }

        $manifest = IntegrityManifest::fromFiles($files, $key);
        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            if (!is_dir(dirname($output)) && !mkdir($concurrentDirectory = dirname($output), 0755, true) && !is_dir($concurrentDirectory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
            file_put_contents($output, $manifest->toJson());
            $this->components->info(count($files).' view(s) hashed, manifest written to '.$output.($manifest->isSigned() ? ' (signed).' : ' (unsigned).'));
        } else {
            $this->line($manifest->toJson());
        }

        return self::SUCCESS;
    }
}
