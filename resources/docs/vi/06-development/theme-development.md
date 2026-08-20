---
title: Phát triển Theme
description: Tài liệu Phát triển Theme cho TN CMS.
order: 3
---

# Phát triển Theme

Tài liệu này hướng dẫn cách xây dựng, đóng gói, cài đặt, publish asset và bảo trì theme cho **TN CMS**.

Theme nằm trong `themes/{slug}/`, được nhận diện qua `theme.json`, và render frontend thông qua namespace Blade `theme::`. TN CMS luôn giữ một theme hiệu lực đang active nếu hệ thống còn ít nhất một theme hợp lệ.

## 1. Cấu trúc thư mục theme

```txt
themes/{theme-slug}/
├── theme.json                 # manifest bắt buộc
├── screenshot.png             # ảnh preview trong admin, khuyến nghị 1280×800
├── functions.php              # file cấu hình tuỳ chọn: options, widgets, hooks
├── lang/                      # bản dịch giao diện theme, không bắt buộc
│   ├── en.json
│   └── vi.json
├── assets/                    # asset nguồn, được publish bằng command
│   ├── css/
│   ├── js/
│   └── images/
└── views/                     # Blade views, được resolve qua namespace theme::
    ├── layouts/
    │   └── master.blade.php
    ├── pages/
    │   ├── home.blade.php
    │   └── page.blade.php
    ├── posts/
    │   └── post.blade.php
    ├── archives/
    │   ├── index.blade.php
    │   ├── category.blade.php
    │   └── tag.blade.php
    └── partials/
        ├── header.blade.php
        ├── footer.blade.php
        └── seo.blade.php
```

Thư mục `themes/{slug}` là source code. Web server không serve trực tiếp thư mục này. File public phải được copy sang `public/themes/{slug}` bằng command publish asset.

## 2. `theme.json`

```json
{
  "name": "Default Theme",
  "slug": "default",
  "version": "1.0.0",
  "description": "Default starter theme for TN CMS.",
  "author": "The Nguyen Media",
  "author_uri": "https://tncms.org",
  "support_email": "support@tncms.org",
  "screenshot": "screenshot.png",
  "requires": { "cms": ">=1.0.0" },
  "supports": {
    "menus": ["header", "footer"],
    "theme_options": true,
    "widgets": true
  }
}
```

Các key bắt buộc: `name`, `slug`, `version`, `author`. Theme thiếu manifest hoặc manifest không hợp lệ sẽ bị bỏ qua và hiển thị trong danh sách theme lỗi ở admin, không làm sập frontend.

`requires.cms` và `supports.*` chủ yếu mang tính mô tả. Tính năng thực tế phụ thuộc vào file và schema thật, đặc biệt là `functions.php` cho theme options, widgets và hooks.

## 3. View bắt buộc và không bắt buộc

View bắt buộc:

```txt
views/layouts/master.blade.php
views/pages/page.blade.php
views/posts/post.blade.php
views/archives/index.blade.php
views/partials/seo.blade.php
```

View không bắt buộc:

```txt
views/pages/home.blade.php
views/archives/category.blade.php
views/archives/tag.blade.php
views/partials/header.blade.php
views/partials/footer.blade.php
views/maintenance.blade.php
```

Luôn gọi view thông qua namespace:

```blade
@extends('theme::layouts.master')
@include('theme::partials.header')
@include('theme::partials.seo')
```

TN CMS resolve view từ theme active trước, sau đó fallback về theme `default`. Nhờ vậy một theme con hoặc theme override một phần chỉ cần chứa các file muốn thay đổi.

## 4. Public asset publishing

TN CMS không serve CSS, JavaScript, hình ảnh hoặc font trực tiếp từ `themes/{slug}/assets`. Asset của theme được publish sang:

```txt
public/themes/{slug}/
```

Cách thiết kế này giúp tránh phụ thuộc symlink, chạy tốt trên shared hosting, giữ source file riêng tư và giúp deploy dễ kiểm soát hơn.

### Vòng đời asset

```txt
Sửa asset nguồn
        ↓
themes/{slug}/assets
        ↓
php artisan theme:publish
        ↓
public/themes/{slug}
        ↓
Browser / CDN
```

### Lệnh publish

```bash
php artisan theme:publish
php artisan theme:publish default
php artisan theme:publish --all
php artisan theme:publish default --dry-run
php artisan theme:publish default --clean
```

Ý nghĩa:

| Lệnh | Mục đích |
| --- | --- |
| `theme:publish` | Publish theme đang active. |
| `theme:publish default` | Publish một theme theo slug. |
| `theme:publish --all` | Publish asset của toàn bộ theme hợp lệ. |
| `theme:publish --dry-run` | Xem trước file sẽ được copy, không ghi dữ liệu. |
| `theme:publish --clean` | Xoá `public/themes/{slug}` trước rồi publish lại. |

Khi activate theme, TN CMS cũng tự publish asset. Tuy nhiên trong quy trình phát triển và deploy, vẫn nên chạy command rõ ràng sau khi thay đổi asset.

### File được publish

Các loại file public hợp lệ:

