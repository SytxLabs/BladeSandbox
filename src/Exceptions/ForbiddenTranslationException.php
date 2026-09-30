<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

final class ForbiddenTranslationException extends SecurityViolationException
{
    public static function for(string $subject, string $reason = ''): self
    {
        return new self('Translation key '.$subject.($reason !== '' ? ' ('.$reason.')' : '').' is not allowed in the sandbox.', 'translation', $subject);
    }
}
