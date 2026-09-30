<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Console;

use Illuminate\Console\Command;
use SytxLabs\BladeSandbox\SandboxManager;

/**
 * Removes compiled sandbox templates (the cache is content addressed and grows with every distinct template/policy combination; `view:clear` does not descend into its sub directory).
 */
final class ClearCommand extends Command
{
    /** @var string */
    protected $name = 'blade-sandbox:clear';

    /** @var string */
    protected $description = 'Remove all compiled Blade sandbox templates';

    public function handle(SandboxManager $manager): int
    {
        $manager->cache()->flush();
        $this->components->info('Compiled sandbox templates cleared successfully ('.$manager->cacheStore()->directory().').');

        return self::SUCCESS;
    }
}
