<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Upgrade\UpgradeManager;

/**
 * Remote update facade (`cms.update`) (CORE-UPGRADE-2).
 *
 * The single application-facing entry point that composes the update layer from
 * operator configuration (feed URL, channel, enabled flag) and the version
 * authority ({@see CmsInfo::VERSION}). It exposes:
 *
 *   - discovery ({@see check()}) — read-only, safe for an admin page;
 *   - preparation ({@see prepare()}) — secure download + verification, producing
 *     the single {@see VerifiedUpdatePackage}; and
 *   - handoff ({@see handOff()}) — entry into the CORE-UPGRADE-1 engine.
 *
 * The admin surface calls only {@see check()}. `prepare()`/`handOff()` are
 * deliberately NOT wired to any route in this phase — there is no one-click
 * upgrade execution (§forbidden-scope). Configuration is read through injectable
 * resolvers so the service is unit-testable without the framework.
 */
final class UpdateService
{
    private UpdateClient $client;

    private UpdateChecker $checker;

    private UpdatePackageDownloader $downloader;

    private UpdateVerifier $verifier;

    private UpdateStateStore $state;

    /** @var callable():?string */
    private $feedUrlResolver;

    /** @var callable():?string */
    private $channelResolver;

    /** @var callable():bool */
    private $enabledResolver;

    public function __construct(
        ?UpdateClient $client = null,
        ?UpdateStateStore $state = null,
        ?callable $feedUrlResolver = null,
        ?callable $channelResolver = null,
        ?callable $enabledResolver = null,
    ) {
        $this->client = $client ?? new UpdateClient;
        $this->state = $state ?? new UpdateStateStore;
        $this->checker = new UpdateChecker($this->client);
        $this->downloader = new UpdatePackageDownloader($this->client);
        $this->verifier = new UpdateVerifier;

        $this->feedUrlResolver = $feedUrlResolver ?? static fn (): ?string => self::config('cms.update.feed_url');
        $this->channelResolver = $channelResolver ?? static fn (): ?string => self::config('cms.update.channel');
        $this->enabledResolver = $enabledResolver ?? static fn (): bool => (bool) self::config('cms.update.enabled', false);
    }

    public function currentVersion(): string
    {
        return CmsInfo::VERSION;
    }

    public function channel(): UpdateChannel
    {
        return UpdateChannel::fromConfig(($this->channelResolver)());
    }

    public function feedUrl(): string
    {
        return trim((string) ($this->feedUrlResolver)());
    }

    public function isEnabled(): bool
    {
        return ($this->enabledResolver)();
    }

    /** @return array<string,mixed> the last persisted states (check/download/verification). */
    public function lastState(): array
    {
        return $this->state->all();
    }

    /** Read-only discovery. Persists the outcome. Never throws. */
    public function check(): UpdateAvailability
    {
        $current = $this->currentVersion();
        $channel = $this->channel();

        if (! $this->isEnabled()) {
            $a = UpdateAvailability::unavailable($current, $channel->name, 'Remote update checking is disabled.');
            $this->state->recordCheck($a);

            return $a;
        }

        $a = $this->checker->check($this->feedUrl(), $channel, $current);
        $this->state->recordCheck($a);

        return $a;
    }

    /**
     * Securely download and fully verify the release described by $manifest.
     * Produces the verified handoff object. Throws {@see UpdateException} on any
     * download/verification failure (no partial result).
     */
    public function prepare(UpdateManifest $manifest): VerifiedUpdatePackage
    {
        $dl = $this->downloader->download($manifest);
        $this->state->recordDownload(['bytes' => $dl['bytes'], 'target_version' => $manifest->version]);

        try {
            $verified = $this->verifier->verify($dl['path'], $manifest, $this->currentVersion());
        } catch (UpdateException $e) {
            // A package that fails verification is untrusted — remove it.
            $this->downloader->cleanup($dl['id']);
            throw $e;
        }

        $this->state->recordVerification($verified->summary());

        return $verified;
    }

    /**
     * Hand a verified package to the CORE-UPGRADE-1 engine. Requires the live
     * `cms.upgrade` authority.
     *
     * @return array{id:string, verify:array<string,mixed>}
     */
    public function handOff(VerifiedUpdatePackage $package, ?UpgradeManager $engine = null): array
    {
        $engine ??= app('cms.upgrade');

        return (new UpdateHandoff($engine))->toEngine($package);
    }

    private static function config(string $key, mixed $default = null): mixed
    {
        return \function_exists('config') ? config($key, $default) : $default;
    }
}
