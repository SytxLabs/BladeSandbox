<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use SytxLabs\BladeSandbox\Exceptions\ForbiddenComponentException;
use SytxLabs\BladeSandbox\Support\Patterns;

final class ComponentGuard extends Guard
{
    public function assertComponent(mixed $component): string
    {
        if (!is_string($component) || !Patterns::isValidName($component)) {
            throw $this->deny(ForbiddenComponentException::for(is_string($component) ? substr(preg_replace('/[^\w\-\.:]/', '?', $component) ?? '', 0, 80) : get_debug_type($component), 'invalid component name'));
        }
        if (!$this->policy->allowsComponent($component)) {
            throw $this->deny(ForbiddenComponentException::for($component));
        }
        return $component;
    }
}
