<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Sanitizers;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use SytxLabs\BladeSandbox\Contracts\OutputSanitizer;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;
use SytxLabs\BladeSandbox\Exceptions\UnsafeOutputException;

/**
 * Built-in allowlist HTML sanitizer (no extra dependencies besides ext-dom).
 *
 * - Elements that execute or embed active content (script, style, iframe, object, embed, template,
 *   noscript, svg, math, form elements, base, link, ...) are removed with their content.
 * - Other unknown elements are unwrapped (their text / children are kept).
 * - Attributes are allowlisted per element; every `on*` handler is removed.
 * - URLs (href, src, cite, action-like attributes) only allow http(s), mailto, tel and relative URLs;
 *   `data:` only for images (png, gif, jpeg, webp) in img[src].
 * - Inline `style` is removed unless enabled; if enabled, dangerous CSS (expression(), url(),
 *
 *   @import, behavior, -moz-binding, javascript:) removes the attribute.
 * - Comments and processing instructions are removed; links with target get rel="noopener noreferrer".
 *
 * Fragments stay fragments; complete documents (starting with <!DOCTYPE or <html>) keep their
 * html/head/body structure. Livewire / Alpine attributes are removed unless allowed via $extraAttributes.
 */
final class HtmlSanitizer implements OutputSanitizer
{
    /** Removed including their content. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'template', 'noscript',
        'noembed', 'noframes', 'svg', 'math', 'base', 'link', 'form', 'input', 'button', 'select', 'textarea',
        'option', 'optgroup', 'datalist', 'output', 'canvas', 'dialog', 'portal', 'xmp', 'plaintext', 'param',
    ];

    private const TAGS = [
        'html', 'head', 'body', 'title', 'meta',
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'caption', 'cite',
        'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
        'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'i', 'img', 'ins', 'kbd', 'label',
        'li', 'main', 'mark', 'nav', 'ol', 'p', 'picture', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section',
        'small', 'span', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time',
        'tr', 'u', 'ul', 'var', 'wbr',
    ];

    private const GLOBAL_ATTRIBUTES = ['class', 'id', 'title', 'lang', 'dir', 'role', 'hidden', 'tabindex', 'translate'];

    private const ATTRIBUTES = [
        'a' => ['href', 'target', 'rel', 'name', 'hreflang', 'download'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol' => ['start', 'reversed', 'type'],
        'li' => ['value'],
        'time' => ['datetime'],
        'blockquote' => ['cite'],
        'q' => ['cite'],
        'del' => ['cite', 'datetime'],
        'ins' => ['cite', 'datetime'],
        'details' => ['open'],
        'meta' => ['charset', 'name', 'content'],
        'label' => ['for'],
    ];

    private const URL_ATTRIBUTES = ['href', 'src', 'cite'];

    /**
     * @param list<string> $extraTags additionally allowed elements (never the DROP list)
     * @param array<string, list<string>> $extraAttributes tag (or "*") => additionally allowed attributes
     * @param bool $allowStyle keep inline style attributes (dangerous CSS is still removed)
     * @param bool $allowDataAttributes keep data-* and aria-* attributes
     * @param bool $rejectUnsafe throw UnsafeOutputException instead of cleaning when something was removed
     */
    public function __construct(private readonly array $extraTags = [], private readonly array $extraAttributes = [], private readonly bool $allowStyle = false, private readonly bool $allowDataAttributes = true, private readonly bool $rejectUnsafe = false)
    {
    }

