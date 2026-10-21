<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use TheNguyen\CMS\Models\UserProfileField;

/**
 * Immutable, normalized description of a user profile field as held in the
 * in-memory registry (Account Profile Field Engine, P1-A).
 *
 * This is the registration-time contract only. It carries NO user values and
 * grants NO read access — reading a user's value is the job of the privacy-aware
 * UserProfileManager (P1-B), which always enforces visibility under an actor.
 */
final class ProfileFieldDefinition
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $validation
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $type,
        public readonly string $visibility,
        public readonly string $editability,
        public readonly bool $isEncrypted,
        public readonly string $source,
        public readonly int $sortOrder,
        public readonly array $options = [],
        public readonly array $validation = [],
        public readonly mixed $default = null,
        public readonly ?string $label = null,
        public readonly ?string $help = null,
    ) {}

    /**
     * Build a definition view from a persisted field row, for the value engine.
     * `group` is irrelevant to value operations and is left empty here.
     */
    public static function fromModel(UserProfileField $field): self
    {
        return new self(
            key: (string) $field->key,
            group: '',
            type: (string) $field->type,
            visibility: (string) $field->visibility,
            editability: (string) $field->editability,
            isEncrypted: (bool) $field->is_encrypted,
            source: (string) $field->source,
            sortOrder: (int) $field->sort_order,
            options: is_array($field->options) ? $field->options : [],
            validation: is_array($field->validation) ? $field->validation : [],
            default: $field->default_value,
        );
    }

    /**
     * The normalized comparison payload used to decide whether a re-registration
     * is identical (idempotent) or an incompatible collision. Deterministic:
     * arrays are deep-sorted by key so declaration order never matters.
     *
     * @return array<string, mixed>
     */
    public function signature(): array
    {
        return [
            'group' => $this->group,
            'type' => $this->type,
            'visibility' => $this->visibility,
            'editability' => $this->editability,
            'is_encrypted' => $this->isEncrypted,
            'options' => self::deepSort($this->options),
            'validation' => self::deepSort($this->validation),
            'default' => $this->default,
            'sort_order' => $this->sortOrder,
            'label' => $this->label,
            'help' => $this->help,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private static function deepSort(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::deepSort($item);
            }
        }
        unset($item);

        ksort($value);

        return $value;
    }
}