```txt
css, js, mjs, json, png, jpg, jpeg, gif, svg, webp, avif,
ico, woff, woff2, ttf, eot, txt, xml, webmanifest
```

Các loại file bị bỏ qua:

```txt
php, blade.php, env, sql, map, log, bak, file git, file ẩn nhạy cảm
```

Không sửa file trong `public/themes/{slug}`. Luôn sửa file nguồn trong `themes/{slug}/assets`, sau đó publish lại.

## 5. Gọi asset trong Blade

Dùng `theme_asset()`:

```blade
<link rel="stylesheet" href="{{ theme_asset('css/app.css') }}">
<script src="{{ theme_asset('js/app.js') }}" defer></script>
<img src="{{ theme_asset('images/logo.svg') }}" alt="{{ settings('general.site_name') }}">
```

Nếu cần cache busting, có thể gắn version theme:

```blade
<link rel="stylesheet" href="{{ theme_asset('css/app.css') }}?v={{ theme()->active()?->version }}">
```

## 6. Helper có sẵn

Nên dùng helper của CMS thay vì query database trực tiếp.

| Helper | Mục đích |
| --- | --- |
| `settings('group.key', $default)` | Đọc setting CMS. |
| `theme()` / `theme('slug')` | Truy cập Theme Manager hoặc theme object. |
| `theme_asset('css/app.css')` | Tạo URL public cho asset của theme active. |
| `theme_view('pages.page')` | Tạo tên view dạng `theme::`. |
| `frontend_menu('header')` | Lấy cây menu đã sẵn sàng để render. |
| `language()` / `current_locale()` | Language Manager và locale hiện tại. |
| `language_switcher()` | Dữ liệu cho bộ chuyển ngôn ngữ frontend. |
| `content_url($content)` | URL bài viết/trang theo locale. |
| `term_url($term)` | URL taxonomy theo locale. |
| `localized_url('en', '/path')` | Tạo path theo locale. |
| `seo()` | Ngữ cảnh SEO hiện tại. |
| `cms_html($body)` | HTML đã sanitize an toàn. |
| `theme_option('key', $default)` | Giá trị theme option của theme active. |
| `theme_trans('Read more')` | Dịch chuỗi giao diện theme. |
| `widget_area('footer-1')` | Render một widget area. |
| `render_hook('cms.theme.header')` | Render một vùng action hook. |

## 7. SEO partial

Include SEO partial một lần trong `<head>` của layout:

```blade
@include('theme::partials.seo')
```

Layout không nên tự render thêm `<title>`. SEO partial chịu trách nhiệm cho title, description, keywords, robots, canonical, Open Graph, Twitter card và hreflang.

## 8. Nội dung và featured image

`pages/page.blade.php` và `posts/post.blade.php` nhận các biến như `$content`, `$title`, `$body`, `$excerpt`, `$featuredImage`, `$publishedAt`.

```blade
@if ($featuredImage)
    <img src="{{ $featuredImage }}" alt="{{ $title }}" loading="lazy">
@endif

<div class="entry-content">
    {!! cms_html($body) !!}
</div>
```

Featured image hiện được lưu dưới dạng URL string. Luôn escape attribute và render rich text qua `cms_html()`.

## 9. Menu

Khai báo vị trí menu trong `theme.json`:

```json
"supports": {
  "menus": ["header", "footer"]
}
```

Render menu bằng `frontend_menu()`:

```blade
@foreach (frontend_menu('header') as $node)
    <a href="{{ $node['url'] }}">{{ $node['title'] }}</a>
@endforeach
```

URL menu tự đi theo hệ thống slug, permalink và locale của CMS.

## 10. Hỗ trợ đa ngôn ngữ

Tạo link bằng `content_url()`, `term_url()`, `localized_url()` và `language_switcher()`. Không hardcode `/en/...` hoặc `/vi/...`.

Locale mặc định có thể hiển thị không prefix, còn các locale khác có thể dùng dạng `/{locale}/...` tuỳ cấu hình CMS.

## 11. Theme options

Theme có thể khai báo option trong `functions.php`. TN CMS render các option này tại **Appearance → Theme Options** và lưu giá trị vào `cms_settings`.

`functions.php` phải return array và không nên chạy side effect.

```php
<?php

return [
    'options' => [
        'sections' => [
            [
                'key' => 'identity',
                'label' => 'Site Identity',
                'description' => 'Logo and brand display.',
                'fields' => [
                    [
                        'key' => 'logo',
                        'label' => 'Logo',
                        'type' => 'image',
                        'default' => null,
                    ],
                    [
                        'key' => 'show_tagline',
                        'label' => 'Show tagline',
                        'type' => 'boolean',
                        'default' => true,
                    ],
                ],
            ],
        ],
    ],
];
```

Các field type hỗ trợ:

```txt
text, textarea, boolean, number, select, image, color
```

Đọc giá trị trong view:

```blade
@php($logo = theme_option('logo'))
@if (is_string($logo) && $logo !== '')
    <img src="{{ $logo }}" alt="{{ settings('general.site_name') }}">
@else
    {{ settings('general.site_name') }}
@endif
```

