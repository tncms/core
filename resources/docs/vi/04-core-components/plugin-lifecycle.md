# Plugin Lifecycle

## Giới thiệu

Plugin Lifecycle là cơ chế chuẩn của TNCMS để quản lý toàn bộ vòng đời
của một plugin.

### Mục tiêu

-   Đảm bảo plugin luôn được cài đặt đầy đủ trước khi kích hoạt.
-   Không bao giờ để plugin ở trạng thái **Active nhưng chưa cài xong**.
-   Không yêu cầu người dùng chạy thủ công `php artisan migrate`.
-   Hỗ trợ mở rộng cho Upgrade, Uninstall, Assets, Queue, Cache...

## Lifecycle

``` text
Install Plugin -> Validate -> beforeActivate() -> Run Migrations -> Run Seeders -> Verify -> Activate -> afterActivate()
```

## Kiến trúc

### PluginLifecycleManager

Điều phối validate, hooks, database setup, activation và verification.

### PluginDatabaseManager

Discover/run migrations & seeders, verify installation.

### PluginInstallationGuard

Ngăn plugin gây lỗi HTTP 500 khi dữ liệu chưa được cài đặt.

## plugin.json

``` json
{"database":{"migrations":"database/migrations","seeders":"database/seeders"}}
```

## Hooks

-   beforeActivate
-   afterActivate
-   beforeDeactivate
-   afterDeactivate
-   beforeUpgrade
-   afterUpgrade
-   beforeUninstall
-   afterUninstall

## Best Practices

-   Không chạy migration thủ công.
-   Không gọi artisan migrate từ plugin.
-   Để Core quản lý toàn bộ lifecycle.
