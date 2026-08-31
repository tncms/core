<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Forms;

use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Page-title visibility toggle (PB-FREE-LIBRARY-DESIGN-1-E-H1).
 *
 * A generic, core-owned Page presentation control that lets an editor decide
 * whether the visible page title (the document primary heading) is rendered
 * above the content. It is injected into the page form through the core Admin
 * Form Hook Bridge (`cms.form.schema.page`) so NO host/app or Filament resource
 * file is edited, and it appears even when Page Builder is absent.
 *
 * Placement: immediately AFTER the editing-language selector (the `locale`
 * field) inside the same section, matching the owner's requested UX. When that
 * field cannot be located (a reshaped form), it falls back to the nearest
 * core-owned location — the top of the form — so the control never disappears.
 *
 * The flag is a base-entity invariant persisted on {@see \TheNguyen\CMS\Models\Content}
 * (default true). Hiding the visible title never alters the stored title, the
 * SEO `<title>`, canonical URL, navigation label, or Admin label.
 */
final class PageTitleVisibilityFormField
{
    /** The form state path — mirrors the `cms_contents.show_page_title` column. */
    public const STATE_PATH = 'show_page_title';

    public static function make(): Toggle
    {
        return Toggle::make(self::STATE_PATH)
            // Lazy label/helper resolution: the admin locale is only known at
            // render time, so resolving eagerly here would freeze the first
            // request's language.
            ->label(fn (): string => tn_trans('Show page title'))
            ->helperText(fn (): string => tn_trans('Display the page title above the page content. Turn this off for layouts that provide their own primary heading.'))
            ->default(true)
            ->inline(false)
            ->dehydrated(true);
    }

    /**
     * Return a copy of the form component array with the toggle inserted
     * immediately after the editing-language selector. Falls back to prepending
     * the toggle when no `locale` field is present.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public static function inject(array $components): array
    {
        if (self::insertAfterLocale($components)) {
            return $components;
        }

        array_unshift($components, self::make());

        return $components;
    }

    /**
     * Depth-first insert of the toggle immediately after the `locale` field,
     * descending into layout components (Group/Section) that expose a raw child
     * schema. Operates on the freshly-built (not yet container-materialized)
     * component objects the resource just constructed, so replacing a layout
     * component's schema is safe.
     *
     * @param  array<int, mixed>  $components
     */
    private static function insertAfterLocale(array &$components): bool
    {
        foreach ($components as $index => $component) {
            if (self::isLocaleField($component)) {
                array_splice($components, $index + 1, 0, [self::make()]);

                return true;
            }

            if ($component instanceof Component) {
                $children = $component->getDefaultChildComponents();

                if (is_array($children) && self::insertAfterLocale($children)) {
                    $component->schema($children);

                    return true;
                }
            }
        }

        return false;
    }

    private static function isLocaleField(mixed $component): bool
    {
        return is_object($component)
            && method_exists($component, 'getName')
            && $component->getName() === 'locale';
    }
}
