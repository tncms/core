<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileValue;

/**
 * Public profile read API for content renderers (CORE-EXTENSION-TRUST S3,
 * binding `cms.public_profile`; contract: docs/core-extension-trust/S3-API-CONTRACT.md).
 *
 * The audience is ALWAYS public: there is no viewer parameter, and the result
 * never depends on the authenticated user, preview state or request input, so
 * an editor or admin rendering a page sees exactly what a guest sees and the
 * output is safe in any cacheable response. Custom fields are authorized by
 * {@see UserProfileManager} as a guest (no second authority).
 *
 * A subject is projected only while their Core consent flag `core.public_profile`
 * is exactly true; a missing user and a non-consenting user both return null.
 * Read-only and stateless: safe as a singleton under persistent workers.
 */
class PublicProfileProvider
{
    public const MAX_FIELDS = 20;

    public const ELIGIBILITY_FIELD = 'core.public_profile';

    public const MAX_TOKEN_BYTES = 160;

    public const MAX_BIO_CHARS = 5000;

    public const MAX_URL_BYTES = 2048;

    private const FIELD_PREFIX = 'field:';

    /**
     * @param  list<string>  $fields  tokens: name | avatar | bio | field:{key}
     *
     * @throws InvalidArgumentException on a malformed selection (subject-independent)
     */
    public function project(int|string $subject, array $fields): ?PublicProfileProjection
    {
        $selection = $this->selection($fields);
        $id = $this->subjectId($subject);

        if ($id === null) {
            return null;
        }

        try {
            if (! $this->consented($id)) {
                return null;
            }

            $user = User::query()->find($id);

            return $user === null ? null : $this->build($user, $selection);
        } catch (\Throwable $e) {
            Log::warning('Public profile projection failed', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * @param  array<mixed>  $fields
     * @return array{name: bool, avatar: bool, bio: bool, custom: list<string>}
     */
    private function selection(array $fields): array
    {
        if (count($fields) > self::MAX_FIELDS) {
            throw new InvalidArgumentException(sprintf('A public profile selection may hold at most %d fields.', self::MAX_FIELDS));
        }

        $out = ['name' => false, 'avatar' => false, 'bio' => false, 'custom' => []];

        foreach ($fields as $token) {
            if (! is_string($token)) {
                throw new InvalidArgumentException('Public profile field tokens must be strings.');
            }

            if (strlen($token) > self::MAX_TOKEN_BYTES) {
                throw new InvalidArgumentException(sprintf('Public profile field tokens may be at most %d bytes.', self::MAX_TOKEN_BYTES));
            }

            if (in_array($token, ['name', 'avatar', 'bio'], true)) {
                $out[$token] = true;
            } elseif (str_starts_with($token, self::FIELD_PREFIX) && strlen($token) > strlen(self::FIELD_PREFIX)) {
                $key = substr($token, strlen(self::FIELD_PREFIX));

                // The consent flag itself is excluded by ProfilePresenter (single point).
                if (! in_array($key, $out['custom'], true)) {
                    $out['custom'][] = $key;
                }
            }
            // Any other token is simply absent (no error, no existence signal).
        }

        return $out;
    }

    /** A positive user id from an int or a 1-18 digit ASCII string, else null. */
    private function subjectId(int|string $subject): ?int
    {
        if (is_int($subject)) {
            return $subject > 0 ? $subject : null;
        }

        if (preg_match('/^[0-9]{1,18}$/D', $subject) !== 1) {
            return null;
        }

        $id = (int) $subject;

        return $id > 0 ? $id : null;
    }

    /**
     * Narrow system read of the consent flag only (it is owner-visible, so a
     * guest read through the manager would always deny). The definition must be
     * the untampered Core field, the neutral-locale value must be exactly true,
     * and the subject must have written it themself: a value set on their behalf
     * (e.g. by a `profile_fields.edit_others` holder) is not consent.
     */
    private function consented(int $userId): bool
    {
        $field = UserProfileField::query()->where('key', self::ELIGIBILITY_FIELD)->first();

        if ($field === null
            || ! $field->is_active
            || $field->is_encrypted
            || $field->type !== 'boolean'
            || $field->source !== 'core') {
            return false;
        }

        $row = UserProfileValue::query()
            ->where('user_id', $userId)->where('field_id', $field->getKey())->where('locale', '')
            ->first(['value', 'updated_by']);

        return $row !== null && $row->value === true && $row->updated_by === $userId;
    }

    /** @param array{name: bool, avatar: bool, bio: bool, custom: list<string>} $selection */
    private function build(User $user, array $selection): PublicProfileProjection
    {
        $name = is_string($user->name) ? trim($user->name) : '';
        $name = $name === '' ? null : $name;

        $avatar = null;

        if ($selection['avatar'] && is_string($user->avatar) && $this->safeUrl($user->avatar)) {
            $avatar = ['url' => $user->avatar, 'alt' => $name ?? ''];
        }

        $bio = null;

        if ($selection['bio'] && is_string($user->bio) && trim($user->bio) !== '') {
            $bio = mb_substr($user->bio, 0, self::MAX_BIO_CHARS);
        }

        return new PublicProfileProjection(
            name: $selection['name'] ? $name : null,
            avatar: $avatar,
            bio: $bio,
            fields: $selection['custom'] === [] ? [] : $this->customFields($user, $selection['custom']),
        );
    }

    /**
     * Guest-authorized custom fields via the existing presenter (which already
     * drops encrypted and media fields), re-checking url hrefs strictly.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, type: string, label: string, display: string, href: ?string}>
     */
    private function customFields(User $user, array $keys): array
    {
        $present = app(ProfilePresenter::class)->present($user, null, $keys);
        $out = [];

        foreach ($present['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $href = is_string($field['href'] ?? null) && $this->safeUrl($field['href']) ? $field['href'] : null;

                $out[] = [
                    'key' => (string) $field['key'],
                    'type' => (string) $field['type'],
                    'label' => (string) $field['label'],
                    'display' => (string) $field['display'],
                    'href' => $field['type'] === 'url' ? $href : null,
                ];
            }
        }

        return $out;
    }

    /**
     * http(s) with an ASCII (punycode) or bracketed-IPv6 host, no userinfo, no
     * backslash, whitespace, control or non-ASCII characters, bounded.
     */
    private function safeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_BYTES || preg_match('/[^\x21-\x7E]|\\\\/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['https', 'http'], true)
            && preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*|\[[0-9A-Fa-f:.]+\])$/D', $host) === 1
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
