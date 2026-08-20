<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * A per-locale slug input (Phase 8.3): locale tabs of slug {@see TextInput}s with
 * a generate action that derives the slug from the MATCHING locale's title
 * ({titleStatePath}.{locale}) — never from another locale. Locale slugs stay
 * independent. Slug-format validation is applied per locale. No routing changes.
 */
final class LocalizedSlugInput
{
    /** @param array<string, mixed> $options */
    public static function make(string $statePath, string $titleStatePath, array $options = [], ?LocaleOptions $locales = null): Tabs
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return LocaleTabs::make(
            $statePath,
            static function (string $path, LocaleOption $opt) use ($manager, $locales, $options, $titleStatePath): TextInput {
                $titlePath = "{$titleStatePath}.{$opt->code}";

                $input = TextInput::make($path)
                    ->label($options['label'] ?? null)
                    ->rules($manager->validation->rulesFor($opt->code, $locales, ['slug' => true] + $options))
                    ->dehydrated(true);

                if (method_exists($input, 'suffixAction')) {
                    $input->suffixAction(
                        Action::make('generateSlug_'.$opt->code)
                            ->label(__('Generate'))
                            ->action(function ($get, $set) use ($manager, $opt, $titlePath, $path): void {
                                $title = $get($titlePath);
                                if (is_string($title) && trim($title) !== '') {
                                    $set($path, $manager->slugs->generate($title, $opt->code));
                                }
                            }),
                    );
                }

                return $input;
            },
            $locales,
            $options['label'] ?? null,
        );
    }
}
