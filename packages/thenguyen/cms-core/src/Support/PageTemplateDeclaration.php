<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * An immutable, validated page-template declaration from a theme manifest
 * (CORE-THEME-2: active-theme page templates).
 *
 * Declared in theme.json:
 *
 *   "page_templates": [
 *     { "id": "cv", "label": "CV", "view": "pages/templates/cv",
 *       "description": "Curriculum vitae layout." }
 *   ]
 *
 * `id` is the stable identifier persisted on a Page (cms_contents.template).
 * `view` is a theme-relative view identifier (slash or dot separated) that must
 * resolve inside the declaring theme's own view hierarchy — never an arbitrary
 * Blade path, another theme, or a namespaced view.
 */
final class PageTemplateDeclaration
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $view,
        public readonly string $description,
        /** Slug of the chain theme that declared this template (owner). */
        public readonly string $owner,
    ) {}

    /**
     * The declared view as a dotted identifier under the "theme::" namespace
     * (e.g. "pages/templates/cv" → "theme::pages.templates.cv").
     */
    public function themeView(): string
    {
        return 'theme::'.str_replace('/', '.', $this->view);
    }
}
