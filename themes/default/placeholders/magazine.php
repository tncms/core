<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Magazine placeholder content (theme-implementation; Phase 9B)
|--------------------------------------------------------------------------
|
| Curated, bilingual placeholder items for the magazine-family sections
| (featured-grid, category-blocks, trending-list). Used when a section's
| `data_source` setting is `placeholder` — the safety-net mode that always
| renders something even before any real content exists.
|
| Rules: clean localized shape `{ vi, en }` for translatable leaves; `url`
| and `date` are NOT localized; `image` is always null (null-safe in Blade,
| no dependency on imported media). Topics are generic CMS/editorial themes —
| no real brands and no copied article text.
|
| Keyed by section type → a map of that section's repeater field(s) → items.
*/

$t = static fn (string $vi, string $en): array => ['vi' => $vi, 'en' => $en];

return [
    'featured-grid' => [
        'main' => [
            [
                'title' => $t('Xây dựng một CMS hiện đại với kiến trúc lõi cổng', 'Building a modern CMS on a portable core'),
                'excerpt' => $t(
                    'Tách ứng dụng host mỏng khỏi gói lõi để nâng cấp an toàn và tái sử dụng giữa các dự án.',
                    'Separate a thin host app from a portable core for safe upgrades and reuse across projects.',
                ),
                'category' => $t('Kiến trúc', 'Architecture'),
                'url' => '#',
                'date' => '2026-06-19',
                'image' => null,
            ],
        ],
        'items' => [
            [
                'title' => $t('Hệ thống bố cục trang chủ theo khối', 'A section-based homepage layout system'),
                'category' => $t('Giao diện', 'Theming'),
                'url' => '#',
                'date' => '2026-06-17',
                'image' => null,
            ],
            [
                'title' => $t('Đa ngôn ngữ đúng cách: nội dung theo từng locale', 'Multilingual done right: per-locale content'),
                'category' => $t('Bản địa hóa', 'Localization'),
                'url' => '#',
                'date' => '2026-06-15',
                'image' => null,
            ],
            [
                'title' => $t('Plugin tự đóng gói, cài đặt bằng một lệnh', 'Self-contained, one-command plugins'),
                'category' => $t('Mở rộng', 'Extensions'),
                'url' => '#',
                'date' => '2026-06-12',
                'image' => null,
            ],
            [
                'title' => $t('Tối ưu truy vấn: ngân sách và đo lường', 'Query budgets and performance measurement'),
                'category' => $t('Hiệu năng', 'Performance'),
                'url' => '#',
                'date' => '2026-06-10',
                'image' => null,
            ],
        ],
    ],

    'category-blocks' => [
        'blocks' => [
            [
                'title' => $t('Hướng dẫn', 'Guides'),
                'description' => $t('Bắt đầu nhanh và đi sâu vào từng tính năng.', 'Quick starts and deep dives into each feature.'),
                'url' => '#',
                'posts' => [
                    ['title' => $t('Cài đặt và cấu hình ban đầu', 'Install and first-run configuration'), 'url' => '#', 'date' => '2026-06-18'],
                    ['title' => $t('Tạo giao diện đầu tiên của bạn', 'Create your first theme'), 'url' => '#', 'date' => '2026-06-14'],
                    ['title' => $t('Trình chỉnh sửa bố cục cho người biên tập', 'The layout editor for editors'), 'url' => '#', 'date' => '2026-06-09'],
                ],
            ],
            [
                'title' => $t('Bảo mật', 'Security'),
                'description' => $t('Thực hành an toàn cho dữ liệu và người dùng.', 'Safe practices for data and users.'),
                'url' => '#',
                'posts' => [
                    ['title' => $t('Phân quyền theo vai trò', 'Role-based access control'), 'url' => '#', 'date' => '2026-06-16'],
                    ['title' => $t('Làm sạch HTML do người dùng nhập', 'Sanitizing user-supplied HTML'), 'url' => '#', 'date' => '2026-06-11'],
                    ['title' => $t('Quản lý bí mật và biến môi trường', 'Managing secrets and environment'), 'url' => '#', 'date' => '2026-06-07'],
                ],
            ],
            [
                'title' => $t('Di trú', 'Migration'),
                'description' => $t('Chuyển nội dung từ nền tảng khác sang.', 'Move content in from other platforms.'),
                'url' => '#',
                'posts' => [
                    ['title' => $t('Lập kế hoạch di trú nội dung', 'Planning a content migration'), 'url' => '#', 'date' => '2026-06-13'],
                    ['title' => $t('Ánh xạ phân loại và thẻ', 'Mapping taxonomies and tags'), 'url' => '#', 'date' => '2026-06-08'],
                    ['title' => $t('Giữ liên kết cũ không gãy', 'Keeping old links from breaking'), 'url' => '#', 'date' => '2026-06-05'],
                ],
            ],
        ],
    ],

    'trending-list' => [
        'items' => [
            ['title' => $t('Mười điều nên cấu hình ngay sau khi cài', 'Ten things to configure right after install'), 'category' => $t('Hướng dẫn', 'Guides'), 'url' => '#', 'date' => '2026-06-19'],
            ['title' => $t('Hiểu về resolver bố cục trang chủ', 'Understanding the homepage layout resolver'), 'category' => $t('Kiến trúc', 'Architecture'), 'url' => '#', 'date' => '2026-06-18'],
            ['title' => $t('Mẹo tối ưu hình ảnh cho trang nội dung', 'Image optimization tips for content pages'), 'category' => $t('Hiệu năng', 'Performance'), 'url' => '#', 'date' => '2026-06-16'],
            ['title' => $t('Thiết kế widget cho thanh bên', 'Designing widgets for the sidebar'), 'category' => $t('Giao diện', 'Theming'), 'url' => '#', 'date' => '2026-06-14'],
            ['title' => $t('Quy trình dịch nội dung theo locale', 'A workflow for translating content'), 'category' => $t('Bản địa hóa', 'Localization'), 'url' => '#', 'date' => '2026-06-12'],
            ['title' => $t('Viết plugin đầu tiên của bạn', 'Writing your first plugin'), 'category' => $t('Mở rộng', 'Extensions'), 'url' => '#', 'date' => '2026-06-10'],
        ],
    ],
];
