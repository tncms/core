<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use TheNguyen\CMS\Support\TrustedComponents\TrustedComponentPolicy;
use TheNguyen\CMS\Support\TrustedComponents\TrustedRenderContext;

/**
 * A server-rendered interactive component owned by an active plugin
 * (CORE-EXTENSION-TRUST S2). Typical use: a form with a CSRF field that must
 * appear inside content which `cms_html()` sanitizes.
 *
 * Register it from the plugin's own code during boot:
 *
 *     app('cms.trusted_components')->register(new ContactFormComponent);
 *
 * Core derives the owner from this class's file, so the component id is always
 * `{owner-slug}/{key()}`. Nothing the provider returns can claim another owner.
 *
 * {@see render()} receives UNTRUSTED props (from content or a layout) and a
 * server-derived context. Its output is validated by Core's component-only
 * interactive profile and rejected as a whole on any violation. Escape every
 * value with Blade `{{ }}` / `e()`.
 */
interface TrustedComponentProvider
{
    /** Local key, `^[a-z0-9][a-z0-9-]{0,47}$`. */
    public function key(): string;

    /** Who may invoke it, in which modes, for which viewers. Read once, at registration. */
    public function policy(): TrustedComponentPolicy;

    /**
     * Render the component's HTML for this request.
     *
     * In a read-only context ({@see TrustedRenderContext::isReadOnly()}: signed
     * preview, editor) it must not write, call out or submit: Core refuses
     * database writes and HTTP-client calls, and requires inert controls.
     *
     * @param  array<string, mixed>  $props  Bounded scalars and lists; untrusted.
     */
    public function render(TrustedRenderContext $context, array $props): string;
}
