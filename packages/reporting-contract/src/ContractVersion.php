<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

/**
 * The contract version.
 *
 * Four independently-deployed codebases implement this contract: the EdutrustPay
 * console (Laravel 12), paxhitestbed (Laravel 10), edutrustshsm (Laravel 12) and
 * posinnova (Laravel 9). They upgrade on different days. Every payload therefore
 * carries the version it was built against, the ingest API returns the version it
 * currently speaks, and each institution records the version it is expected to
 * send — so a rollout can be staged rather than flag-day.
 */
final class ContractVersion
{
    /** The version this package implements. */
    public const CURRENT = '1.3';

    /** Versions this package can still read. */
    public const SUPPORTED = ['1.0', '1.1', '1.2', '1.3'];

    /** The version that added financial.group_names. */
    public const GROUP_NAMES_FROM = '1.2';

    /** Students per class, and the staff part, arrived together. */
    public const STUDENTS_AND_STAFF_FROM = '1.3';

    public static function supports(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }
}
