<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

/**
 * Statically known capabilities a template refers to (collected while compiling for validation):
 * functions, static methods, constants, class constants, views, components and Livewire components that are named literally in the template.
 */
final class TemplateReferences
{
    public const FUNCTION = 'function';

    public const STATIC_METHOD = 'static-method';

    public const CONSTANT = 'constant';

    public const CLASS_CONSTANT = 'class-constant';

    public const VIEW = 'view';

    public const COMPONENT = 'component';

    public const LIVEWIRE_COMPONENT = 'livewire-component';

    public const ROUTE = 'route';

    public const TRANSLATION = 'translation';

    /** @var list<array{kind: string, subject: string, member: string, line: int}> */
    private array $references = [];

    public function add(string $kind, string $subject, int $line, string $member = ''): void
    {
        $this->references[] = ['kind' => $kind, 'subject' => $subject, 'member' => $member, 'line' => $line];
    }

    /** @return list<array{kind: string, subject: string, member: string, line: int}> */
    public function all(): array
    {
        return $this->references;
    }
}
