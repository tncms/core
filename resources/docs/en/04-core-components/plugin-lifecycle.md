# Plugin Lifecycle

## Overview

Plugin Lifecycle is the standard mechanism used by TNCMS to manage the
complete lifecycle of a plugin.

### Goals

-   Ensure every plugin is fully installed before activation.
-   Never allow an **Active but not installed** state.
-   Eliminate manual `php artisan migrate`.
-   Prepare for Upgrade, Uninstall, Assets, Queue and Cache.

## Lifecycle

``` text
Install Plugin -> Validate -> beforeActivate() -> Run Migrations -> Run Seeders -> Verify -> Activate -> afterActivate()
```

## Architecture

### PluginLifecycleManager

Coordinates validation, hooks, database setup, activation and
verification.

### PluginDatabaseManager

Discovers/runs migrations & seeders and verifies installation.

### PluginInstallationGuard

Prevents HTTP 500 when plugin tables are missing.

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

-   Do not run migrations manually.
-   Do not call artisan migrate from plugins.
-   Let the Core manage the lifecycle.
