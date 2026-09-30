<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO;

/** Minimal stand-in for the originating application's Proxy class used by BaseDTO. */
final class Proxy
{
    public function __construct(private readonly object $object)
    {
    }

    public function getObject(): object
    {
        return $this->object;
    }
}
