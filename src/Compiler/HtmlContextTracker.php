<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Compiler;

use Closure;
use SytxLabs\BladeSandbox\Exceptions\InvalidSandboxTemplateException;

/**
 * Tracks the HTML context of the template's literal text while it is compiled (and of raw HTML at runtime), so that
 *  - static attributes can be validated (wire:*, Alpine / inline JS when enforced),
 *  - dynamic output can be compiled for the context it appears in,
 *  - templates, captured fragments (sections, slots, pushes) and control-flow branches are context-neutral, i.e. skipping or moving a fragment cannot make the browser see markup that was never validated.
 *
 * The tokenizer follows the HTML parsing rules that matter for these checks (tags, attributes, quoted/unquoted values, comments incl. "<!-->" and "--!>", raw text elements). It is not an HTML sanitizer.
 */
final class HtmlContextTracker
{
    public const TEXT = 'text';

    public const TAG_NAME = 'tag-name';

    public const TAG = 'tag';

    public const ATTRIBUTE_NAME = 'attribute-name';

    public const AFTER_ATTRIBUTE_NAME = 'after-attribute-name';

    public const BEFORE_VALUE = 'before-value';

    public const VALUE_DOUBLE = 'value-double';

    public const VALUE_SINGLE = 'value-single';

    public const VALUE_UNQUOTED = 'value-unquoted';

    public const COMMENT = 'comment';

    public const RAW_TEXT = 'raw-text';

    /** Elements whose content is not parsed as markup by browsers. */
    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext'];

    private string $state = self::TEXT;

    /** Characters kept back because they may start a construct that continues in the next chunk. */
    private string $carry = '';

    private string $tagName = '';

    private bool $closingTag = false;

    private bool $tagNameDynamic = false;

    private string $attributeName = '';

    private bool $attributeNameDynamic = false;

    private string $attributeValue = '';

    private bool $attributeValueDynamic = false;

    private string $rawTextEnd = '';

    private bool $precededBySpace = true;

    private int $line = 1;

    /**
     * @param Closure(string $name, ?string $value, bool $dynamic, int $line): void $onAttribute
     * @param Closure(string $tag, bool $dynamic, int $line): void $onTag
     */
    public function __construct(private readonly Closure $onAttribute, private readonly Closure $onTag)
    {
    }

    public function state(): string
    {
        return $this->carry === '<' ? self::TAG_NAME : $this->state;
    }

    public function blockState(): string
    {
        $state = $this->state();
        return ($state === self::AFTER_ATTRIBUTE_NAME || ($state === self::ATTRIBUTE_NAME && $this->attributeNameDynamic && $this->attributeName === '')) ? self::TAG : $state;
    }

    public function inText(): bool
    {
        return $this->state() === self::TEXT;
    }

    public function feed(string $text, int $line): self
    {
        $this->line = $line;
        $text = $this->carry.$text;
        $this->carry = '';
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === "\n") {
                $this->line++;
            }

