<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Section Registry (theme-architecture 14)
|--------------------------------------------------------------------------
|
| The core-owned catalog of section types. Each entry declares the settings
| schema (enum-validated presentation knobs), the fields schema (typed
| content), the binding mode, the view it renders through, and the components
| it composes. The SectionResolver validates + defaults section nodes against
| this catalog before producing SectionViewModels.
|
| This is the Company-preset section family (theme-implementation 01/03). All
| are data-bound. Settings/fields shapes match the canonical pagebuilder/06
| field value types.
|
| Field schema vocabulary:
|   text|textarea|richtext  => ['type'=>..., 'default'=>'', 'max'=>?int]
|       Localized (per-locale) by default; add 'translatable'=>false to share one
|       value across locales (urls, icon names, provider slugs, identifiers).
|       link.label is localized; link.url is not.
|   number                  => ['type'=>'number', 'default'=>0, 'min'=>?, 'max'=>?]
|   boolean                 => ['type'=>'boolean', 'default'=>false]
|   link                    => ['type'=>'link', 'default'=>null]   // {label,url,target?,variant?}
|   media                   => ['type'=>'media', 'default'=>null]   // {kind:media,id} | {ref:key} | url
|   repeater                => ['type'=>'repeater', 'default'=>[], 'max'=>?int, 'item'=>[ <fields map> ]]
|
| Setting schema vocabulary:
|   enum    => ['type'=>'enum', 'values'=>[...], 'default'=>mixed]
|   boolean => ['type'=>'boolean', 'default'=>bool]
|   number  => ['type'=>'number', 'default'=>int, 'min'=>?, 'max'=>?]
|   text    => ['type'=>'text', 'default'=>'']
*/

$width = fn (string $default = 'wide') => [
    'type' => 'enum', 'values' => ['measure', 'wide', 'full'], 'default' => $default,
];
$background = fn (array $values, string $default = 'none') => [
    'type' => 'enum', 'values' => $values, 'default' => $default,
];
$align = fn (string $default = 'left') => [
    'type' => 'enum', 'values' => ['left', 'center'], 'default' => $default,
];
$spacing = fn (string $default = 'default') => [
    'type' => 'enum', 'values' => ['compact', 'default', 'spacious'], 'default' => $default,
];
// A presentation-only style variant. Additive (theme-evolution Rule 20): the
// first value is always the historic look, so absence/legacy layouts default to
// the original rendering. Variants are NOT localized (a setting, not a field).
$variant = fn (array $values, string $default) => [
    'type' => 'enum', 'values' => $values, 'default' => $default,
];

