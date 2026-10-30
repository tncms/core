<?php

declare(strict_types=1);

namespace Tests\Support;

use TheNguyen\CMS\ContentAuthority\ContentWriteContext;

/**
 * Explicit write context for TEST FIXTURE content set up outside the behaviour
 * under test (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * Since R1 an unattributed ContentManager write (no actor, no context) is
 * refused. Pre-R1 tests seeded content that way; they now say so explicitly with
 * a bounded host system reason, registered through the supported
 * `cms.content_authority.system_reasons` config — not a global test bypass.
 * Owner guards still apply to system writes.
 */
final class ContentFixtures
{
    public const REASON = 'test_fixture';

    public static function context(): ContentWriteContext
    {
        $reasons = (array) config('cms.content_authority.system_reasons', []);

        if (! in_array(self::REASON, $reasons, true)) {
            config(['cms.content_authority.system_reasons' => [...$reasons, self::REASON]]);
        }

        return ContentWriteContext::system(self::REASON);
    }

    public const OWNER_ACTOR_EMAIL = 'owner-actor@tncms.test';

    /**
     * Sign in the TEST actor for content-ownership fixtures
     * (CORE-UNIFIED-OWNERSHIP-R1): ownership changes need a signed-in actor
     * allowed to create and publish. The actor is a configured super admin
     * (an explicit, test-only config entry); it holds no explicit adoption
     * grant, so it cannot adopt existing content.
     */
    public static function signInOwnerActor(): \Illuminate\Contracts\Auth\Authenticatable
    {
        $emails = (array) config('cms.super_admin_emails', []);

        if (! in_array(self::OWNER_ACTOR_EMAIL, $emails, true)) {
            config(['cms.super_admin_emails' => [...$emails, self::OWNER_ACTOR_EMAIL]]);
        }

        $model = config('auth.providers.users.model', \App\Models\User::class);
        $user = $model::query()->where('email', self::OWNER_ACTOR_EMAIL)->first()
            ?? $model::factory()->create(['email' => self::OWNER_ACTOR_EMAIL]);

        app('auth')->guard()->setUser($user);
        app('auth')->shouldUse(config('auth.defaults.guard', 'web'));

        return $user;
    }

    /** PHP source of an allow-all ContentWriteGuard for a fixture plugin namespace. */
    public static function allowAllGuardSource(string $namespace): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
            ."use TheNguyen\\CMS\\ContentAuthority\\ContentWriteDecision;\n"
            ."use TheNguyen\\CMS\\ContentAuthority\\ContentWriteIntent;\n"
            ."use TheNguyen\\CMS\\Contracts\\ContentWriteGuard;\n\n"
            ."/** Fixture owner guard: the owner allows every sanctioned write. */\n"
            ."final class OwnerGuard implements ContentWriteGuard\n{\n"
            ."    public function check(ContentWriteIntent \$intent): ContentWriteDecision\n    {\n"
            ."        return ContentWriteDecision::allow();\n    }\n}\n";
    }
}
