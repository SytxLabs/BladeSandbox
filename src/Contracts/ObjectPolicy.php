<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/** Member-level access rules for objects that are not DTOs. */
interface ObjectPolicy
{
    public function allowsMethod(object|string $class, string $method): bool;

    public function allowsProperty(object|string $class, string $property): bool;

    /** Reading `$object[$key]` on an ArrayAccess object. */
    public function allowsArrayAccess(object $object): bool;

    /** Iterating a Traversable object with foreach. */
    public function allowsIteration(object $object): bool;

    /** Implicit or explicit string conversion via __toString(). */
    public function allowsStringConversion(object $object): bool;

    /** Printing an Htmlable object unescaped via toHtml() in an escaped echo. */
    public function allowsHtmlable(object $object): bool;
}
