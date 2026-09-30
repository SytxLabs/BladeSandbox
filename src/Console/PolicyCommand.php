<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Console;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use SytxLabs\BladeSandbox\Console\Concerns\ResolvesTemplateTargets;
use SytxLabs\BladeSandbox\Learning\PolicySuggestion;
use SytxLabs\BladeSandbox\SandboxManager;

/**
 * Suggests the policy additions templates need (static analysis, nothing is rendered).
 */
final class PolicyCommand extends Command
{
    use ResolvesTemplateTargets;

    protected $name = 'blade-sandbox:policy';

    protected $description = 'Suggest the minimal policy additions the given Blade templates need';

    protected function getArguments(): array
    {
        return [
            new InputArgument('targets', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Directories, files, view namespaces ("plugin::") or view names ("plugin::page")'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('policy', null, InputOption::VALUE_OPTIONAL, 'Compare against this policy from config/blade-sandbox.php (default: the default policy)'),
            new InputOption('json', null, InputOption::VALUE_NONE, 'Output the suggestion as JSON'),
        ];
    }

    /**
     * @throws JsonException
     */
    public function handle(SandboxManager $manager): int
    {
        $policy = $this->option('policy');
        $sandbox = is_string($policy) && $policy !== '' ? $manager->policy($policy) : $manager->default();

        $suggestion = new PolicySuggestion();
        foreach ((array) $this->argument('targets') as $target) {
            $templates = $this->resolveTarget($manager, (string) $target);
            if ($templates === null) {
                $this->components->error('Unknown target: '.$target);

                return self::FAILURE;
            }
            foreach ($templates as $template) {
                $suggestion = $suggestion->merge(isset($template['view']) ? $sandbox->suggestPolicyForView($template['view']) : $sandbox->suggestPolicy((string) file_get_contents((string) ($template['path'] ?? '')), $template['name']));
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($suggestion->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($suggestion->toConfig() === []) {
            $this->components->info('The policy already allows everything these templates name literally.');
        } else {
            $this->components->info('Missing allowances (config/blade-sandbox.php policy keys):');
            $this->line(var_export($suggestion->toConfig(), true));
            $this->newLine();
            $this->line($suggestion->toPhp());
        }

        foreach ($suggestion->flagged() as $flagged) {
            $this->components->warn('Not suggested: '.$flagged);
        }
        foreach ($suggestion->errors() as $error) {
            $this->components->error($error);
        }

        return self::SUCCESS;
    }
}
