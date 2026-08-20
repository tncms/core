<?php

declare(strict_types=1);

/**
 * Default Theme functions.
 *
 * Loaded safely by the ThemeManager as a *config-returning* file: it must return
 * an array and must not produce side effects. The "options" key declares the
 * Theme Options schema rendered by Appearance → Theme Options and read in views
 * via the theme_option() helper.
 *
 * This MUST stay in sync with theme.options.json (the declarative mirror, per
 * theme-architecture 07/14): both describe the same option set. functions.php is
 * the schema the ThemeManager loads; theme.options.json documents it.
 *
 * Supported field types: text, textarea, boolean, number, select, image, color.
 *
 * @return array<string, mixed>
 */

return [
    // Widget regions this theme exposes (Widget Foundation integration). Core
    // reads these from themeConfig()['widget_areas'] and mirrors them into
    // cms_widget_areas (source = theme) so they appear in Appearance → Widgets.
    // The slugs are stable contracts referenced by this theme's Blade partials
    // (sidebar/footer); do not rename without updating those views.
    'widget_areas' => [
        ['slug' => 'archive.sidebar', 'name' => 'Archive Sidebar', 'description' => 'Shown beside category, tag, author and date archive listings.'],
        ['slug' => 'post.sidebar', 'name' => 'Post Sidebar', 'description' => 'Shown beside single posts.'],
        ['slug' => 'blog.sidebar', 'name' => 'Blog Sidebar', 'description' => 'Shown beside a dedicated blog index, when one is in use.'],
        ['slug' => 'footer.column_1', 'name' => 'Footer Column 1', 'description' => 'First footer widget column, rendered below the footer menu.'],
        ['slug' => 'footer.column_2', 'name' => 'Footer Column 2', 'description' => 'Second footer widget column, rendered below the footer menu.'],
        ['slug' => 'footer.column_3', 'name' => 'Footer Column 3', 'description' => 'Third footer widget column, rendered below the footer menu.'],
        ['slug' => 'footer.column_4', 'name' => 'Footer Column 4', 'description' => 'Fourth footer widget column, rendered below the footer menu.'],
    ],

    'options' => [
        'sections' => [
            [
                'key' => 'brand',
                'label' => 'Brand',
                'description' => 'Logo, site icon, and brand display.',
                'fields' => [
                    [
                        'key' => 'logo',
                        'label' => 'Logo',
                        'type' => 'image',
                        'default' => null,
                        'helper' => 'Shown in the header. Recommended transparent PNG or SVG. Falls back to the site name when empty.',
                    ],
                    [
                        'key' => 'favicon',
                        'label' => 'Site icon (favicon)',
                        'type' => 'image',
                        'default' => null,
                        'helper' => 'Shown in the browser tab. Falls back to the site icon from General Settings.',
                    ],
                    [
                        'key' => 'show_tagline',
                        'label' => 'Show tagline',
                        'type' => 'boolean',
                        'default' => true,
                        'helper' => 'Display the site tagline (from General Settings) in the header.',
                    ],
                ],
            ],
            [
                'key' => 'colors',
                'label' => 'Colors',
                'description' => 'Brand accents, exposed to the theme as CSS variables.',
                'fields' => [
                    [
                        'key' => 'primary_color',
                        'label' => 'Primary color',
                        'type' => 'color',
                        'default' => '#2563eb',
                        'helper' => 'Used as the --tncms-primary CSS variable.',
                    ],
                    [
                        'key' => 'secondary_color',
                        'label' => 'Secondary color',
                        'type' => 'color',
                        'default' => '#0ea5e9',
                        'helper' => 'Secondary accent (--tncms-secondary), used for gradients/links.',
                    ],
                ],
            ],
            [
                'key' => 'layout',
                'label' => 'Layout',
                'fields' => [
                    [
                        'key' => 'container_width',
                        'label' => 'Container width',
                        'type' => 'select',
                        'default' => 'default',
                        'options' => [
                            'default' => 'Default',
                            'wide' => 'Wide',
                            'full' => 'Full width',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'homepage',
                'label' => 'Homepage',
                'description' => 'The preset blueprint that powers the homepage. The live value is the setting theme.default.homepage_preset; import a demo to fill it with content.',
                'fields' => [
                    [
                        'key' => 'homepage_preset',
                        'label' => 'Homepage preset',
                        'type' => 'select',
                        'default' => 'company',
                        'options' => [
                            'company' => 'Company',
                            'blog' => 'Blog',
                            'magazine' => 'Magazine',
                        ],
                        'helper' => 'Selects which preset blueprint powers the homepage. Available presets are discovered from the theme\'s presets/ folder.',
                    ],
                ],
            ],
            [
                'key' => 'content_layout',
                'label' => 'Blog / Content Layout',
                'description' => 'Controls the listing (category, tag, author, archive) and single-post layouts. A presentation concern, stored in Theme Options only.',
                'fields' => [
                    [
                        'key' => 'archive_layout',
                        'label' => 'Archive layout',
                        'type' => 'select',
                        'default' => 'right-sidebar',
                        'options' => [
                            'full-width' => 'Full width',
                            'left-sidebar' => 'Left sidebar',
                            'right-sidebar' => 'Right sidebar',
                        ],
                        'helper' => 'Controls category, tag, author and archive listing pages.',
                    ],
                    [
                        'key' => 'post_layout',
                        'label' => 'Post layout',
                        'type' => 'select',
                        'default' => 'right-sidebar',
                        'options' => [
                            'full-width' => 'Full width',
                            'left-sidebar' => 'Left sidebar',
                            'right-sidebar' => 'Right sidebar',
                        ],
                        'helper' => 'Controls single post detail pages.',
                    ],
                    [
                        'key' => 'sidebar_width',
                        'label' => 'Sidebar width',
                        'type' => 'select',
                        'default' => '320',
                        'options' => [
                            '280' => '280px',
                            '320' => '320px',
                            '360' => '360px',
                        ],
                        'helper' => 'Controls the sidebar width on left/right sidebar layouts.',
                    ],
                    [
                        'key' => 'show_post_author',
                        'label' => 'Show post author',
                        'type' => 'boolean',
                        'default' => true,
                        'helper' => 'Display the author block on single posts.',
                    ],
                    [
                        'key' => 'show_post_tags',
                        'label' => 'Show post tags',
                        'type' => 'boolean',
                        'default' => true,
                        'helper' => 'Display the tag list on single posts.',
                    ],
                    [
                        'key' => 'show_related_posts',
                        'label' => 'Show related posts',
                        'type' => 'boolean',
                        'default' => true,
                        'helper' => 'Display the related posts block on single posts (when related posts are available).',
                    ],
                    [
                        'key' => 'related_posts_count',
                        'label' => 'Related posts count',
                        'type' => 'number',
                        'default' => 3,
                        'min' => 1,
                        'max' => 12,
                        'helper' => 'How many related posts to display.',
                    ],
                ],
            ],
            [
                'key' => 'footer',
                'label' => 'Footer',
                'fields' => [
                    [
                        'key' => 'footer_text',
                        'label' => 'Footer text',
                        'type' => 'textarea',
                        'default' => '© 2026 TN CMS',
                        'helper' => 'Shown in the site footer. Leave empty to use the default copyright line.',
                    ],
                ],
            ],
            [
                'key' => 'social',
                'label' => 'Social',
                'description' => 'Social profile links shown in the theme.',
                'fields' => [
                    [
                        'key' => 'social_links',
                        'label' => 'Social links',
                        'type' => 'textarea',
                        'default' => '',
                        'helper' => 'One URL per line.',
                    ],
                ],
            ],
        ],
    ],
];
