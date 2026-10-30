<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Concerns;

use Closure;
use Filament\Actions\DeleteAction;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Locked;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\ContentAuthority\ManagedContentEditUrl;
use TheNguyen\CMS\Models\Content;

/**
 * Post/Page create + edit pages write through the Core content authority
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 *  - The revision the form was filled from is captured server-side (#[Locked],
 *    so the browser cannot change it) and sent as the expected revision: a save
 *    against content that changed meanwhile is a conflict, never an overwrite.
 *  - Extension-managed content never opens in the native editor: it redirects
 *    to the extension's same-site editor URL, or is refused when there is none.
 *  - A refusal (authority, guard, conflict) is shown as a notification and the
 *    save halts — nothing is reported as saved.
 */
trait WritesContentThroughAuthority
{
    #[Locked]
    public ?int $contentRevision = null;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        $record = $this->getRecord();

        if ($record instanceof Content && $record->managed_by !== null) {
            $url = ManagedContentEditUrl::for($record);

            abort_if($url === null, 403, tn_trans('This content is managed by an extension and cannot be edited here.'));

            // Stops the page before anything runs. A plain RedirectResponse: inside
            // a Livewire request the redirect() helper returns Livewire's Redirector.
            throw new HttpResponseException(new RedirectResponse($url));
        }
    }

    protected function captureContentRevision(Content $record): void
    {
        $this->contentRevision = (int) $record->revision;
    }

    /**
     * Run a ContentManager write; on refusal notify and halt the page action.
     *
     * @param  Closure(): Model  $write
     */
    protected function writeThroughContentAuthority(Closure $write): Model
    {
        $resource = static::getResource();

        try {
            $record = $write();
        } catch (ContentWriteException $e) {
            $resource::notifyContentWriteFailure($e);

            throw new Halt;
        }

        if ($record instanceof Content) {
            $this->captureContentRevision($record);
        }

        return $record;
    }

    protected function contentDeleteHeaderAction(): DeleteAction
    {
        return DeleteAction::make()
            ->using(fn (Content $record): bool => static::getResource()::deleteContentThroughService($record, $this->contentRevision));
    }
}
