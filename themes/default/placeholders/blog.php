<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blog placeholder content (theme-implementation; Phase 9B)
|--------------------------------------------------------------------------
|
| Curated, bilingual placeholder articles for the post-grid section, used when
| its `data_source` setting is `placeholder`. Same rules as the magazine
| dataset: clean `{ vi, en }` localized leaves, non-localized url/date, null
| images, generic CMS topics only.
*/

$t = static fn (string $vi, string $en): array => ['vi' => $vi, 'en' => $en];

return [
    'post-grid' => [
        'posts' => [
            [
                'title' => $t('Ghi chép việc xây dựng một CMS hiện đại', 'Notes on building a modern CMS'),
                'excerpt' => $t('Vì sao tách lõi cổng khỏi ứng dụng host lại quan trọng cho việc nâng cấp.', 'Why a portable core, split from the host app, matters for upgrades.'),
                'category' => $t('Kiến trúc', 'Architecture'),
                'url' => '#',
                'date' => '2026-06-19',
                'image' => null,
            ],
            [
                'title' => $t('Bố cục trang chủ theo khối, dễ biên tập', 'An editable, section-based homepage'),
                'excerpt' => $t('Cách resolver biến cấu hình thành bố cục đã sẵn sàng hiển thị.', 'How the resolver turns configuration into a render-ready layout.'),
                'category' => $t('Giao diện', 'Theming'),
                'url' => '#',
                'date' => '2026-06-16',
                'image' => null,
            ],
            [
                'title' => $t('Đa ngôn ngữ: nội dung theo từng locale', 'Multilingual: per-locale content'),
                'excerpt' => $t('Giữ mỗi ngôn ngữ độc lập mà vẫn chia sẻ cấu trúc chung.', 'Keep each language independent while sharing one structure.'),
                'category' => $t('Bản địa hóa', 'Localization'),
                'url' => '#',
                'date' => '2026-06-13',
                'image' => null,
            ],
            [
                'title' => $t('Plugin tự đóng gói và cài bằng một lệnh', 'Self-contained, one-command plugins'),
                'excerpt' => $t('Đóng gói tính năng để cài đặt và gỡ bỏ an toàn.', 'Package features so they install and uninstall cleanly.'),
                'category' => $t('Mở rộng', 'Extensions'),
                'url' => '#',
                'date' => '2026-06-10',
                'image' => null,
            ],
            [
                'title' => $t('Ngân sách truy vấn cho trang công khai', 'Query budgets for public pages'),
                'excerpt' => $t('Phát hiện hồi quy hiệu năng trước khi người dùng nhận ra.', 'Catch performance regressions before your readers do.'),
                'category' => $t('Hiệu năng', 'Performance'),
                'url' => '#',
                'date' => '2026-06-07',
                'image' => null,
            ],
            [
                'title' => $t('Widget và thanh bên cho giao diện', 'Widgets and sidebars for your theme'),
                'excerpt' => $t('Thêm vùng widget mà không truy vấn cơ sở dữ liệu trong Blade.', 'Add widget regions without querying the database in Blade.'),
                'category' => $t('Giao diện', 'Theming'),
                'url' => '#',
                'date' => '2026-06-04',
                'image' => null,
            ],
        ],
    ],
];
