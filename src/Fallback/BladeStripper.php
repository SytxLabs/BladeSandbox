<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Fallback;

/** Text-level helpers for fallback output: remove Blade syntax, PHP blocks and attributes of JavaScript frameworks from unrendered template sources.*/
final class BladeStripper
{
    /** Removes <?php ... ?> / <?= ... ?> blocks and @php ... @endphp. */
    public static function removePhp(string $source): string
    {
        return preg_replace('/@php\b(?!\s*\().*?@endphp\b/si', '', preg_replace('/<\?(?:php|=)?.*?(?:\?>|\z)/si', '', $source) ?? '') ?? '';
    }

    /** Removes all Blade syntax and keeps the static markup. */
    public static function strip(string $source): string
    {
        $verbatim = [];
        $source = preg_replace_callback('/@verbatim\b(.*?)@endverbatim\b/s', static function (array $match) use (&$verbatim): string {
            $verbatim[] = $match[1];
            return "\0VERBATIM".(count($verbatim) - 1)."\0";
        }, $source) ?? '';

        $source = self::removePhp($source);
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? '';
        $source = preg_replace('/(?<!@)\{!!.*?!!\}/s', '', $source) ?? '';
        $source = preg_replace('/(?<!@)\{\{\{?.*?\}?\}\}/s', '', $source) ?? '';
        $source = (string) str_replace('@{{', '{{', $source);

        // Directives with balanced (nested) parentheses, then bare directives.
        $source = preg_replace('/(?<![\w@])@[A-Za-z_]\w*\s*(?<args>\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|(?&args))*\))/s', '', $source) ?? '';
        $source = preg_replace('/(?<![\w@.])@[A-Za-z_]\w*\b/', '', $source) ?? '';
        $source = (string) str_replace('@@', '@', $source);

        // Component, slot and Livewire tags: keep their content.
        $source = preg_replace('/<\/?(?:x[-:][\w\-:.]+|livewire:[\w\-:.]+)(?:\s(?:[^>"\']++|"[^"]*"|\'[^\']*\')*)?\/?>/i', '', $source) ?? '';
        foreach ($verbatim as $index => $content) {
            $source = (string) str_replace("\0VERBATIM".$index."\0", $content, $source);
        }

        return preg_replace("/\n[ \t]*\n(?:[ \t]*\n)+/", "\n\n", $source) ?? '';
    }

    /** Removes attributes that would activate JavaScript frameworks: wire:* always (a fallback has no Livewire component), x-* / @event / :binding unless Alpine is allowed. */
    public static function removeFrameworkAttributes(string $html, bool $allowAlpine): string
    {
        $pattern = $allowAlpine ? '(?:wire:)' : '(?:wire:|x-|@|:)';
        return preg_replace_callback('/<[A-Za-z][^\s>\/]*(?:\s(?:[^>"\']++|"[^"]*"|\'[^\']*\')*)?>/', static fn (array $tag) => preg_replace('/\s'.$pattern.'[^\s=>\/]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $tag[0]) ?? '', $html) ?? '';
    }
}
