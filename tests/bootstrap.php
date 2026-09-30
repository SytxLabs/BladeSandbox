<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Casts\Json;
use Livewire\Wireable;

/*
 * Test-only shims for helpers the supplied real-world BaseDTO relies on (they live in the
 * originating application) and for optional dependencies. Only defined when missing.
 */

require __DIR__.'/../vendor/autoload.php';

if (! function_exists('throw_if_debug')) {
    function throw_if_debug(Throwable $exception): void
    {
        // The originating application rethrows in debug mode; tests keep the BaseDTO behaviour lenient.
    }
}

if (! function_exists('enum_value')) {
    function enum_value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : ($value instanceof UnitEnum ? $value->name : $value);
    }
}

if (! interface_exists(Wireable::class)) {
    require __DIR__.'/Fixtures/Shims/Wireable.php';
}

if (! class_exists(Json::class)) {
    require __DIR__.'/Fixtures/Shims/Json.php';
}
