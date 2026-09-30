<?php

namespace Illuminate\Database\Eloquent\Casts;

/** Test shim: Laravel 10 has no Json cast helper. */
final class Json
{
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, $flags);
    }
}
