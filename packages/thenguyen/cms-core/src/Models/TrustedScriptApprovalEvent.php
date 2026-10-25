<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit row for a trusted external script approval change
 * (CORE-EXTENSION-TRUST S1).
 *
 * @property int $id
 * @property string $owner_slug
 * @property string $script_key
 * @property string $fingerprint
 * @property string $action
 * @property int|null $actor_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class TrustedScriptApprovalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cms_trusted_script_approval_events';

    protected $fillable = [
        'owner_slug',
        'script_key',
        'fingerprint',
        'action',
        'actor_id',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
