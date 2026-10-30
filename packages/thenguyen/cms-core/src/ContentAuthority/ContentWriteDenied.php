<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * A content write refused by authority: an owner guard, the RBAC/ownership/
 * publication rules, a missing owner, an unsanctioned model write or a missing
 * expected revision.
 */
final class ContentWriteDenied extends ContentWriteException {}
