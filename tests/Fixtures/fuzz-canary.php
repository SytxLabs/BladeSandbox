<?php

declare(strict_types=1);

/*
 * Global canary for FuzzTest: templates must never be able to call it.
 */

if (! function_exists('fuzz_canary')) {
    function fuzz_canary(): string
    {
        $GLOBALS['__fuzz_canary'] = true;

        return 'TRIPPED';
    }
}
