<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Guards;

use SytxLabs\BladeSandbox\Exceptions\ForbiddenDirectiveException;
use SytxLabs\BladeSandbox\Policy\DirectivePolicy;

final class DirectiveGuard extends Guard
{
    public function check(string $directive, array $applicationDirectives): bool
    {
        $name = strtolower($directive);
        if (in_array($name, DirectivePolicy::NEVER, true)) {
            throw $this->deny(ForbiddenDirectiveException::for('@'.$directive, 'never available in a sandbox'));
        }
        if ($this->policy->allowsDirective($name)) {
            return true;
        }
        if (DirectivePolicy::isKnownBuiltIn($name)) {
            throw $this->deny(ForbiddenDirectiveException::for('@'.$directive));
        }
        if (in_array($name, $applicationDirectives, true)) {
            throw $this->deny(ForbiddenDirectiveException::for('@'.$directive, 'application directives are not available in a sandbox'));
        }
        return false;
    }

    public function denyRawEcho(): ForbiddenDirectiveException
    {
        return $this->deny(ForbiddenDirectiveException::for('{!! !!}', 'raw output; enable with allowRawEcho()'));
    }
}
