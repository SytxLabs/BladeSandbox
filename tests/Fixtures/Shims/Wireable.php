<?php

namespace Livewire;

/** Test shim: lets the BaseDTO fixture load when Livewire is not installed. */
interface Wireable
{
    public function toLivewire();

    public static function fromLivewire($value);
}
