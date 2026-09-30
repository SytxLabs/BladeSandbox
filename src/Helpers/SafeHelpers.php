<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Helpers;

use Illuminate\Support\Number;
use Illuminate\Support\Str;
use SytxLabs\BladeSandbox\Sandbox;

final class SafeHelpers
{
    public const FUNCTIONS = [
        'strtoupper', 'strtolower', 'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim', 'strlen', 'substr', 'str_contains', 'str_starts_with', 'str_ends_with', 'strip_tags',
        'mb_strtoupper', 'mb_strtolower', 'mb_substr', 'mb_strlen', 'number_format', 'round', 'floor', 'ceil', 'abs', 'intval', 'floatval', 'is_numeric', 'date', 'urlencode', 'rawurlencode', 'now', 'today',
    ];
    public const STR = [
        'limit', 'words', 'slug', 'title', 'headline', 'upper', 'lower', 'ucfirst', 'squish', 'mask', 'plural', 'singular', 'excerpt', 'initials', 'wordCount',
        'before', 'after', 'between', 'finish', 'start', 'contains', 'startsWith', 'endsWith', 'length', 'substr', 'kebab', 'snake', 'camel', 'studly',
    ];
    public const NUMBER = ['format', 'currency', 'percentage', 'fileSize', 'abbreviate', 'forHumans', 'ordinal', 'spell'];

    public function __invoke(Sandbox $sandbox): void
    {
        foreach (self::FUNCTIONS as $function) {
            if (function_exists($function)) {
                $sandbox->allowFunction($function);
            }
        }
        $sandbox->allowStaticMethod(Str::class, self::existing(Str::class, self::STR));
        if (class_exists(Number::class)) {
            $sandbox->allowStaticMethod(Number::class, self::existing(Number::class, self::NUMBER));
        }
    }

    private static function existing(string $class, array $methods): array
    {
        return array_values(array_filter($methods, static fn (string $method): bool => method_exists($class, $method)));
    }
}
