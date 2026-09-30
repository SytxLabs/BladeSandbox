<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/** Converts rendered HTML into plain text (renderText(), e.g. the text part of an e-mail). Replace the default with BladeSandbox::useTextConverter(). */
interface TextConverter
{
    public function convert(string $html): string;
}
