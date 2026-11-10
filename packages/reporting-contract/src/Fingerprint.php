<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

/**
 * Whether a month's books have changed since it was last filed.
 *
 * Comparing raw bytes cannot answer that: generated_at differs on every build.
 * Ageing, fees charged and the audit count are also left out on purpose. They are
 * snapshots of the system today, so they move whenever a student pays or someone
 * signs in, and including them would refile every old month daily. The contract
 * version and capability list only say how the report was written, not what it says.
 *
 * What remains is what the month itself contains: its status, its ledger totals
 * and hash, payroll and operational figures.
 */
final class Fingerprint
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(array $payload): string
    {
        unset(
            $payload['generated_at'],
            $payload['sequence'],
            $payload['contract_version'],
            $payload['capabilities'],
            $payload['operational']['fees_charged'],
            // Who is on the staff and how many students each class holds are read
            // from the institution's records as they stand today. Counting them would
            // refile every closed month the day either first appears, and again
            // whenever someone corrects a post, and each refile of a closed month
            // raises a finding.
            $payload['operational']['students'],
            $payload['operational']['staff'],
            // A school naming or renaming its account groups is not a change to
            // its figures. Counting it would refile every closed month the day
            // the names first appear, and each refile of a closed month raises
            // a finding.
            $payload['financial']['group_names'],
            $payload['financial']['receivables'],
            $payload['financial']['payables'],
            $payload['integrity']['audit_events']
        );

        return hash('sha256', Canonical::encode($payload));
    }

    public static function ofJson(string $json): string
    {
        return self::of(Canonical::decode($json));
    }

    /**
     * Should this month be filed again?
     *
     * Yes if its books changed. Also yes if it is still open and the fees it
     * charges changed, because the body compares the latest fees with its rules
     * and would otherwise judge a school on what it used to charge. A closed month
     * is not reopened for that: its books did not change, and the next month's
     * report carries the new fees anyway.
     *
     * The school's names for its account groups follow the same rule: an open
     * month carries a new name across, a closed month is left alone.
     *
     * @param  string  $filedJson  the report as it was last delivered
     * @param  array<string, mixed>  $payload  the report as it stands now
     */
    public static function needsRefiling(string $filedJson, array $payload): bool
    {
        $filed = Canonical::decode($filedJson);
        $current = Canonical::decode(Canonical::encode($payload));

        if (self::of($filed) !== self::of($current)) {
            return true;
        }

        if (($filed['status'] ?? null) === 'final') {
            return false;
        }

        foreach ([['operational', 'fees_charged'], ['financial', 'group_names'], ['operational', 'students'], ['operational', 'staff']] as [$block, $key]) {
            if (json_encode($filed[$block][$key] ?? null) !== json_encode($current[$block][$key] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
