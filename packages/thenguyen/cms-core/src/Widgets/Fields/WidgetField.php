<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/**
 * Fluent field definition for a widget's editable settings (v1.0.0-beta.7.1).
 *
 * A widget's schema() may return either plain arrays (the beta.7 shape) or these
 * field objects — both normalise to the same canonical array via {@see toArray()},
 * so the WidgetManager, the admin editor, and render-time settings resolution are
 * agnostic to which style a widget author uses. This keeps full backward
 * compatibility while giving a nicer authoring API:
 *
 *   TextField::make('title')->label('Title')->localized()
 *   NumberField::make('limit')->default(5)->min(1)->max(20)
 *
 * The same field vocabulary is intentionally reusable for Theme Options and
 * Localized Settings later (see CMS_ARCHITECTURE.md §Widget Field API).
 */
abstract class WidgetField
{
    protected string $label;

    protected mixed $default = null;

    protected bool $localized = false;

    protected ?string $helper = null;

    protected ?string $placeholder = null;

    /** @var array<int|string, mixed> */
    protected array $options = [];

    protected int|float|null $min = null;

    protected int|float|null $max = null;

    protected int $rows = 3;

    /** Sub-fields for composite types (repeater). @var array<int, WidgetField> */
    protected array $children = [];

    final public function __construct(protected string $key)
    {
        // Humanise the key into a default label ("show_date" → "Show date").
        $this->label = ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    /** The canonical field type ('text', 'textarea', 'toggle', …). */
    abstract public function type(): string;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function default(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    public function localized(bool $localized = true): static
    {
        $this->localized = $localized;

        return $this;
    }

    public function helper(?string $helper): static
    {
        $this->helper = $helper;

        return $this;
    }

    public function placeholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * @param  array<int|string, mixed>  $options
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function min(int|float $min): static
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float $max): static
    {
        $this->max = $max;

        return $this;
    }

    public function rows(int $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Declare sub-fields for a composite (repeater) field.
     *
     * @param  array<int, WidgetField>  $children
     */
    public function fields(array $children): static
    {
        $this->children = $children;

        return $this;
    }

    public function isLocalized(): bool
    {
        return $this->localized;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * The canonical, UI-/manager-friendly array form. Matches the beta.7 schema
     * shape so consumers never need to know a field was authored fluently.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type(),
            'localized' => $this->localized,
            'default' => $this->default,
            'helper' => $this->helper,
            'placeholder' => $this->placeholder,
        ];

        if ($this->options !== []) {
            $array['options'] = $this->options;
        }

        if ($this->min !== null) {
            $array['min'] = $this->min;
        }

        if ($this->max !== null) {
            $array['max'] = $this->max;
        }

        if ($this->type() === 'textarea' || $this->type() === 'richeditor' || $this->type() === 'html') {
            $array['rows'] = $this->rows;
        }

        if ($this->children !== []) {
            $array['fields'] = array_map(
                static fn (WidgetField $child): array => $child->toArray(),
                $this->children,
            );
        }

        return $array;
    }
}
