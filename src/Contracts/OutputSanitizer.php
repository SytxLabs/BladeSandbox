<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

/** Post-processes the complete HTML output of a sandboxed page (e.g. to remove JavaScript that template authors must not be able to run in visitors' browsers). */
interface OutputSanitizer
{
    /**
     * @param string $html the rendered output of the page
     * @param string $view the entry view name ("plugin::page") or "inline:<hash>" for raw templates
     */
    public function sanitize(string $html, string $view): string;
}
