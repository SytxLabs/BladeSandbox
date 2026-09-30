<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use SytxLabs\BladeSandbox\Isolation\IsolatedRenderWorker;
use SytxLabs\BladeSandbox\SandboxManager;

/**
 * Child process of isolated rendering (Sandbox::isolated()). Reads the payload from STDIN and prints one JSON line. Not meant to be called manually.
 */
final class RenderCommand extends Command
{
    protected $name = 'blade-sandbox:render';

    protected $description = 'Internal: render one sandboxed template in an isolated process';

    protected $hidden = true;

    public function handle(SandboxManager $manager): int
    {
        $this->output->writeln((new IsolatedRenderWorker($manager))->run((string) stream_get_contents(STDIN) ?: ''), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
