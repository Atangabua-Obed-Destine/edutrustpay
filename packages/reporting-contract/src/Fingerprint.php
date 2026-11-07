<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

/**
 * Whether a month's books have changed since it was last filed.
 *
 * Comparing raw bytes cannot answer that: generated_at differs on every build.
 * Ageing and the audit count are also left out on purpose. They are computed
 * from the state of the system today, so they move whenever a student pays or
 * someone signs in, and including them would refile every old month daily.
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
}
