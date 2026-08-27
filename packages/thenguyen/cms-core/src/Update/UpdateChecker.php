<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Update discovery (CORE-UPGRADE-2, §discovery).
 *
 * Fetches the channel feed, parses the versioned metadata contract and compares
 * the latest release against the installed {@see \TheNguyen\CMS\Support\CmsInfo}
 * version. Discovery is read-only and total: any transport, parse or
 * compatibility problem resolves to a definite {@see UpdateAvailability} state
 * (UP_TO_DATE / UPDATE_AVAILABLE / INCOMPATIBLE / UNAVAILABLE) rather than an
 * exception — a broken feed must never break an admin page. Nothing is
 * downloaded here.
 */
final class UpdateChecker
{
    public function __construct(
        private UpdateClient $client,
        private UpdateCompatibilityChecker $compatibility = new UpdateCompatibilityChecker,
    ) {}

    public function check(string $feedUrl, UpdateChannel $channel, string $currentVersion): UpdateAvailability
    {
        $feedUrl = trim($feedUrl);
        if ($feedUrl === '') {
            return UpdateAvailability::unavailable($currentVersion, $channel->name, 'No update feed is configured.');
        }

        try {
            $body = $this->client->fetch($feedUrl);
            $manifest = UpdateManifest::fromJson($body, $channel);
        } catch (UpdateException $e) {
            return UpdateAvailability::unavailable($currentVersion, $channel->name, $e->getMessage());
        }

        if (! version_compare($manifest->version, $currentVersion, '>')) {
            return UpdateAvailability::upToDate($currentVersion, $channel->name, $manifest);
        }

        $compat = $this->compatibility->check($manifest, $currentVersion);
        if (! $compat['ok']) {
            return UpdateAvailability::incompatible($currentVersion, $channel->name, $manifest, $compat['detail']);
        }

        return UpdateAvailability::available($currentVersion, $channel->name, $manifest);
    }
}
