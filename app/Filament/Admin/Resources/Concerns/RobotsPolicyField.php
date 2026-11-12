<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Concerns;

use Filament\Forms\Components\Select;
use TheNguyen\CMS\Seo\ResourceRobotsPolicy;

/**
 * The per-item "Search engine indexing" control shared by the Post, Page,
 * Category and Tag SEO sections (CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1). Empty =
 * inherit the site policy. The Select only offers the restriction values, and
 * the Core manager re-validates every write.
 */
final class RobotsPolicyField
{
    public static function make(bool $isPage = false): Select
    {
        $help = tn_trans('Noindex keeps the item reachable but asks search engines not to list it and removes it from the XML sitemap. The site-wide "Discourage search engines from indexing this site" setting always wins.');

        if ($isPage) {
            $help .= ' '.tn_trans('When this page is the homepage, this setting also applies at "/", combined with the site policy.');
        }

        return Select::make(ResourceRobotsPolicy::COLUMN)
            ->label(tn_trans('Search engine indexing'))
            ->placeholder(tn_trans('Default (follow the site setting)'))
            ->options(array_map(static fn (string $label): string => tn_trans($label), ResourceRobotsPolicy::labels()))
            ->helperText($help)
            ->nullable();
    }
}
