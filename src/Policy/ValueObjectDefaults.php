<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Policy;

use ArrayIterator;
use ArrayObject;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Stringable;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use stdClass;
use UnitEnum;

final class ValueObjectDefaults
{
    public const DATE_METHODS = [
        'format', 'getTimestamp', 'getTimezone', 'toDateString', 'toTimeString', 'toDateTimeString',
        'toDateTimeLocalString', 'toIso8601String', 'toAtomString', 'toRfc2822String', 'toFormattedDateString',
        'toDayDateTimeString', 'isoFormat', 'translatedFormat', 'diffForHumans', 'isPast', 'isFuture',
        'isToday', 'isTomorrow', 'isYesterday', 'isWeekend', 'isWeekday',
    ];
    public const DATE_PROPERTIES = [
        'year', 'month', 'day', 'hour', 'minute', 'second', 'dayOfWeek', 'dayOfYear', 'weekOfYear',
        'daysInMonth', 'timestamp', 'englishDayOfWeek', 'englishMonth', 'shortEnglishMonth', 'shortEnglishDayOfWeek',
        'dayName', 'shortDayName', 'monthName', 'shortMonthName', 'quarter',
    ];

    public const COLLECTION_METHODS = [
        'all', 'count', 'first', 'last', 'get', 'has', 'hasAny', 'isEmpty', 'isNotEmpty', 'keys', 'values',
        'take', 'slice', 'reverse', 'chunk', 'sortKeys', 'sortKeysDesc', 'forPage', 'nth', 'split', 'only', 'except',
    ];

    public const ATTRIBUTE_BAG_METHODS = [
        'get', 'has', 'hasAny', 'missing', 'only', 'except', 'merge', 'class', 'style', 'prepends',
        'whereStartsWith', 'whereDoesntStartWith', 'thatStartWith', 'first', 'isEmpty', 'isNotEmpty',
        'getAttributes', 'toHtml',
    ];

    public static function apply(PolicyBuilder $builder): PolicyBuilder
    {
        $builder
            ->allowMethod(DateTimeInterface::class, self::DATE_METHODS)
            ->allowProperty(DateTimeInterface::class, self::DATE_PROPERTIES)
            ->allowStringConversion(DateTimeInterface::class)
            ->allowProperty(UnitEnum::class, 'name')
            ->allowProperty(BackedEnum::class, 'value')
            ->allowProperty(stdClass::class, '*')
            ->allowStringConversion(Stringable::class)
            ->allowHtmlable(HtmlString::class)
            ->allowStringConversion(HtmlString::class)
            ->allowIteration(ArrayIterator::class)
            ->allowIteration(ArrayObject::class)
            ->allowArrayAccess(ArrayObject::class)
            ->allowArrayAccess(ArrayIterator::class)
            ->allowIteration(Enumerable::class)
            ->allowArrayAccess(Collection::class)
            ->allowMethod(Enumerable::class, self::COLLECTION_METHODS)
            ->allowMethod(ComponentAttributeBag::class, self::ATTRIBUTE_BAG_METHODS)
            ->allowHtmlable(ComponentAttributeBag::class)
            ->allowStringConversion(ComponentAttributeBag::class)
            ->allowIteration(ComponentAttributeBag::class)
            ->allowMethod(ComponentSlot::class, ['isEmpty', 'isNotEmpty', 'hasActualContent', 'toHtml'])
            ->allowProperty(ComponentSlot::class, 'attributes')
            ->allowHtmlable(ComponentSlot::class)
            ->allowStringConversion(ComponentSlot::class);

        return $builder;
    }
}
