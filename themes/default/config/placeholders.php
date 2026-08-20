<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Placeholder dataset map (theme-implementation; Phase 9B)
|--------------------------------------------------------------------------
|
| Maps a section type to the placeholder dataset file under
| themes/default/placeholders/{dataset}.php that supplies its `placeholder`
| data_source content. The SectionDataProvider reads this map, loads the named
| dataset, and returns the entry keyed by the section type.
|
| A section type with no entry here simply has no placeholder content (its
| `placeholder` mode renders empty), which keeps the map the single source of
| truth for what placeholder content the theme ships.
*/

return [
    'post-grid' => 'blog',
    'featured-grid' => 'magazine',
    'category-blocks' => 'magazine',
    'trending-list' => 'magazine',
];