Với dữ liệu nhạy cảm khi output, ví dụ màu trong CSS, hãy validate trước khi in ra.

## 12. Bản dịch theme

Chuỗi giao diện của theme nằm trong:

```txt
themes/{slug}/lang/en.json
themes/{slug}/lang/vi.json
```

Ví dụ:

```json
{
  "Read more": "Đọc thêm",
  "No posts yet.": "Chưa có bài viết nào."
}
```

Dùng `theme_trans()` trong Blade:

```blade
<a href="{{ content_url($post) }}">{{ theme_trans('Read more') }}</a>
```

Theme translation chỉ dành cho chuỗi frontend của theme. Chuỗi admin thuộc về file translation của Core CMS.

## 13. Widgets

Render widget area:

```blade
{!! widget_area('footer-1') !!}
{!! widget_area('sidebar-blog', current_locale()) !!}
```

Đăng ký widget area và widget riêng từ `functions.php`:

```php
<?php

return [
    'widget_areas' => [
        ['slug' => 'homepage-after-hero', 'name' => 'Homepage After Hero'],
    ],
    'widgets' => [
        \MyTheme\Widgets\PromoWidget::class,
    ],
];
```

Output của widget phải an toàn. Escape text và sanitize HTML trước khi return.

## 14. Hooks và shortcodes

Expose hook region trong layout:

```blade
{!! render_hook('cms.theme.header') !!}
@include('theme::partials.header')

<main class="site-main">
    {!! render_hook('cms.theme.before_content') !!}
    @yield('content')
    {!! render_hook('cms.theme.after_content') !!}
</main>

@include('theme::partials.footer')
{!! render_hook('cms.theme.footer') !!}
```

Plugin hoặc theme có thể inject markup qua action:

```php
add_action('cms.theme.header', function (): void {
    echo '<link rel="stylesheet" href="/plugins/announce/bar.css">';
});
```

Shortcode cũng có thể được đăng ký từ PHP. TN CMS không thực thi PHP lưu trong nội dung.

## 15. Cài theme từ ZIP

Theme ZIP có thể có một thư mục bọc ngoài hoặc chứa file ngay ở root:

```txt
my-theme.zip
└── my-theme/
    ├── theme.json
    ├── screenshot.png
    ├── functions.php
    ├── lang/
    ├── assets/
    └── views/
        └── layouts/master.blade.php
```

Cài tại **Appearance → Install Theme**. Installer kiểm tra ZIP, chặn path không an toàn và symlink, tìm đúng một `theme.json`, validate manifest, rồi copy file vào `themes/{slug}`. Theme sau khi cài vẫn ở trạng thái inactive cho đến khi được activate.

Theme đang active không thể bị overwrite. Hãy activate theme khác trước nếu cần thay thế.

## 16. Quy trình deploy

Quy trình production khuyến nghị:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan theme:publish --all
php artisan optimize
```

Quy trình local development:

```bash
# sửa themes/default/assets/css/app.css
php artisan theme:publish default
php artisan optimize:clear
```

Dùng `--clean` khi file đã xoá trong `assets/` cũng cần biến mất khỏi `public/themes/{slug}`.

## 17. Checklist đóng gói theme

Trước khi gửi theme ZIP, nên có:

```txt
✓ theme.json
✓ screenshot.png
✓ views/
✓ assets/
✓ functions.php, nếu theme khai báo options/widgets/hooks
✓ lang/, nếu theme có chuỗi giao diện cần dịch
```

Không đóng gói:

```txt
✗ node_modules/
✗ vendor/
✗ .git/
✗ .env
✗ storage/
✗ public/themes/{slug}/
✗ build cache và file tạm
```

## 18. Không nên làm

- Không sửa `vendor/`.
- Không serve file trực tiếp từ `themes/{slug}`.
- Không sửa file generated trong `public/themes/{slug}`.
- Không hardcode admin path.
- Không render thêm `<title>` ngoài SEO partial.
- Không output HTML chưa sanitize.
- Không query database trực tiếp khi đã có helper hoặc manager của CMS.
- Không gọi trực tiếp view của theme khác; hãy dùng namespace `theme::` và cơ chế fallback.

## 19. FAQ

### Vì sao sửa CSS nhưng frontend không thay đổi?

Bạn đã sửa asset nguồn nhưng chưa publish lại:

```bash
php artisan theme:publish default
```

### Theme có dùng symlink được không?

Không. TN CMS chủ động copy asset thay vì dùng symlink để tương thích shared hosting.

### Có nên commit `public/themes/{slug}` không?

Thông thường là không. Hãy commit source trong `themes/{slug}` và publish asset khi deploy.

### Có dùng Vite, Tailwind hoặc build tool khác được không?

Được. Build output vào `themes/{slug}/assets`, sau đó chạy `php artisan theme:publish`.

### Có đặt file PHP trong `assets/` được không?

Không. Public asset publishing sẽ bỏ qua PHP và Blade file. Code thực thi phải nằm trong source theme, không nằm trong public assets.

---

TN CMS · https://tncms.org · support@tncms.org · The Nguyen Media
