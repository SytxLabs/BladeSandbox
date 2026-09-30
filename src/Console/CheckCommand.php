<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Console;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use SytxLabs\BladeSandbox\Console\Concerns\ResolvesTemplateTargets;
use SytxLabs\BladeSandbox\Sandbox;
use SytxLabs\BladeSandbox\SandboxManager;
use SytxLabs\BladeSandbox\Validation\ValidationResult;
use SytxLabs\BladeSandbox\Validation\Violation;

/**
 * Validates templates against a sandbox policy without rendering them (e.g. in CI).
 *
 * Targets: a directory or file path, a view namespace ("plugin" / "plugin::") or a view name ("plugin::page"). Exits with 1 when a violation is found.
 */
final class CheckCommand extends Command
{
    use ResolvesTemplateTargets;

    /** @var string */
    protected $name = 'blade-sandbox:check';

    /** @var string */
    protected $description = 'Validate Blade templates against a sandbox policy without rendering them';

    protected function getArguments(): array
    {
        return [
            new InputArgument('targets', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Directories, files, view namespaces ("plugin::") or view names ("plugin::page")'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('policy', null, InputOption::VALUE_OPTIONAL, 'Policy from config/blade-sandbox.php (default: the default policy)'),
            new InputOption('json', null, InputOption::VALUE_NONE, 'Output the violations as JSON'),
        ];
    }

    /** @throws JsonException */
    public function handle(SandboxManager $manager): int
    {
        $policy = $this->option('policy');
        $sandbox = is_string($policy) && $policy !== '' ? $manager->policy($policy) : $manager->default();

        $result = new ValidationResult();
        foreach ((array) $this->argument('targets') as $target) {
            $result = $result->merge($this->check($manager, $sandbox, (string) $target));
        }

        if ($this->option('json')) {
            $this->line(json_encode(['passed' => $result->passes(), 'templates' => $result->templates(), 'violations' => $result->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $result->passes() ? self::SUCCESS : self::FAILURE;
        }

        foreach ($result->violations() as $violation) {
            $this->components->error((string) $violation);
        }

        $count = count($result->templates());
        if ($result->passes()) {
            $this->components->info($count.' template(s) checked, no violations.');
            return self::SUCCESS;
        }
        $this->components->warn(count($result->violations()).' violation(s) in '.$count.' checked template(s).');
        return self::FAILURE;
    }

    private function check(SandboxManager $manager, Sandbox $sandbox, string $target): ValidationResult
    {
        $templates = $this->resolveTarget($manager, $target);
        if ($templates === null) {
            $this->components->error('Unknown target: '.$target);

            return new ValidationResult([new Violation($target, 'target', $target, 'Unknown target.')]);
        }

        $result = new ValidationResult();
        foreach ($templates as $template) {
            $result = $result->merge(isset($template['view']) ? $sandbox->validateView($template['view']) : $sandbox->validateFile((string) ($template['path'] ?? ''), $template['name']));
        }

        return $result;
    }
}
