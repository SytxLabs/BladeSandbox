<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Runtime;

use Illuminate\View\ComponentSlot;

/** @internal */
final class ComponentFrame
{
    /** @var array<string, ComponentSlot> */
    public array $slots = [];

    /** @var list<array{name: string, attributes: array<string, mixed>}> */
    public array $slotStack = [];

    /**
     * @param 'tag'|'view' $type
     * @param array<string, mixed> $attributes raw values (constructor arguments, props, component data)
     * @param array<string, mixed> $bagAttributes values for the attribute bag (bound values escaped, like Blade)
     */
    public function __construct(public readonly string $type, public readonly string $name, public readonly array $attributes, public readonly array $bagAttributes = [])
    {
    }
}
