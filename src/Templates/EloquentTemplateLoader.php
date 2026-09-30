<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Templates;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SytxLabs\BladeSandbox\Contracts\TemplateLoader;

/** new EloquentTemplateLoader(Template::class, name: 'identifier', content: 'content', query: fn (Builder $q) => $q->where('active', true)). */
class EloquentTemplateLoader implements TemplateLoader
{
    /** @var array<string, string|null> */
    private array $memo = [];

    /**
     * @param class-string<Model> $model
     * @param Closure(Model): ?string|string $content
     * @param (Closure(Builder<Model>): mixed)|null $query additional constraints (active, locale, tenant, ...)
     */
    public function __construct(private readonly string $model, private readonly string $name = 'name', private readonly Closure|string $content = 'content', private readonly ?Closure $query = null)
    {
        if (!is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException('['.$model.'] is not an Eloquent model.');
        }
    }

    public function exists(string $name): bool
    {
        return $this->lookup($name) !== null;
    }

    public function source(string $name): string
    {
        return $this->lookup($name) ?? throw new InvalidArgumentException('Template ['.$name.'] not found.');
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** @return Builder<Model> */
    protected function newQuery(string $name): Builder
    {
        $query = $this->model::query()->where($this->name, $name);
        if ($this->query !== null) {
            ($this->query)($query);
        }
        return $query;
    }

    protected function contentOf(Model $record): ?string
    {
        $content = $this->content instanceof Closure ? ($this->content)($record) : $record->getAttribute($this->content);

        return is_string($content) ? $content : null;
    }

    private function lookup(string $name): ?string
    {
        if (!array_key_exists($name, $this->memo)) {
            $record = $this->newQuery($name)->first();
            $this->memo[$name] = $record instanceof Model ? $this->contentOf($record) : null;
        }
        return $this->memo[$name];
    }
}
