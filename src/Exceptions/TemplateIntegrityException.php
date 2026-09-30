<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Exceptions;

/** Thrown when a view file does not match the integrity manifest (unknown view or changed contents), or when the manifest itself cannot be trusted (bad signature). */
final class TemplateIntegrityException extends SecurityViolationException
{
    public static function for(string $subject, string $reason): self
    {
        return new self('Integrity check failed for '.$subject.' ('.$reason.').', 'integrity', $subject);
    }
}
