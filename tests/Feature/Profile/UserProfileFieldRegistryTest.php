<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TheNguyen\CMS\Exceptions\InvalidProfileFieldDefinitionException;
use TheNguyen\CMS\Exceptions\ProfileFieldCollisionException;
use TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Profile\ProfileFieldDefinition;
use TheNguyen\CMS\Profile\UserProfileFieldRegistry;

/**
 * P1-A — UserProfileFieldRegistry contract: in-memory registration, stable
 * namespaced identity, reserved-key protection, deterministic collision policy,
 * idempotence, and schema-guarded non-destructive reconciliation
 * (CLARIFICATION-001: persistence is explicit + Schema::hasTable-guarded, never
 * an unguarded per-boot write — mirrors PermissionManager::syncDefaults()).
 */
class UserProfileFieldRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): UserProfileFieldRegistry
    {
        return app('cms.user_profile_fields');
    }

    public function test_binding_is_a_shared_singleton(): void
    {
        $this->assertInstanceOf(UserProfileFieldRegistry::class, app('cms.user_profile_fields'));
        $this->assertSame(app('cms.user_profile_fields'), app('cms.user_profile_fields'));
        $this->assertSame(app('cms.user_profile_fields'), app(UserProfileFieldRegistry::class));
    }

    public function test_registers_group_and_field_with_normalized_identity(): void
    {
        $r = $this->registry();
        $r->registerGroup('Professional', ['sort_order' => 20], 'core');       // normalized → 'professional'
        $r->registerField(' Professional.Job_Title ', ['group' => 'professional', 'type' => 'text'], 'core');

        $this->assertTrue($r->hasGroup('professional'));
        $this->assertTrue($r->has('professional.job_title'));

        $def = $r->get('professional.job_title');
        $this->assertInstanceOf(ProfileFieldDefinition::class, $def);
        $this->assertSame('professional.job_title', $def->key);
        $this->assertSame('professional', $def->group);
        $this->assertSame('text', $def->type);
        $this->assertSame('owner', $def->visibility);   // privacy-preserving default
        $this->assertSame('owner', $def->editability);
        $this->assertFalse($def->isEncrypted);
        $this->assertSame('core', $def->source);
    }

    public function test_in_group_returns_fields_in_deterministic_order(): void
    {
        $r = $this->registry();
        $r->registerGroup('social', [], 'core');
        $r->registerField('social.github', ['group' => 'social', 'type' => 'url', 'sort_order' => 30], 'core');
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'url', 'sort_order' => 10], 'core');

        $keys = array_map(fn (ProfileFieldDefinition $d) => $d->key, $r->inGroup('social'));
        $this->assertSame(['social.linkedin', 'social.github'], $keys);
    }

    public function test_rejects_non_namespaced_field_key(): void
    {
        $r = $this->registry();
        $r->registerGroup('contact', [], 'core');
        // 'website' is not reserved, so this exercises the namespacing rule
        // specifically (a bare, un-namespaced key is rejected as malformed).
        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $r->registerField('website', ['group' => 'contact', 'type' => 'url'], 'core');
    }

    public function test_rejects_reserved_users_column_and_auth_identifier_keys(): void
    {
        $r = $this->registry();
        $r->registerGroup('contact', [], 'core');

        $this->expectException(ReservedProfileFieldKeyException::class);
        // 'email' is both an auth identifier and a users column — rejected regardless of namespace attempt.
        $r->registerField('email', ['group' => 'contact', 'type' => 'email'], 'core');
    }

    public function test_plugin_may_not_claim_core_namespace(): void
    {
        $r = $this->registry();
        $r->registerGroup('core_identity', [], 'core');
        $this->expectException(ReservedProfileFieldKeyException::class);
        $r->registerField('core.secret', ['group' => 'core_identity', 'type' => 'text'], 'plugin:acme');
    }

    public function test_rejects_unknown_field_type(): void
    {
        $r = $this->registry();
        $r->registerGroup('misc', [], 'core');
        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $r->registerField('misc.weird', ['group' => 'misc', 'type' => 'quantum'], 'core');
    }

    public function test_rejects_field_referencing_unknown_group(): void
    {
        $r = $this->registry();
        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $r->registerField('ghost.field', ['group' => 'does_not_exist', 'type' => 'text'], 'core');
    }

    public function test_rejects_arbitrary_php_in_validation_metadata(): void
    {
        $r = $this->registry();
        $r->registerGroup('misc', [], 'core');
        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $r->registerField('misc.evil', [
            'group' => 'misc', 'type' => 'text',
            'validation' => ['callback' => fn () => true], // closures forbidden
        ], 'core');
    }

    public function test_identical_reregistration_is_idempotent(): void
    {
        $r = $this->registry();
        $r->registerGroup('social', [], 'core');
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'url', 'visibility' => 'public'], 'core');
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'url', 'visibility' => 'public'], 'core');

        $this->assertCount(1, $r->inGroup('social'));
    }

    public function test_incompatible_reregistration_same_source_is_a_collision(): void
    {
        $r = $this->registry();
        $r->registerGroup('social', [], 'core');
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'url'], 'core');
        $this->expectException(ProfileFieldCollisionException::class);
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'text'], 'core'); // changed type
    }

    public function test_cross_source_collision_is_rejected(): void
    {
        $r = $this->registry();
        $r->registerGroup('social', [], 'core');
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'url'], 'plugin:a');
        $this->expectException(ProfileFieldCollisionException::class);
        $r->registerField('social.linkedin', ['group' => 'social', 'type' => 'url'], 'plugin:b');
    }

    public function test_reconcile_persists_definitions_idempotently(): void
    {
        $r = $this->registry();
        $r->registerGroup('professional', ['sort_order' => 20], 'core');
        $r->registerField('professional.job_title', [
            'group' => 'professional', 'type' => 'text', 'label' => 'Job title', 'help' => 'Shown publicly.',
            'visibility' => 'public',
        ], 'core');

        $r->reconcile();
        $r->reconcile(); // idempotent — no duplicates

        $this->assertSame(1, UserProfileFieldGroup::query()->where('key', 'professional')->count());
        $field = UserProfileField::query()->where('key', 'professional.job_title')->firstOrFail();
        $this->assertSame('text', $field->type);
        $this->assertSame('public', $field->visibility);
        $this->assertTrue((bool) $field->is_locked); // core/plugin fields are locked
        $this->assertSame(1, UserProfileField::query()->where('key', 'professional.job_title')->count());
    }

    public function test_reconcile_is_schema_guarded_and_fails_closed(): void
    {
        Schema::dropIfExists('cms_user_profile_values');
        Schema::dropIfExists('cms_user_profile_field_translations');
        Schema::dropIfExists('cms_user_profile_fields');
        Schema::dropIfExists('cms_user_profile_field_groups');

        $r = $this->registry();
        $r->registerGroup('professional', [], 'core');
        $r->registerField('professional.job_title', ['group' => 'professional', 'type' => 'text'], 'core');

        // Must not throw when the schema is absent (fresh install / mid-migration / disabled).
        $r->reconcile();
        $this->assertTrue(true);
    }

    public function test_reconcile_never_deletes_rows_for_unregistered_definitions(): void
    {
        // Simulate a previously-persisted (e.g. now-disabled plugin) field.
        $group = UserProfileFieldGroup::query()->create(['key' => 'legacy', 'source' => 'plugin:gone']);
        UserProfileField::query()->create(['key' => 'legacy.old', 'group_id' => $group->id, 'type' => 'text', 'source' => 'plugin:gone']);

        $r = $this->registry();
        $r->registerGroup('professional', [], 'core');
        $r->registerField('professional.job_title', ['group' => 'professional', 'type' => 'text'], 'core');
        $r->reconcile();

        // Non-destructive: the unregistered legacy field is preserved, not purged.
        $this->assertSame(1, UserProfileField::query()->where('key', 'legacy.old')->count());
        $this->assertSame(1, UserProfileField::query()->where('key', 'professional.job_title')->count());
    }
}
