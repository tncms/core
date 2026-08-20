<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets;

use Illuminate\Contracts\View\View;
use TheNguyen\CMS\Widgets\Fields\WidgetField;

/**
 * Base class for every CMS widget (Widget Foundation, v1.0.0-beta.7).
 *
 * A widget is a small, self-describing render unit registered with the
 * WidgetManager from core, a theme, or a plugin. Subclasses declare a stable
 * machine {@see type()}, a human {@see name()}, an optional {@see schema()} of
 * editable fields, and a {@see render()} method that returns a safe HTML string
 * (or a Blade View). The manager resolves per-locale settings before calling
 * render() and catches any failure so a broken widget never 500s the site.
 *
 * Field schema (returned by schema()) is intentionally simple — one flat array
 * of field definitions:
 *
 *   ['key' => 'limit', 'label' => 'Number of posts', 'type' => 'number',
 *    'default' => 5, 'min' => 1, 'max' => 20, 'localized' => false]
 *
 * The universal "title" field is handled by the manager/admin and is always
 * localized; it should NOT be redeclared in schema().
 */
abstract class Widget
{
    /** Field types the widget schema may declare. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'toggle', 'select', 'media', 'html'];

    /** Stable machine identifier, e.g. "recent-posts". */
    abstract public static function type(): string;

    /** Human-readable name shown in the admin. */
    abstract public static function name(): string;

    /** Short description shown in the admin (optional). */
    public static function description(): string
    {
        return '';
    }

    /** Heroicon name for the admin, or null. */
    public static function icon(): ?string
    {
        return null;
    }

    /**
     * The admin picker group this widget belongs to (e.g. "Basic", "Content").
     * Used only to organise the "Available Widgets" list — purely cosmetic.
     */
    public static function group(): string
    {
        return 'Basic';
    }

    /**
     * Editable field definitions (excluding the universal localized title).
     *
     * May return either plain arrays (the beta.7 shape) or {@see WidgetField}
     * objects — both normalise identically via {@see normalizedSchema()}.
     *
     * @return array<int, array<string, mixed>|WidgetField>
     */
    public static function schema(): array
    {
        return [];
    }

    /**
     * The schema normalised to canonical arrays, regardless of whether the
     * widget authored it with field objects or plain arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function normalizedSchema(): array
    {
        $normalized = [];

        foreach (static::schema() as $field) {
            if ($field instanceof WidgetField) {
                $normalized[] = $field->toArray();
            } elseif (is_array($field) && isset($field['key']) && is_string($field['key'])) {
                $normalized[] = $field;
            }
        }

        return $normalized;
    }

    /**
     * Render the widget for the given resolved settings + locale.
     *
     * $settings is the fully resolved value map (schema defaults overlaid with
     * global then localized stored values, plus a 'title' key). Implementations
     * must return safe output: escape user text, or run HTML through the CMS
     * sanitizer. Throwing is allowed — the manager catches and reports it.
     *
     * @param  array<string, mixed>  $settings
     */
    abstract public function render(array $settings = [], ?string $locale = null): string|View;

    /**
     * The schema defaults as a flat key => default map. Helper for the manager.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (static::normalizedSchema() as $field) {
            $defaults[$field['key']] = $field['default'] ?? null;
        }

        return $defaults;
    }
}
