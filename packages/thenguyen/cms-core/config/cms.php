<?php

declare(strict_types=1);

return [
    'name' => env('CMS_NAME', 'TN CMS'),

    'paths' => [
        'base' => base_path(),
        'public' => public_path(),
        'modules' => base_path('modules'),
        'themes' => base_path('themes'),
        'plugins' => base_path('plugins'),
        'uploads' => public_path('uploads'),
        'theme_assets' => public_path('themes'),
        'cms_assets' => public_path('vendor/cms'),
        // Core-owned public namespace for opt-in plugin assets: each plugin's declared built assets are
        // provisioned to <plugin_assets>/<slug> (e.g. public/vendor/<slug>). Slug-isolated; never
        // plugin-specified. See TheNguyen\CMS\Services\PluginAssetPublisher.
        'plugin_assets' => public_path('vendor'),
    ],

    'theme' => [
        'active' => env('CMS_ACTIVE_THEME', 'default'),
    ],

    /*
     | Admin panel URL segment. Collected by the web installer and consumed by
     | the Filament admin panel (App\Providers\Filament\AdminPanelProvider).
     | Changing it requires a route cache clear to take effect.
     */
    'admin_path' => env('ADMIN_PATH', 'admin'),

    'deployment' => [
        'mode' => env('CMS_DEPLOYMENT_MODE', 'public_root'),
    ],

    /*
     | Public content resolution cache (v1.0.0-beta.6.3). Caches the resolved
     | cms_slugs reference for public URLs so repeated hits skip the slug lookup.
     | TTL in seconds; set CMS_PUBLIC_CACHE_TTL=0 to disable the public cache
     | entirely. Invalidation is versioned — see PublicContentCacheManager.
     */
    'cache' => [
        'public_ttl' => env('CMS_PUBLIC_CACHE_TTL', 3600),
    ],

    /*
     | /cms-health output. show_paths exposes absolute filesystem paths
     | (base/public) for LOCAL debugging only. It is OFF by default and is NOT
     | tied to APP_DEBUG — the public health endpoint must never leak server
     | paths unless an operator explicitly opts in.
     */
    'health' => [
        'show_paths' => env('CMS_HEALTH_SHOW_PATHS', false),
    ],

    /*
     | Media upload security. These are the SERVER-SIDE source of truth, enforced
     | in MediaManager::upload() for every entry point (media library, media
     | picker, rich editor). Filament's client-side acceptedFileTypes() is only a
     | UX hint and is trivially bypassed, so it is never relied on for safety.
     |
     | allowed_extensions / allowed_mimes are an allow-list (fail-safe deny).
     | denied_extensions is an always-on hard block that wins even if an operator
     | mistakenly whitelists an executable type. allow_svg is opt-in because SVG
     | is an active-content format; when enabled every SVG is sanitized before it
     | is written to disk.
     */
    'media' => [
        // Hard ceiling in bytes. The admin UI also exposes a softer per-site
        // limit via the media.max_upload_size_mb setting; the smaller of the two
        // wins at upload time.
        'max_upload_size' => (int) env('CMS_MEDIA_MAX_UPLOAD_SIZE', 8 * 1024 * 1024),

        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf'],

        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
            'application/pdf',
        ],

        // Always denied, regardless of allowed_extensions. Executable / scriptable
        // types that could lead to RCE or stored XSS if ever served.
        'denied_extensions' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
            'exe', 'bat', 'cmd', 'com', 'sh', 'bash', 'cgi', 'pl',
            'js', 'mjs', 'cjs', 'html', 'htm', 'xhtml', 'shtml', 'jsp', 'asp', 'aspx',
        ],

        // SVG is an active-content image format. Off by default; when enabled,
        // uploads are sanitized (scripts, event handlers, foreignObject and
        // javascript:/data: URLs stripped) before being stored.
        'allow_svg' => (bool) env('CMS_MEDIA_ALLOW_SVG', false),
    ],

    /*
     | Performance: per-request query budgets and an opt-in query profiler.
     |
     | The budget never breaks a response — exceeding it is report()ed so it
     | surfaces in logs/monitoring while the page still renders. Budgets are keyed
     | by public page type; 'default' applies to anything unclassified.
     |
     | These budgets are guardrails to catch query regressions (e.g. an N+1), not
     | strict performance targets yet. They are set above the measured baseline of
     | normal pages with demo data so routine requests stay quiet; tighten them as
     | the query paths are optimized.
     |
     | The profiler is fully off unless CMS_DEBUG_QUERIES=true. When on, public
     | responses carry X-TNCMS-Queries (count), X-TNCMS-Time (ms), and
     | X-TNCMS-Cache (HIT/MISS/BYPASS) headers for diagnostics.
     */
    'performance' => [
        'query_budget' => [
            'enabled' => (bool) env('CMS_QUERY_BUDGET_ENABLED', true),
            'budgets' => [
                'homepage' => 25,
                'content' => 5,
                'archive' => 10,
                'category' => 10,
                'tag' => 10,
                'default' => 25,
            ],
        ],

        'profiler' => [
            'enabled' => (bool) env('CMS_DEBUG_QUERIES', false),
        ],
    ],

    /*
     | Emails always treated as super admins, regardless of assigned roles — a
     | safety net so the CMS owner can never be locked out of the admin. Set the
     | CMS_SUPER_ADMIN_EMAILS env var as a comma-separated list.
     */
    'super_admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CMS_SUPER_ADMIN_EMAILS', '')),
    ))),

    /*
     | Preview (v1.0.0-beta.7.1.16). Secure, temporary, signed frontend previews
     | of unpublished content. `default_ttl_minutes` bounds how long a generated
     | preview link stays valid; `route_prefix` is the signed endpoint's path.
     */
    'preview' => [
        'enabled' => (bool) env('CMS_PREVIEW_ENABLED', true),
        'default_ttl_minutes' => (int) env('CMS_PREVIEW_TTL_MINUTES', 30),
        'route_prefix' => 'cms/preview',
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Authentication (v1.0.0-beta.7.1.14)
    |--------------------------------------------------------------------------
    | Defaults for the frontend identity & authentication foundation. These are
    | fallbacks: the DB-backed Core settings under the "auth" group override
    | them at runtime (settings('auth.<key>', config('cms.auth.<key>'))), so the
    | system works before the settings seeder has run (e.g. in tests).
    |
    | Users share one table across frontend and admin; roles decide capability.
    | Frontend login never grants admin access on its own.
    */
    'auth' => [
        // Registration.
        'registration_enabled' => true,
        // Role id assigned to new frontend registrations. Null => resolve the
        // safe "subscriber" role (created on demand). Never defaults to admin.
        'default_role_id' => null,
        // Slug used to resolve/create the safe fallback frontend role.
        'default_role_slug' => 'subscriber',
        'email_verification_required' => false,
        'auto_login_after_registration' => true,

        // Session / remember lifetimes (minutes). Enforced by the
        // cms.frontend_session middleware via an absolute expiry stored in the
        // session, independent of the global session cookie lifetime.
        'frontend_session_lifetime_minutes' => 1440,   // 24h
        'frontend_remember_lifetime_minutes' => 10080, // 7d
        'admin_session_lifetime_minutes' => 720,       // 12h
        'admin_remember_lifetime_minutes' => 43200,    // 30d

        // Session/device invalidation policy.
        'rotate_session_on_device_change' => true,
        'force_logout_on_password_change' => true,
        'single_session_per_user' => false,
        'idle_timeout_minutes' => null,

        // Optional soft IP check: compares this many leading octets of the IPv4
        // address on device-change evaluation. 0 disables the IP signal (mobile
        // networks change IPs; the user-agent hash is the primary signal).
        'ip_prefix_octets' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Updates (CORE-UPGRADE-2)
    |--------------------------------------------------------------------------
    | Read-only remote update discovery. When enabled with a configured HTTPS
    | feed URL, the admin "Software updates" page reports the latest available
    | Core release for the chosen channel. Discovery never downloads or applies
    | anything — upgrades stay manual via the /upgrade wizard.
    |
    | Channels: stable | beta | development. The site's channel is set here and
    | is never changed by the remote feed.
    */
    'update' => [
        'enabled' => env('CMS_UPDATE_ENABLED', false),
        'channel' => env('CMS_UPDATE_CHANNEL', 'stable'),
        // HTTPS-only feed serving the tncms.update/v1 metadata contract.
        'feed_url' => env('CMS_UPDATE_FEED_URL', ''),
    ],
];