            switch ($this->state) {
                case self::TEXT:
                    if ($char !== '<') {
                        break;
                    }
                    if ($i + 1 >= $length) {
                        $this->carry = '<';
                        break;
                    }
                    $next = $text[$i + 1];
                    if ($next === '!' && substr($text, $i, 4) === '<!--') {
                        if (substr($text, $i, 5) === '<!-->') {
                            $i += 4;
                        } elseif (substr($text, $i, 6) === '<!--->') {
                            $i += 5;
                        } else {
                            $this->state = self::COMMENT;
                            $i += 3;
                        }
                    } elseif ($next === '!' && $i + 3 >= $length && str_starts_with('<!--', substr($text, $i))) {
                        $this->carry = substr($text, $i);
                        $i = $length;
                    } elseif ($next === '/' || $next === '!' || ctype_alpha($next)) {
                        $this->openTag($next === '/' || $next === '!');
                        if ($next === '/' || $next === '!') {
                            $i++;
                        }
                    }
                    break;

                case self::COMMENT:
                    if ($char !== '-') {
                        break;
                    }
                    $rest = substr($text, $i);
                    if (str_starts_with($rest, '-->')) {
                        $this->state = self::TEXT;
                        $i += 2;
                    } elseif (str_starts_with($rest, '--!>')) {
                        $this->state = self::TEXT;
                        $i += 3;
                    } elseif (strlen($rest) < 4 && (str_starts_with('-->', $rest) || str_starts_with('--!>', $rest))) {
                        $this->carry = $rest;
                        $i = $length;
                    }
                    break;

                case self::RAW_TEXT:
                    if ($char !== '<') {
                        break;
                    }
                    $candidate = strtolower(substr($text, $i, strlen($this->rawTextEnd)));
                    if ($candidate === $this->rawTextEnd) {
                        $this->state = self::TEXT;
                        $i--;
                    } elseif (str_starts_with($this->rawTextEnd, $candidate) && $i + strlen($candidate) >= $length) {
                        $this->carry = substr($text, $i);
                        $i = $length;
                    }
                    break;

                case self::TAG_NAME:
                    if ($char === '>' || $char === '/' || ctype_space($char)) {
                        $this->finishTagName();
                        $this->state = self::TAG;
                        $this->precededBySpace = true;
                        $i--;
                    } else {
                        $this->tagName .= $char;
                    }
                    break;

                case self::TAG:
                    if ($char === '/' || ctype_space($char)) {
                        $this->precededBySpace = true;
                    } elseif ($char === '>') {
                        $this->closeTag();
                    } else {
                        $this->startAttribute($char);
                    }
                    break;

                case self::ATTRIBUTE_NAME:
                    if (ctype_space($char)) {
                        $this->state = self::AFTER_ATTRIBUTE_NAME;
                    } elseif ($char === '=') {
                        $this->state = self::BEFORE_VALUE;
                    } elseif ($char === '>' || $char === '/') {
                        $this->finishAttribute(null);
                        $this->state = self::TAG;
                        $this->precededBySpace = true;
                        $i--;
                    } else {
                        $this->attributeName .= $char;
                    }
                    break;

                case self::AFTER_ATTRIBUTE_NAME:
                    if (ctype_space($char)) {
                        break;
                    }
                    if ($char === '=') {
                        $this->state = self::BEFORE_VALUE;
                    } else {
                        $this->finishAttribute(null);
                        $this->state = self::TAG;
                        $this->precededBySpace = true;
                        $i--;
                    }
                    break;

                case self::BEFORE_VALUE:
                    if (ctype_space($char)) {
                        break;
                    }
                    if ($char === '"') {
                        $this->state = self::VALUE_DOUBLE;
                    } elseif ($char === '\'') {
                        $this->state = self::VALUE_SINGLE;
                    } elseif ($char === '>') {
                        $this->finishAttribute('');
                        $this->closeTag();
                    } else {
                        $this->state = self::VALUE_UNQUOTED;
                        $this->attributeValue .= $char;
                    }
                    break;

                case self::VALUE_DOUBLE:
                case self::VALUE_SINGLE:
                    if ($char === ($this->state === self::VALUE_DOUBLE ? '"' : '\'')) {
                        $this->finishAttribute($this->attributeValue);
                        $this->state = self::TAG;
                        $this->precededBySpace = false;
                    } else {
                        $this->attributeValue .= $char;
                    }
                    break;

                case self::VALUE_UNQUOTED:
                    if ($char === '>' || ctype_space($char)) {
                        $this->finishAttribute($this->attributeValue);
                        $this->state = self::TAG;
                        $this->precededBySpace = true;
                        if ($char === '>') {
                            $i--;
                        }
                    } else {
                        $this->attributeValue .= $char;
                    }
                    break;
            }
        }
        return $this;
    }

    /**
     * Called for every echo / output that is inserted at the current position. Returns the context the output is compiled for:
     *  'text'            plain text (Htmlable values may render raw markup)
     *  'value'           inside an attribute value, raw text element or comment (always escaped)
     *  'attribute'       attribute-list position ({{ $attributes }}), output is validated as attributes
     *  'tag-name'        inside a tag name (<h{{ $level }}>), output must be a plain name
     *  'attribute-name'  suffix of an attribute name (data-{{ $id }}), output must be a plain name.
     */
    public function dynamic(string $what = 'echo'): string
    {
        if ($this->carry === '<') {
            $this->carry = '';
            $this->openTag(false);
        } elseif ($this->carry !== '') {
            $this->carry = '';
        }

        switch ($this->state) {
            case self::TAG_NAME:
                $this->tagNameDynamic = true;

                return 'tag-name';

            case self::TAG:
                if (! $this->precededBySpace) {
                    throw InvalidSandboxTemplateException::syntax($what.' directly after an attribute value; separate attributes with whitespace', $this->line);
                }
                $this->state = self::ATTRIBUTE_NAME;
                $this->attributeName = '';
                $this->attributeNameDynamic = true;
                $this->attributeValue = '';
                $this->attributeValueDynamic = false;
                return 'attribute';

            case self::ATTRIBUTE_NAME:
                $this->attributeNameDynamic = true;
                return 'attribute-name';

            case self::AFTER_ATTRIBUTE_NAME:
                $this->finishAttribute(null);
                $this->state = self::TAG;
                $this->precededBySpace = true;
                return $this->dynamic($what);
            case self::BEFORE_VALUE:
            case self::VALUE_UNQUOTED:
                throw InvalidSandboxTemplateException::syntax('unquoted attribute value with dynamic content; quote the attribute value', $this->line);
            case self::VALUE_DOUBLE:
            case self::VALUE_SINGLE:
                $this->attributeValueDynamic = true;
                return 'value';
            case self::COMMENT:
            case self::RAW_TEXT:
                return 'value';
            default:
                return 'text';
        }
    }

    public function finish(string $what = 'template'): self
    {
        if ($this->attributeNameDynamic && $this->attributeName === '' && $this->state() === self::ATTRIBUTE_NAME) {
            $this->finishAttribute(null);
            $this->state = self::TAG;
        }
        if (!in_array($this->state(), [self::TEXT, self::COMMENT, self::RAW_TEXT], true)) {
            throw InvalidSandboxTemplateException::syntax($what.' ends inside an HTML tag', $this->line);
        }
        return $this;
    }

    private function openTag(bool $closingOrBogus): void
    {
        $this->state = self::TAG_NAME;
        $this->tagName = '';
        $this->tagNameDynamic = false;
        $this->closingTag = $closingOrBogus;
    }

    private function startAttribute(string $char): void
    {
        $this->state = self::ATTRIBUTE_NAME;
        $this->attributeName = $char;
        $this->attributeNameDynamic = false;
        $this->attributeValue = '';
        $this->attributeValueDynamic = false;
    }

    private function finishAttribute(?string $value): void
    {
        $name = $this->attributeName;

        if ($this->attributeNameDynamic) {
            if ($name === '' && $value !== null) {
                throw InvalidSandboxTemplateException::syntax('dynamic attribute names are only allowed after a "data-" or "aria-" prefix', $this->line);
            }
            if ($name === '') {
                $this->resetAttribute();
                return;
            }
            $lower = strtolower($name);
            if (!str_starts_with($lower, 'data-') && !str_starts_with($lower, 'aria-')) {
                throw InvalidSandboxTemplateException::syntax('dynamic attribute names are only allowed after a "data-" or "aria-" prefix', $this->line);
            }
        }
        if (!$this->closingTag && $name !== '') {
            ($this->onAttribute)($name, $value, $this->attributeValueDynamic, $this->line);
        }

        $this->resetAttribute();
    }

    private function resetAttribute(): void
    {
        $this->attributeName = '';
        $this->attributeNameDynamic = false;
        $this->attributeValue = '';
        $this->attributeValueDynamic = false;
    }

    private function finishTagName(): void
    {
        if (!$this->closingTag) {
            ($this->onTag)($this->tagName, $this->tagNameDynamic, $this->line);
        }
    }

    private function closeTag(): void
    {
        $tag = strtolower($this->tagName);
        $this->state = self::TEXT;

        if (!$this->closingTag && !$this->tagNameDynamic && in_array($tag, self::RAW_TEXT_ELEMENTS, true)) {
            $this->state = self::RAW_TEXT;
            $this->rawTextEnd = '</'.$tag;
        }
    }
}
