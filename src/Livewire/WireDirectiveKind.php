<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Livewire;

enum WireDirectiveKind: string
{
    case Action = 'action';
    case Model = 'model';
    case Plain = 'plain';
    case Expression = 'expression';
    case Internal = 'internal';
}
