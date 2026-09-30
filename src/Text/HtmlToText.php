<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Text;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use SytxLabs\BladeSandbox\Contracts\TextConverter;

/**
 * Default HTML to plain text conversion (ext-dom): block elements become paragraphs, <br> a line
 * break, list items "- " / "1. ", links "text (url)", images their alt text, table cells are separated
 * by " | ", <hr> becomes a rule. Scripts, styles and other invisible content are dropped.
 */
class HtmlToText implements TextConverter
{
    private const SKIP = ['script', 'style', 'head', 'title', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'select', 'button', 'input', 'textarea'];

    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'dialog', 'div', 'dl', 'dt', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'main',
        'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'ul', 'caption', 'body', 'html',
    ];

    public function __construct(private readonly int $wordWrap = 0)
    {
    }

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode(
            "\n",
            array_map('ltrim', array_map(static fn (string $line): string => rtrim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line), explode("\n", $this->children($dom, false))))
        )) ?? '');
        return $this->wordWrap > 0 ? wordwrap($text, $this->wordWrap, "\n", false) : $text;
    }

    protected function children(DOMNode $node, bool $pre): string
    {
        return collect(iterator_to_array($node->childNodes))->implode(fn ($v) => $this->node($v, $pre), '');
    }

    protected function node(DOMNode $node, bool $pre): string
    {
        if ($node instanceof DOMText) {
            return $pre ? $node->data : (preg_replace('/\s+/u', ' ', $node->data) ?? '');
        }
        if (!$node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        return in_array($tag, self::SKIP, true) ? '' : match ($tag) {
            'br' => "\n",
            'hr' => "\n\n---\n\n",
            'img' => trim($node->getAttribute('alt')),
            'a' => $this->link($node, $pre),
            'li' => "\n".$this->bullet($node).trim($this->children($node, $pre)),
            'tr' => "\n".collect(iterator_to_array($node->childNodes))->filter(fn ($c) => $c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true))->implode(fn ($c) => trim(preg_replace('/\s+/u', ' ', $this->children($c, $pre)) ?? ''), ' | '),
            'pre' => "\n\n".$this->children($node, true)."\n\n",
            default => in_array($tag, self::BLOCKS, true) ? "\n\n".trim($this->children($node, $pre))."\n\n" : $this->children($node, $pre),
        };
    }

    protected function link(DOMElement $link, bool $pre): string
    {
        $text = trim($this->children($link, $pre));
        $href = trim($link->getAttribute('href'));
        if ($href === '' || str_starts_with($href, '#') || preg_match('/\A(?:https?:|mailto:|tel:|\/)/i', $href) !== 1) {
            return $text;
        }
        $target = preg_replace('/\A(?:mailto|tel):/i', '', $href) ?? $href;
        if ($text === '' || $text === $href || $text === $target) {
            return $target;
        }
        return $text.' ('.$href.')';
    }

    protected function bullet(DOMElement $item): string
    {
        $list = $item->parentNode;
        if ($list instanceof DOMElement && strtolower($list->tagName) === 'ol') {
            $position = 1;
            for ($sibling = $item->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
                if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === 'li') {
                    $position++;
                }
            }
            return $position.'. ';
        }
        return '- ';
    }
}
