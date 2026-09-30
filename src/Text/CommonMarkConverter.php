<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Text;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use SytxLabs\BladeSandbox\Contracts\MarkdownConverter;
use SytxLabs\BladeSandbox\Exceptions\SandboxException;

/**
 * Default @markdown converter: league/commonmark (GitHub flavoured: tables, strikethrough, autolinks)
 * with raw HTML escaped and unsafe links removed.
 */
class CommonMarkConverter implements MarkdownConverter
{
    private ?GithubFlavoredMarkdownConverter $converter = null;

    public function __construct(private readonly array $options = [])
    {
    }

    public function convert(string $markdown): string
    {
        if (!class_exists(GithubFlavoredMarkdownConverter::class)) {
            throw new SandboxException('@markdown needs league/commonmark (composer require league/commonmark).');
        }
        return ($this->converter ??= new GithubFlavoredMarkdownConverter(['max_nesting_level' => 50, ...$this->options, 'html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown)->__toString();
    }
}
