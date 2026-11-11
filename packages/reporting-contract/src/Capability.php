<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

/**
 * What an institution can currently report.
 *
 * This is the accommodation that makes the whole platform workable, and it is
 * not a nicety. The three systems feeding EdutrustPay are at very different
 * levels of completeness: edutrustshsm is mid-build and gains modules monthly,
 * and posinnova's double-entry ledger has never been written to at all — its
 * accounting_accounts_transactions table is empty, and its GL codes are unset.
 *
 * Had the contract assumed a ledger everywhere, posinnova would have contributed
 * a confident, perfectly balanced ZERO to the consolidated position, and nobody
 * would have noticed. That is precisely the failure the brief forbids: a
 * beautiful consolidated statement faithfully reporting a consistent lie.
 *
 * So: a capability absent from the declaration means CANNOT REPORT. A figure
 * present with value 0 means GENUINELY ZERO. These are different facts and the
 * contract never lets them collapse into each other.
 *
 * Each capability owns a set of payload paths. PayloadBuilder enforces the
 * coupling in both directions — you cannot supply receivables without declaring
 * the capability, and you cannot declare it without supplying them.
 */
final class Capability
{
    /** Income and expenditure by OHADA group, plus cash position and control totals. */
    public const LEDGER = 'ledger';

    /** Debtors, with ageing buckets. */
    public const RECEIVABLES = 'receivables';

    /** Creditors, with ageing buckets. */
    public const PAYABLES = 'payables';

    /** Budget versus actual. */
    public const BUDGET = 'budget';

    /** Student counts and expected fees. Not applicable to a business institution. */
    public const ENROLMENT = 'enrolment';

    /** Staff cost and statutory liabilities. */
    public const PAYROLL = 'payroll';

    /** Ledger hash, reversal and backdating counts — the tamper-evidence block. */
    public const INTEGRITY = 'integrity';

    /** What is actually being charged, per class and fee. Since contract 1.1. */
    public const FEES_CHARGED = 'fees_charged';

    /** Reconciliation against a bank or mobile-money statement. */
    public const BANK_RECON = 'bank_recon';

    /**
     * How many students the institution has in the session the month falls in,
     * class by class. No student is named. Since contract 1.3. Separate from
     * enrolment, which also carries fees billed and collected: an institution
     * can count its students long before its fee records can be trusted.
     */
    public const STUDENTS = 'students';

    /**
     * How many staff, by department and post, with the staff list itself: number,
     * name, department and post. Never pay. Since contract 1.3.
     */
    public const STAFF = 'staff';

    /**
     * The fee position for the academic year the month falls in, as at the end
     * of the month: expected, collected, still owed and overpaid, how many
     * students are fully paid, part paid, not paid and never charged, and the
     * same amounts class by class and fee by fee. No student is named. Since
     * contract 1.4. Separate from enrolment, whose billed and collected run
     * from the day the school started using its system rather than for a year.
     */
    public const FEES = 'fees';

    /**
     * Every capability, in the order the console's capability matrix shows them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LEDGER,
            self::RECEIVABLES,
            self::PAYABLES,
            self::BUDGET,
            self::ENROLMENT,
            self::PAYROLL,
            self::INTEGRITY,
            self::BANK_RECON,
            self::FEES_CHARGED,
            self::STUDENTS,
            self::STAFF,
            self::FEES,
        ];
    }

    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }

    /**
     * The payload blocks each capability owns.
     *
     * A block is a top-level-dotted path into the payload. If the capability is
     * declared, every one of its blocks must be present; if it is not declared,
     * none of them may be.
     *
     * @return array<string, list<string>>
     */
    public static function blocks(): array
    {
        return [
            self::LEDGER => [
                'financial.income_by_group',
                'financial.expenditure_by_group',
                'financial.surplus_deficit',
                'financial.cash',
                'financial.control',
            ],
            self::RECEIVABLES => ['financial.receivables'],
            self::PAYABLES => ['financial.payables'],
            self::BUDGET => ['financial.budget'],
            self::PAYROLL => ['financial.payroll'],
            self::BANK_RECON => ['financial.bank_recon'],
            self::ENROLMENT => ['operational.enrolment'],
            self::FEES_CHARGED => ['operational.fees_charged'],
            self::STUDENTS => ['operational.students'],
            self::STAFF => ['operational.staff'],
            self::FEES => ['operational.fees'],
            self::INTEGRITY => ['integrity'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function blocksFor(string $capability): array
    {
        return self::blocks()[$capability] ?? [];
    }

    /**
     * Capabilities that cannot apply to a given institution type.
     *
     * A hospital, pharmacy or shop has no enrolment; asking a pharmacy for
     * student counts is a category error, and the console should render that
     * cell as "not applicable" rather than "not yet reporting" — the difference
     * between a gap that will close and one that never will.
     *
     * @return list<string>
     */
    public static function notApplicableTo(string $institutionType): array
    {
        return in_array($institutionType, self::WITHOUT_PUPILS, true) ? [self::ENROLMENT, self::FEES_CHARGED, self::STUDENTS, self::FEES] : [];
    }

    /** Institution types that have no pupils, so no enrolment and no school fees. */
    public const WITHOUT_PUPILS = ['health', 'business'];

    /**
     * @return list<string>
     */
    public static function applicableTo(string $institutionType): array
    {
        $excluded = self::notApplicableTo($institutionType);

        return array_values(array_filter(
            self::all(),
            static fn (string $c): bool => ! in_array($c, $excluded, true)
        ));
    }
}
