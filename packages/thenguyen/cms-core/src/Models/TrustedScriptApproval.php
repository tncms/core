<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Administrator approval of one plugin's trusted external script declaration
 * (CORE-EXTENSION-TRUST S1). Written only by
 * {@see \TheNguyen\CMS\Services\TrustedScriptApprovalStore}.
 *
 * @property int $id
 * @property string $owner_slug
 * @property string $script_key
 * @property string $fingerprint
 * @property string $url
 * @property string $status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $revoked_by
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revocation_reason
 */
class TrustedScriptApproval extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'cms_trusted_script_approvals';

    protected $fillable = [
        'owner_slug',
        'script_key',
        'fingerprint',
        'url',
        'status',
        'approved_by',
        'approved_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'approved_by' => 'integer',
        'revoked_by' => 'integer',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
