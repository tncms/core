<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TheNguyen\CMS\Exceptions\UnknownProfileFieldException;
use TheNguyen\CMS\Profile\AccountProfileFieldsPresenter;
use TheNguyen\CMS\Profile\UserProfileManager;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Owner-only update endpoint for the dynamic custom profile fields (P3).
 *
 * This is a SEPARATE namespaced endpoint from the base-profile update
 * (`cms.account.profile.update`): base User columns are never mixed with
 * arbitrary custom-field keys. The acting user is always the authenticated
 * owner (there is no target parameter — cross-user writes are impossible here),
 * and every write still passes through {@see UserProfileManager}, which
 * re-authorizes and validates each field server-side before any mutation.
 *
 * Payload contract:
 *   profile[<key>]        : submitted value (string, or array for checkbox)
 *   profile_present[]     : keys of the editable fields the form actually rendered
 * Only keys that are BOTH editable-for-the-owner AND rendered are considered;
 * unknown/forged/locked/admin-only/system/immutable/media keys are dropped here
 * and would also be refused by the manager. Encrypted fields: a blank input means
 * "unchanged" and plaintext is never echoed back into old input.
 */
class AccountProfileFieldsController
{
    public function __construct(
        private readonly UserProfileManager $manager,
        private readonly AccountProfileFieldsPresenter $presenter,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $defs = $this->presenter->editableDefinitions($user); // key => UserProfileField (editable, non-media)
        $present = (array) $request->input('profile_present', []);
        $submitted = (array) $request->input('profile', []);

        $writeSet = [];

        foreach ($present as $key) {
            if (! is_string($key)) {
                continue;
            }

            $field = $defs[$key] ?? null;

            if ($field === null) {
                continue; // not editable / not rendered / forged — dropped (manager would also refuse)
            }

            if ((bool) $field->is_encrypted) {
                $raw = $submitted[$key] ?? null;

                if (! is_string($raw) || trim($raw) === '') {
                    continue; // blank sensitive input ⇒ unchanged (no plaintext round-trip)
                }

                $writeSet[$key] = $raw;
            } elseif ($field->type === 'boolean') {
                $writeSet[$key] = array_key_exists($key, $submitted); // checked ⇒ true, unchecked/absent ⇒ false
            } elseif ($field->type === 'checkbox') {
                $writeSet[$key] = array_values((array) ($submitted[$key] ?? [])); // none ⇒ [] ⇒ clear
            } else {
                $writeSet[$key] = $submitted[$key] ?? ''; // empty ⇒ clear
            }
        }

        // Per-field validation for precise error mapping; drop any key that slips
        // the whitelist (defence in depth — the manager is the real gate).
        $valid = [];
        $errors = [];

        foreach ($writeSet as $key => $value) {
            try {
                $this->manager->validate($user, [$key => $value], $user);
                $valid[$key] = $value;
            } catch (ValidationException $e) {
                $errors['profile.'.$key] = $this->firstMessage($e);
            } catch (AuthorizationException|UnknownProfileFieldException) {
                // Not writable by this owner / unknown — silently skip; never 500.
            }
        }

        if ($errors !== []) {
            return redirect()
                ->route('cms.account.profile')
                ->withErrors($errors)
                ->withInput($this->safeOldInput($request, $defs));
        }

        // Atomic write of the pre-validated set (empty value clears the field).
        $this->manager->set($user, $valid, $user);

        return $this->redirect($request, __('Profile updated.'));
    }

    private function firstMessage(ValidationException $e): string
    {
        $all = $e->errors();
        $first = reset($all);

        return is_array($first) ? (string) ($first[0] ?? __('The value is invalid.')) : __('The value is invalid.');
    }

    /**
     * Old input for re-populating the form after an error, with encrypted field
     * values stripped so plaintext is never flashed to the session or echoed.
     *
     * @param  array<string, \TheNguyen\CMS\Models\UserProfileField>  $defs
     * @return array<string, mixed>
     */
    private function safeOldInput(Request $request, array $defs): array
    {
        $profile = (array) $request->input('profile', []);

        foreach ($defs as $key => $field) {
            if ((bool) $field->is_encrypted) {
                unset($profile[$key]);
            }
        }

        return array_merge($request->except(['profile', '_token']), ['profile' => $profile]);
    }

    private function redirect(Request $request, string $status): RedirectResponse
    {
        $target = apply_filters(
            'cms.account.redirect_after_update',
            route('cms.account.profile'),
            HookContext::make(['request' => $request, 'section' => 'profile_fields']),
        );

        return redirect()->to($target)->with('status', $status);
    }
}
