<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use SytxLabs\BladeSandbox\Support\Patterns;

final readonly class LivewirePolicy
{
    /**
     * @param array<string, true> $directives lower-cased directive names without "wire:" and modifiers
     * @param array<string, true> $actions method names (case-sensitive) incl. magic actions like "$refresh"
     * @param array<string, true> $models public property names (root segment)
     * @param array<string, true> $events
     * @param list<string> $components component names / patterns
     */
    public function __construct(private array $directives = [], private array $actions = [], private array $models = [], private array $events = [], private array $components = [], private bool $alpine = false)
    {
    }

    public function allowsDirective(string $directive): bool
    {
        return isset($this->directives[strtolower($directive)]);
    }

    public function allowsAction(string $action): bool
    {
        return isset($this->actions[$action]);
    }

    public function allowsModel(string $property): bool
    {
        return isset($this->models[$property]) || isset($this->models[explode('.', $property, 2)[0] ?? '']);
    }

    public function allowsEvent(string $event): bool
    {
        return isset($this->events[$event]);
    }

    public function allowsComponent(string $component): bool
    {
        return Patterns::isValidName($component) && Patterns::matchesAny($this->components, $component);
    }

    public function allowsAlpine(): bool
    {
        return $this->alpine;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $list = static function (array $values): array {
            $values = array_keys($values);
            sort($values);
            return $values;
        };
        $components = $this->components;
        sort($components);

        return [
            'directives' => $list($this->directives),
            'actions' => $list($this->actions),
            'models' => $list($this->models),
            'events' => $list($this->events),
            'components' => $components,
            'alpine' => $this->alpine,
        ];
    }
}