// Data source (theme-implementation; Phase 9B / 9C-B). Selects where a data-bound
// section gets its repeater items: authored `manual` content (the historic
// default, so existing presets/demos/layouts are unchanged), curated
// `placeholder` content shipped by the theme, or real published `posts` driven by
// a CMS-friendly query builder. The SectionDataProvider populates the repeater(s)
// before resolution, so the view contract is unchanged. No fallback: `posts`/
// `manual` render empty when they yield nothing; only `placeholder` is guaranteed
// to carry content. The default is always the first enum value (`manual`), keeping
// legacy layouts on authored content.
//
// Phase 9C-B replaced the temporary free-text `category_slug`/`tag_slug` knobs with
// a `source_type` query builder + searchable id multi-selects. Schema vocabulary
// used only here (the editor reads it; the resolver ignores unknown keys):
//   'labels'       => value => English label (editor runs it through tn_trans()).
//   'visible_when' => controlling-key => [allowed values…] (AND-ed); drives the
//                     editor's reactive show/hide. The resolver never depends on it.
//   'terms'        => a searchable multi-select of taxonomy term ids; 'taxonomy'
//                     names the term type. Stored value is an array of ids only.
//   'content'      => a searchable multi-select of Content (post) ids; stored
//                     value is an array of ids only (no titles/slugs/urls).
//   'authors'      => a searchable multi-select of author (user) ids.
// Legacy `category_slug`/`tag_slug` are no longer authored, but the data provider
// still resolves them transparently to ids for backward compatibility.
//
// Phase 9C-C grows this into a reusable Editorial Query Builder (manual / authors /
// related / sticky sources, include/exclude filters, offset, richer ordering).
// Everything is additive: the default (`manual` data_source, `latest` source) is
// unchanged, and the resolver ignores any setting it does not support (e.g. sticky
// where no column exists).
$dataSource = fn (int $defaultLimit = 6, int $maxLimit = 12) => [
    'data_source' => ['type' => 'enum', 'values' => ['manual', 'placeholder', 'posts'], 'default' => 'manual'],
    'source_type' => [
        'type' => 'enum',
        'values' => ['latest', 'categories', 'tags', 'featured', 'most_viewed', 'most_commented', 'manual', 'authors', 'related', 'sticky'],
        'default' => 'latest',
        'label' => 'Source Type',
        'labels' => [
            'latest' => 'Latest',
            'categories' => 'By Categories',
            'tags' => 'By Tags',
            'featured' => 'Featured',
            'most_viewed' => 'Most Viewed',
            'most_commented' => 'Most Commented',
            'manual' => 'Manual Selection',
            'authors' => 'By Authors',
            'related' => 'Related',
            'sticky' => 'Sticky',
        ],
        'visible_when' => ['data_source' => ['posts']],
    ],
    'category_ids' => [
        'type' => 'terms',
        'taxonomy' => 'category',
        'default' => [],
        'label' => 'Categories',
        'visible_when' => ['data_source' => ['posts'], 'source_type' => ['categories']],
    ],
    'tag_ids' => [
        'type' => 'terms',
        'taxonomy' => 'tag',
        'default' => [],
        'label' => 'Tags',
        'visible_when' => ['data_source' => ['posts'], 'source_type' => ['tags']],
    ],
    'author_ids' => [
        'type' => 'authors',
        'default' => [],
        'label' => 'Authors',
        'visible_when' => ['data_source' => ['posts'], 'source_type' => ['authors']],
    ],
    'manual_post_ids' => [
        'type' => 'content',
        'default' => [],
        'label' => 'Posts',
        'visible_when' => ['data_source' => ['posts'], 'source_type' => ['manual']],
    ],
    'related_mode' => [
        'type' => 'enum',
        'values' => ['automatic', 'same_category', 'same_tags', 'same_author', 'current_post_only'],
        'default' => 'automatic',
        'label' => 'Related Mode',
        'labels' => [
            'automatic' => 'Automatic',
            'same_category' => 'Same Category',
            'same_tags' => 'Same Tags',
            'same_author' => 'Same Author',
            'current_post_only' => 'Current Post Only',
        ],
        'visible_when' => ['data_source' => ['posts'], 'source_type' => ['related']],
    ],
    'order_by' => [
        'type' => 'enum',
        'values' => ['latest', 'oldest', 'random', 'most_viewed', 'most_commented', 'title', 'menu_order'],
        'default' => 'latest',
        'label' => 'Order By',
        'labels' => [
            'latest' => 'Latest',
            'oldest' => 'Oldest',
            'random' => 'Random',
            'most_viewed' => 'Most Viewed',
            'most_commented' => 'Most Commented',
            'title' => 'Title',
            'menu_order' => 'Menu Order',
        ],
        'visible_when' => ['data_source' => ['posts']],
    ],
    'limit' => [
        'type' => 'number', 'default' => $defaultLimit, 'min' => 1, 'max' => $maxLimit,
        'label' => 'Limit',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'offset' => [
        'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 100,
        'label' => 'Offset',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'featured_only' => [
        'type' => 'boolean', 'default' => false,
        'label' => 'Featured Only',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'published_only' => [
        'type' => 'boolean', 'default' => true,
        'label' => 'Published Only',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'exclude_current' => [
        'type' => 'boolean', 'default' => false,
        'label' => 'Exclude Current Post',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'exclude_post_ids' => [
        'type' => 'content',
        'default' => [],
        'label' => 'Exclude Posts',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'exclude_category_ids' => [
        'type' => 'terms',
        'taxonomy' => 'category',
        'default' => [],
        'label' => 'Exclude Categories',
        'visible_when' => ['data_source' => ['posts']],
    ],
    'exclude_tag_ids' => [
        'type' => 'terms',
        'taxonomy' => 'tag',
        'default' => [],
        'label' => 'Exclude Tags',
        'visible_when' => ['data_source' => ['posts']],
    ],
];

$linkField = ['type' => 'link', 'default' => null];

return [
    'hero' => [
        'title' => 'Hero',
        'category' => 'marketing',
        'view' => 'theme::sections.hero',
        'binding' => 'data',
        'renders' => ['heading', 'button', 'media'],
        'since' => '1.0.0',
        'settings' => [
            'variant' => $variant(['standard', 'modern-split', 'centered', 'editorial'], 'standard'),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint', 'accent', 'image'], 'tint'),
            'align' => $align('center'),
            'media_position' => ['type' => 'enum', 'values' => ['none', 'right', 'left', 'below'], 'default' => 'right'],
            'spacing' => $spacing('spacious'),
        ],
        'fields' => [
            'eyebrow' => ['type' => 'text', 'default' => '', 'max' => 60],
            'title' => ['type' => 'text', 'default' => '', 'max' => 120],
            'subtitle' => ['type' => 'textarea', 'default' => '', 'max' => 280],
            'ctas' => ['type' => 'repeater', 'default' => [], 'max' => 2, 'item' => [
                'label' => ['type' => 'text', 'default' => '', 'max' => 40],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                'variant' => ['type' => 'enum', 'values' => ['solid', 'outline', 'pill'], 'default' => 'solid'],
            ]],
            // Optional trust/feature chips shown under the CTAs (additive; absence
            // preserves the historic hero, theme-evolution Rule 20).
            'highlights' => ['type' => 'repeater', 'default' => [], 'max' => 6, 'item' => [
                'text' => ['type' => 'text', 'default' => '', 'max' => 60],
                'icon' => ['type' => 'text', 'default' => '', 'translatable' => false],
            ]],
            'media' => ['type' => 'media', 'default' => null],
        ],
    ],

    'logo-strip' => [
        'title' => 'Logo Strip',
        'category' => 'marketing',
        'view' => 'theme::sections.logo-strip',
        'binding' => 'data',
        'renders' => ['section-header', 'media'],
        'since' => '1.0.0',
        'settings' => [
            'width' => $width('wide'),
            'background' => $background(['none', 'surface'], 'none'),
            'grayscale' => ['type' => 'boolean', 'default' => true],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'logos' => ['type' => 'repeater', 'default' => [], 'max' => 24, 'item' => [
                'media' => ['type' => 'media', 'default' => null],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
            ]],
        ],
    ],

    'feature-grid' => [
        'title' => 'Feature Grid',
        'category' => 'marketing',
        'view' => 'theme::sections.feature-grid',
        'binding' => 'data',
        'renders' => ['section-header', 'feature-item', 'icon', 'link'],
        'since' => '1.0.0',
        'settings' => [
            // `screenshot-cards` swaps the glyph well for a mini UI screenshot per
            // card (additive, theme-evolution Rule 20 — `standard` stays default).
            'variant' => $variant(['standard', 'icon-cards', 'bordered', 'screenshot-cards'], 'standard'),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint'], 'none'),
            'columns' => ['type' => 'enum', 'values' => [2, 3, 4], 'default' => 3],
            'align' => $align('center'),
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'subheading' => ['type' => 'textarea', 'default' => '', 'max' => 280],
            'features' => ['type' => 'repeater', 'default' => [], 'max' => 12, 'item' => [
                'icon' => ['type' => 'text', 'default' => '', 'translatable' => false],
                'title' => ['type' => 'text', 'default' => '', 'max' => 80],
                'text' => ['type' => 'textarea', 'default' => '', 'max' => 240],
                'url' => ['type' => 'text', 'default' => null, 'translatable' => false],
                // Optional per-card screenshot used by the `screenshot-cards`
                // variant. Absent → the card renders its icon as before.
                'image' => ['type' => 'media', 'default' => null],
            ]],
        ],
    ],

    'feature-split' => [
        'title' => 'Feature Split',
        'category' => 'marketing',
        'view' => 'theme::sections.feature-split',
        'binding' => 'data',
        'renders' => ['heading', 'media', 'button'],
        'since' => '1.0.0',
        'settings' => [
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint'], 'none'),
            'media_side' => ['type' => 'enum', 'values' => ['left', 'right'], 'default' => 'right'],
            'spacing' => $spacing('default'),
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'body' => ['type' => 'richtext', 'default' => ''],
            'bullets' => ['type' => 'repeater', 'default' => [], 'max' => 10, 'item' => [
                'text' => ['type' => 'text', 'default' => '', 'max' => 160],
            ]],
            'media' => ['type' => 'media', 'default' => null],
            'cta' => $linkField,
        ],
    ],

    'stats-band' => [
        'title' => 'Stats Band',
        'category' => 'marketing',
        'view' => 'theme::sections.stats-band',
        'binding' => 'data',
        'renders' => ['section-header', 'card.stat'],
        'since' => '1.0.0',
        'settings' => [
            'variant' => $variant(['plain', 'surface-card', 'compact'], 'plain'),
            'width' => $width('wide'),
            'background' => $background(['surface', 'tint', 'accent'], 'tint'),
            'columns' => ['type' => 'enum', 'values' => [2, 3, 4], 'default' => 4],
            'align' => $align('center'),
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'stats' => ['type' => 'repeater', 'default' => [], 'max' => 8, 'item' => [
                'number' => ['type' => 'text', 'default' => '', 'max' => 24],
                'label' => ['type' => 'text', 'default' => '', 'max' => 80],
                'icon' => ['type' => 'text', 'default' => '', 'translatable' => false],
            ]],
        ],
    ],

    'pricing' => [
        'title' => 'Pricing',
        'category' => 'marketing',
        'view' => 'theme::sections.pricing',
        'binding' => 'data',
        'renders' => ['section-header', 'card.pricing', 'button'],
        'since' => '1.0.0',
        'settings' => [
            'variant' => $variant(['standard', 'saas-cards'], 'standard'),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface'], 'surface'),
            'columns' => ['type' => 'enum', 'values' => [2, 3, 4], 'default' => 3],
            'billing_toggle' => ['type' => 'boolean', 'default' => false],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'plans' => ['type' => 'repeater', 'default' => [], 'max' => 4, 'item' => [
                'name' => ['type' => 'text', 'default' => '', 'max' => 60],
                'price' => ['type' => 'text', 'default' => '', 'max' => 24],
                'period' => ['type' => 'text', 'default' => '', 'max' => 24],
                'features' => ['type' => 'repeater', 'default' => [], 'max' => 12, 'item' => [
                    'text' => ['type' => 'text', 'default' => '', 'max' => 120],
                ]],
                'cta' => ['type' => 'link', 'default' => null],
                'featured' => ['type' => 'boolean', 'default' => false],
            ]],
        ],
    ],

    'testimonials' => [
        'title' => 'Testimonials',
        'category' => 'marketing',
        'view' => 'theme::sections.testimonials',
        'binding' => 'data',
        'renders' => ['section-header', 'card.testimonial', 'carousel'],
        'since' => '1.0.0',
        'settings' => [
            'variant' => $variant(['standard', 'quote-cards'], 'standard'),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint'], 'surface'),
            'layout' => ['type' => 'enum', 'values' => ['grid', 'carousel'], 'default' => 'grid'],
            'columns' => ['type' => 'enum', 'values' => [1, 2, 3], 'default' => 3],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'items' => ['type' => 'repeater', 'default' => [], 'max' => 24, 'item' => [
                'quote' => ['type' => 'textarea', 'default' => '', 'max' => 400],
                'author' => ['type' => 'text', 'default' => '', 'max' => 80],
                'role' => ['type' => 'text', 'default' => '', 'max' => 120],
                'avatar' => ['type' => 'media', 'default' => null],
                'rating' => ['type' => 'number', 'default' => null, 'min' => 0, 'max' => 5],
            ]],
        ],
    ],

    'faq' => [
        'title' => 'FAQ',
        'category' => 'marketing',
        'view' => 'theme::sections.faq',
        'binding' => 'data',
        'renders' => ['section-header', 'accordion'],
        'since' => '1.0.0',
        'settings' => [
            'variant' => $variant(['plain', 'boxed'], 'plain'),
            'width' => $width('measure'),
            'background' => $background(['none', 'surface'], 'none'),
            'layout' => ['type' => 'enum', 'values' => ['single', 'two-column'], 'default' => 'single'],
            'allow_multiple' => ['type' => 'boolean', 'default' => false],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'items' => ['type' => 'repeater', 'default' => [], 'max' => 30, 'item' => [
                'question' => ['type' => 'text', 'default' => '', 'max' => 200],
                'answer' => ['type' => 'richtext', 'default' => ''],
            ]],
        ],
    ],

    'cta' => [
        'title' => 'Call To Action',
        'category' => 'marketing',
        'view' => 'theme::sections.cta',
        'binding' => 'data',
        'renders' => ['heading', 'button'],
        'since' => '1.0.0',
        'settings' => [
            'variant' => $variant(['standard', 'accent-panel', 'gradient-panel'], 'standard'),
            'width' => $width('wide'),
            'background' => $background(['accent', 'tint', 'surface'], 'accent'),
            'align' => $align('center'),
            'spacing' => $spacing('default'),
        ],
        'fields' => [
            'title' => ['type' => 'text', 'default' => '', 'max' => 120],
            'subtitle' => ['type' => 'textarea', 'default' => '', 'max' => 240],
            'button' => $linkField,
            'secondary' => $linkField,
        ],
    ],

    'media-band' => [
        'title' => 'Media Band',
        'category' => 'marketing',
        'view' => 'theme::sections.media-band',
        'binding' => 'data',
        'renders' => ['media'],
        'since' => '1.0.0',
        'settings' => [
            'width' => $width('full'),
            'background' => $background(['none', 'surface', 'tint'], 'none'),
        ],
        'fields' => [
            'media' => ['type' => 'media', 'default' => null],
            'caption' => ['type' => 'text', 'default' => '', 'max' => 200],
        ],
    ],

    'rich-text' => [
        'title' => 'Rich Text',
        'category' => 'content',
        'view' => 'theme::sections.rich-text',
        'binding' => 'data',
        'renders' => [],
        'since' => '1.0.0',
        'settings' => [
            'width' => $width('measure'),
            'background' => $background(['none', 'surface'], 'none'),
            'align' => $align('left'),
        ],
        'fields' => [
            'body' => ['type' => 'richtext', 'default' => ''],
        ],
    ],

    'newsletter' => [
        'title' => 'Newsletter',
        'category' => 'marketing',
        'view' => 'theme::sections.newsletter',
        'binding' => 'data',
        'renders' => ['section-header', 'newsletter'],
        'since' => '1.0.0',
        'settings' => [
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint', 'accent'], 'tint'),
            'align' => $align('center'),
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'subtitle' => ['type' => 'textarea', 'default' => '', 'max' => 240],
            'provider' => ['type' => 'text', 'default' => '', 'translatable' => false],
            'button_label' => ['type' => 'text', 'default' => 'Subscribe', 'max' => 40],
        ],
    ],

    // ---------------------------------------------------------------------
    // Architecture (theme-implementation; Phase 7E). A visual layer stack /
    // timeline that explains how a system is composed (e.g. theme → core →
    // framework). Data-bound: layers are authored as a repeater so it renders
    // identically from preset, demo, or the layout editor. No `variant` — like
    // feature-split/logo-strip it has a single intentional look.
    // ---------------------------------------------------------------------
    'architecture' => [
        'title' => 'Architecture',
        'category' => 'marketing',
        'view' => 'theme::sections.architecture',
        'binding' => 'data',
        'renders' => ['section-header', 'layer'],
        'since' => '1.3.0',
        'settings' => [
            'width' => $width('measure'),
            'background' => $background(['none', 'surface', 'tint'], 'surface'),
            'align' => $align('center'),
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'subheading' => ['type' => 'textarea', 'default' => '', 'max' => 280],
            'layers' => ['type' => 'repeater', 'default' => [], 'max' => 10, 'item' => [
                'label' => ['type' => 'text', 'default' => '', 'max' => 60],
                'description' => ['type' => 'text', 'default' => '', 'max' => 160],
                'icon' => ['type' => 'text', 'default' => '', 'translatable' => false],
            ]],
        ],
    ],

    // ---------------------------------------------------------------------
    // Blog family (theme-implementation; Phase 5A). Data-bound for v1: the
    // article cards are authored as repeater fields, so the section renders
    // the same whether driven by a preset, a demo, or the layout editor.
    // A future `query` binding can populate `posts` from the ContentManager
    // without changing the view contract.
    // ---------------------------------------------------------------------
    'post-grid' => [
        'title' => 'Post Grid',
        'category' => 'blog',
        'view' => 'theme::sections.post-grid',
        'binding' => 'data',
        'renders' => ['section-header', 'card.post'],
        'since' => '1.1.0',
        'settings' => [
            'variant' => $variant(['standard', 'blog-cards', 'magazine-cards'], 'standard'),
            ...$dataSource(6, 12),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint'], 'none'),
            'columns' => ['type' => 'enum', 'values' => [1, 2, 3], 'default' => 3],
            'show_excerpt' => ['type' => 'boolean', 'default' => true],
            'show_meta' => ['type' => 'boolean', 'default' => true],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'subheading' => ['type' => 'textarea', 'default' => '', 'max' => 280],
            'posts' => ['type' => 'repeater', 'default' => [], 'max' => 12, 'item' => [
                'title' => ['type' => 'text', 'default' => '', 'max' => 160],
                'excerpt' => ['type' => 'textarea', 'default' => '', 'max' => 320],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                'date' => ['type' => 'text', 'default' => '', 'translatable' => false],
                'category' => ['type' => 'text', 'default' => '', 'max' => 60],
                'image' => ['type' => 'media', 'default' => null],
            ]],
        ],
    ],

    // ---------------------------------------------------------------------
    // Magazine family (theme-implementation; Phase 5B). Editorial, content-dense
    // sections, all data-bound for v1: the cards/lists are authored as repeater
    // fields so the sections render identically whether driven by a preset, a
    // demo, or the layout editor. A future `query` binding can populate the
    // repeaters from the ContentManager without changing the view contracts.
    // ---------------------------------------------------------------------
    'featured-grid' => [
        'title' => 'Featured Grid',
        'category' => 'magazine',
        'view' => 'theme::sections.featured-grid',
        'binding' => 'data',
        'renders' => ['section-header', 'card.story'],
        'since' => '1.2.0',
        'settings' => [
            'variant' => $variant(['standard', 'magazine-hero'], 'standard'),
            ...$dataSource(7, 12),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface', 'tint'], 'none'),
            'layout' => ['type' => 'enum', 'values' => ['editorial', 'grid'], 'default' => 'editorial'],
            'show_meta' => ['type' => 'boolean', 'default' => true],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            // The lead story — a single-item repeater so its localized leaves
            // resolve and merge like any other repeater (no new field type).
            'main' => ['type' => 'repeater', 'default' => [], 'max' => 1, 'item' => [
                'title' => ['type' => 'text', 'default' => '', 'max' => 160],
                'excerpt' => ['type' => 'textarea', 'default' => '', 'max' => 320],
                'category' => ['type' => 'text', 'default' => '', 'max' => 60],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                'image' => ['type' => 'media', 'default' => null],
                'date' => ['type' => 'text', 'default' => '', 'translatable' => false],
            ]],
            'items' => ['type' => 'repeater', 'default' => [], 'max' => 6, 'item' => [
                'title' => ['type' => 'text', 'default' => '', 'max' => 160],
                'category' => ['type' => 'text', 'default' => '', 'max' => 60],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                'image' => ['type' => 'media', 'default' => null],
                'date' => ['type' => 'text', 'default' => '', 'translatable' => false],
            ]],
        ],
    ],

    'category-blocks' => [
        'title' => 'Category Blocks',
        'category' => 'magazine',
        'view' => 'theme::sections.category-blocks',
        'binding' => 'data',
        'renders' => ['section-header', 'card.compact'],
        'since' => '1.2.0',
        'settings' => [
            'variant' => $variant(['standard', 'editorial', 'newspaper'], 'standard'),
            ...$dataSource(4, 8),
            'width' => $width('wide'),
            'background' => $background(['none', 'surface'], 'none'),
            'columns' => ['type' => 'enum', 'values' => [2, 3], 'default' => 3],
            // Presentation toggles (Phase 9E-B): the theme reads these to show/hide
            // the category description, the per-post dates, and the "View all" link.
            'show_description' => ['type' => 'boolean', 'default' => true],
            'show_date' => ['type' => 'boolean', 'default' => true],
            'show_view_all' => ['type' => 'boolean', 'default' => true],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'blocks' => ['type' => 'repeater', 'default' => [], 'max' => 6, 'item' => [
                'title' => ['type' => 'text', 'default' => '', 'max' => 80],
                'description' => ['type' => 'textarea', 'default' => '', 'max' => 240],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                'posts' => ['type' => 'repeater', 'default' => [], 'max' => 8, 'item' => [
                    'title' => ['type' => 'text', 'default' => '', 'max' => 160],
                    'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                    'date' => ['type' => 'text', 'default' => '', 'translatable' => false],
                ]],
            ]],
        ],
    ],

    'trending-list' => [
        'title' => 'Trending List',
        'category' => 'magazine',
        'view' => 'theme::sections.trending-list',
        'binding' => 'data',
        'renders' => ['section-header', 'rank-item'],
        'since' => '1.2.0',
        'settings' => [
            'variant' => $variant(['standard', 'compact', 'sidebar-rank'], 'standard'),
            ...$dataSource(6, 10),
            'width' => $width('measure'),
            'background' => $background(['none', 'surface'], 'none'),
            'show_rank' => ['type' => 'boolean', 'default' => true],
        ],
        'fields' => [
            'heading' => ['type' => 'text', 'default' => '', 'max' => 120],
            'items' => ['type' => 'repeater', 'default' => [], 'max' => 10, 'item' => [
                'title' => ['type' => 'text', 'default' => '', 'max' => 160],
                'url' => ['type' => 'text', 'default' => '#', 'translatable' => false],
                'category' => ['type' => 'text', 'default' => '', 'max' => 60],
                'date' => ['type' => 'text', 'default' => '', 'translatable' => false],
            ]],
        ],
    ],
];
