<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * Operator bootstrap for `content.ownership.adopt` (CORE-UNIFIED-OWNERSHIP-R1).
 *
 * The permission is never synced onto a built-in role and super-admin status
 * does not imply it. Operators grant it per user through a dedicated role:
 *
 *   cms:content-ownership:adopters list
 *   cms:content-ownership:adopters grant {user id|email}
 *   cms:content-ownership:adopters revoke {user id|email}
 *
 * (Equivalent: Roles -> create a role holding only that permission.)
 */
class ContentOwnershipAdoptersCommand extends Command
{
    public const ROLE = 'content-ownership-adopter';

    protected $signature = 'cms:content-ownership:adopters {action : list|grant|revoke} {user? : User id or email}';

    protected $description = 'Grant or revoke the explicit permission that lets extensions adopt existing content';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['list', 'grant', 'revoke'], true)) {
            $this->error('Action must be list, grant or revoke.');

            return self::FAILURE;
        }

        app('cms.permission')->syncDefaults();
        $permission = Permission::query()->where('slug', 'content.ownership.adopt')->firstOrFail();
        $role = Role::query()->firstOrCreate(['slug' => self::ROLE], [
            'name' => 'Content ownership adopter',
            'description' => 'May let an extension adopt existing content.',
            'is_system' => false,
        ]);
        $role->permissions()->sync([$permission->id]);

        if ($action === 'list') {
            foreach ($role->users()->get() as $user) {
                $this->line($user->getKey().' '.$user->getAttribute('email'));
            }

            return self::SUCCESS;
        }

        $ref = trim((string) $this->argument('user'));
        $model = config('auth.providers.users.model', \App\Models\User::class);
        $user = $ref === '' ? null : $model::query()->where(ctype_digit($ref) ? 'id' : 'email', $ref)->first();

        if ($user === null) {
            $this->error('Name the user by id or email.');

            return self::FAILURE;
        }

        $action === 'grant'
            ? $role->users()->syncWithoutDetaching([$user->getKey()])
            : $role->users()->detach($user->getKey());

        $this->info("{$action}: user {$user->getKey()}");

        return self::SUCCESS;
    }
}