    public function sanitize(string $html, string $view): string
    {
        if (trim($html) === '') {
            return $html;
        }

        if (!class_exists(DOMDocument::class)) {
            throw new SandboxException('The built-in HTML sanitizer needs the PHP dom extension.');
        }

        $document = preg_match('/\A\s*(?:<!doctype\b|<html\b)/i', $html) === 1;
        $doctype = $document && preg_match('/\A\s*<!doctype\b/i', $html) === 1;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML(($document ? '<?xml encoding="UTF-8">'.$html : '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>'), LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw new SandboxException('The rendered output could not be parsed for sanitizing.');
        }

        $findings = [];
        $this->clean($dom, $findings);

        if ($this->rejectUnsafe && $findings !== []) {
            throw UnsafeOutputException::for($view, array_values(array_unique($findings)));
        }

        if ($document) {
            $output = '';
            foreach ($dom->childNodes as $child) {
                if ($child instanceof DOMProcessingInstruction) {
                    continue;
                }
                $output .= $dom->saveHTML($child);
            }

            return ($doctype ? "<!DOCTYPE html>\n" : '').$output;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $output = '';
        if ($body instanceof DOMElement) {
            foreach ($body->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }
        }

        return $output;
    }

    /** @param list<string> $findings */
    private function clean(DOMNode $node, array &$findings): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                if ($child instanceof DOMComment && (str_contains($child->data, '<') || str_starts_with(ltrim($child->data), '['))) {
                    $findings[] = 'comment';
                }
                $node->removeChild($child);
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP, true)) {
                $findings[] = 'element:'.$tag;
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::TAGS, true) && !in_array($tag, $this->extraTags, true)) {
                $findings[] = 'element:'.$tag;
                $this->clean($child, $findings);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            if ($tag === 'meta' && $child->hasAttribute('http-equiv')) {
                $findings[] = 'attribute:http-equiv';
                $node->removeChild($child);
                continue;
            }
            $this->cleanAttributes($child, $tag, $findings);
            $this->clean($child, $findings);
        }
    }

    /** @param list<string> $findings */
    private function cleanAttributes(DOMElement $element, string $tag, array &$findings): void
    {
        $remove = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            if (!$this->allowsAttribute($tag, $name)) {
                $remove[] = $attribute->name;
                if ($name === 'style' || str_starts_with($name, 'on') || str_contains($name, ':') || str_starts_with($name, '@') || str_starts_with($name, 'x-')) {
                    $findings[] = 'attribute:'.$name;
                }

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && !self::isSafeUrl($value, $tag === 'img' && $name === 'src')) {
                $remove[] = $attribute->name;
                $findings[] = 'url:'.$name;

                continue;
            }

            if ($name === 'style' && !(preg_match('/expression\(|url\(|@import|behavior:|-moz-binding|javascript:|vbscript:/', strtolower(preg_replace('/\/\*.*?\*\/|\\\\|[\x00-\x20]/s', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '')) !== 1)) {
                $remove[] = $attribute->name;
                $findings[] = 'style';
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        if ($tag === 'a' && $element->hasAttribute('target')) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function allowsAttribute(string $tag, string $name): bool
    {
        if (str_starts_with($name, 'on')) {
            return false; // event handlers can never be allowed, not even via $extraAttributes
        }
        if ((in_array($name, self::GLOBAL_ATTRIBUTES, true) || in_array($name, self::ATTRIBUTES[$tag] ?? [], true)) || ($this->allowDataAttributes && (str_starts_with($name, 'data-') || str_starts_with($name, 'aria-')))) {
            return true;
        }
        if ($name === 'style') {
            return $this->allowStyle;
        }
        return in_array($name, $this->extraAttributes[$tag] ?? [], true) || in_array($name, $this->extraAttributes['*'] ?? [], true);
    }

    private static function isSafeUrl(string $value, bool $allowImageData): bool
    {
        // Browsers ignore whitespace / control characters inside the scheme ("java\tscript:").
        $clean = preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if (preg_match('/\A([a-z][a-z0-9+.\-]*):/i', $clean, $match) !== 1) {
            return true; // relative URL, fragment, query or path
        }
        if (in_array(strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }
        return $allowImageData && preg_match('#\Adata:image/(?:png|gif|jpeg|webp);base64,[a-z0-9+/=]+\z#i', $clean) === 1;
    }
}
