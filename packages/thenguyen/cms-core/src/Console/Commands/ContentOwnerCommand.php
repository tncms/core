<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\ContentOwnershipException;

/**
 * Operator control of content ownership (CORE-CONTENT-EXT-R1, D-7).
 *
 *   cms:content-owner show {content}
 *   cms:content-owner assign {content} {owner} --fallback=core_render|hide_404|temporary_503 [--from=current-owner] --actor=<operator> [--reason=...]
 *   cms:content-owner release {content} --from=current-owner --actor=<operator> [--reason=...]
 *
 * --from must name the owner the operator expects (omit it for Core content),
 * so a concurrent change is refused rather than overwritten. --actor (user id
 * or email) names the operator, who must hold `content.authority.recover`;
 * the change is audited with that operator and the reason
 * (CORE-UNIFIED-OWNERSHIP-R1).
 */
class ContentOwnerCommand extends Command
{
    protected $signature = 'cms:content-owner
        {action : show|assign|release}
        {content : Content id}
        {owner? : Plugin slug (assign)}
        {--fallback= : core_render|hide_404|temporary_503 (required for assign)}
        {--from= : Expected current owner}
        {--actor= : Operator user id or email (assign/release)}
        {--reason= : Reason recorded in the ownership audit}';

    protected $description = 'Show, assign or release plugin ownership of existing content';

    public function handle(): int
    {
        $contentId = (int) $this->argument('content');
        $from = $this->option('from');
        $from = is_string($from) && $from !== '' ? $from : null;

        try {
            return match ((string) $this->argument('action')) {
                'show' => $this->show($contentId),
                'assign' => $this->assign($contentId, $from),
                'release' => $this->release($contentId, $from),
                default => $this->fail('Unknown action.'),
            };
        } catch (ContentOwnershipException $e) {
            $this->error('Refused: '.$e->reason);

            return self::FAILURE;
        }
    }

    private function show(int $contentId): int
    {
        $owned = app('cms.content_ownership')->ownershipOf($contentId);

        if ($owned === null) {
            $this->line('Core content (no owner).');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'owner=%s state=%s fallback=%s availability=%s',
            $owned->owner,
            app('cms.content_ownership')->ownerState($owned->owner)->value,
            $owned->fallback->value,
            $owned->availability->value,
        ));

        return self::SUCCESS;
    }

    private function assign(int $contentId, ?string $from): int
    {
        $owner = (string) $this->argument('owner');
        $fallback = ContentFallbackPolicy::tryFrom((string) $this->option('fallback'));

        if ($owner === '' || $fallback === null) {
            $this->error('assign requires an owner and an explicit --fallback=core_render|hide_404|temporary_503.');

            return self::FAILURE;
        }

        $operator = $this->operator();

        if ($operator === null) {
            return self::FAILURE;
        }

        app('cms.content_ownership_admin')->assign($contentId, $owner, $fallback, $from, $operator, $this->reason('operator assignment'));
        $this->info("Content {$contentId} is now owned by {$owner} (fallback {$fallback->value}).");

        return self::SUCCESS;
    }

    private function release(int $contentId, ?string $from): int
    {
        if ($from === null) {
            $this->error('release requires --from=<current owner>.');

            return self::FAILURE;
        }

        $operator = $this->operator();

        if ($operator === null) {
            return self::FAILURE;
        }

        app('cms.content_ownership_admin')->release($contentId, $from, $operator, $this->reason('operator release'));
        $this->info("Content {$contentId} returned to Core.");

        return self::SUCCESS;
    }

    private function operator(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $ref = trim((string) $this->option('actor'));
        $model = config('auth.providers.users.model', \App\Models\User::class);
        $user = $ref === '' ? null : $model::query()->where(ctype_digit($ref) ? 'id' : 'email', $ref)->first();

        if ($user === null) {
            $this->error('--actor=<user id or email> must name the operator (who needs content.authority.recover).');
        }

        return $user;
    }

    private function reason(string $default): string
    {
        $reason = trim((string) $this->option('reason'));

        return $reason !== '' ? $reason : $default;
    }
}
