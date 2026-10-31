<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use LogicException;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;

/**
 * Optional context of a content action decision (CORE-CONTENT-ACTION-AUTHORIZATION-R1,
 * extended by CORE-CONTENT-AUTHORIZATION-R2). Immutable; every option returns a copy.
 *
 * - locale: the content locale the action targets. The code is trimmed and
 *   lower-cased like the editor's `?locale=`; it is validated by the decision
 *   (an invalid or inactive locale is refused, never replaced by the default).
 * - privateDraft(): the caller only prepares data that stays private until a
 *   separately authorized publish. Valid for `edit` / `edit_translation` only.
 *   It lifts exactly one rule ("published content needs publish") of the
 *   decision; it is never write authority (ContentWriteGate does not read it).
 * - asOwningExtension(): the calling plugin asks as the owner of extension-managed
 *   content. The claim records the calling plugin file (never a declared slug);
 *   the decision re-derives the owner from that file and compares it with the
 *   persisted `cms_contents.managed_by`.
 */
final class ContentActionContext
{
    private function __construct(
        public readonly ?string $locale,
        public readonly bool $privateDraft = false,
        private readonly ?string $ownerClaimFile = null,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }

    public static function forLocale(string $locale): self
    {
        return new self(strtolower(trim($locale)));
    }

    public function privateDraft(): self
    {
        return new self($this->locale, true, $this->ownerClaimFile);
    }

    /**
     * Ask as the owning extension. Only code under the plugins root can make a
     * claim that verifies; from Core, host, theme or test code the claim is empty
     * and managed content stays refused.
     */
    public function asOwningExtension(): self
    {
        return new self($this->locale, $this->privateDraft, self::callingPluginFile());
    }

    /**
     * @internal The plugin file that made the owner claim (null: no claim, or not
     *           made by plugin code); read only by {@see ContentActionAuthority}.
     */
    public function ownerClaimFile(): ?string
    {
        return $this->ownerClaimFile;
    }

    /** A context is never rebuilt from data: that would bypass the call-stack derived claim. */
    public function __serialize(): array
    {
        throw new LogicException('A content action context cannot be serialized.');
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A content action context cannot be unserialized.');
    }

    /** The nearest calling frame under the plugins root (the same rule as ContentWriteContext). */
    private static function callingPluginFile(): ?string
    {
        /** @var ExtensionIdentityResolver $identity */
        $identity = app(ExtensionIdentityResolver::class);

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && $identity->isUnderPluginsRoot($file)) {
                return $file;
            }
        }

        return null;
    }
}
