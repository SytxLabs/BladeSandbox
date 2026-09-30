<?php

declare(strict_types=1);

namespace SytxLabs\BladeSandbox\Contracts;

use InvalidArgumentException;

/**
 * A source of templates for one view namespace that does not live on the filesystem (database, API, CMS, ...).
 * Register it with BladeSandbox::loader('cms', $loader); templates are then addressed as "cms::name" in renderView(), @include, @extends, components, ...
 *
 * The returned source is untrusted: it is compiled by the sandbox compiler like every other template.
 */
interface TemplateLoader
{
    /** Whether a template with this name (without the "namespace::" prefix) exists. */
    public function exists(string $name): bool;

    /**
     * The template source.
     *
     * @throws InvalidArgumentException when the template does not exist
     */
    public function source(string $name): string;
}
