<?php

namespace App\Support;

use EdutrustPay\Contract\Capability;

/**
 * The parts of a monthly report, in the words the console uses for them.
 *
 * The contract names them for machines (ledger, receivables, bank_recon). These
 * are the names "What schools send" and a school's setup page both show, kept
 * here so the two pages cannot call the same thing by different names.
 */
final class CapabilityText
{
    /**
     * @return array<string, array{0: string, 1: string}> name and one-line description, by capability
     */
    public static function names(): array
    {
        return [
            Capability::LEDGER => [__('Income and spending'), __('Money in and out, by account class, with cash at the start and end of the month.')],
            Capability::RECEIVABLES => [__('Owed to it'), __('Mostly unpaid fees, by how long they have been owed.')],
            Capability::PAYABLES => [__('What it owes'), __('To suppliers and staff, by how long.')],
            Capability::ENROLMENT => [__('Students and fees collected'), __('Student numbers, fees billed and collected, and how much came in as cash.')],
            Capability::FEES_CHARGED => [__('Fees charged'), __('Each fee per class. Your fee rules are checked against this.')],
            Capability::PAYROLL => [__('Staff pay'), __('What went through payroll, and what is owed for tax and social insurance.')],
            Capability::BUDGET => [__('Budget total'), __('A single budget figure in the report. Most schools send the budget sheet instead.')],
            Capability::BANK_RECON => [__('Bank statement check'), __('The books against the bank\'s own statement.')],
            Capability::INTEGRITY => [__('How the books were kept'), __('Counts of entries, late entries, reversals and unfinished entries.')],
            Capability::STUDENTS => [__('Students per class'), __('How many students it has in the session, class by class. No student is named.')],
            Capability::STAFF => [__('Staff'), __('How many staff, by department and post, with the staff list: number, name, department and post. Never pay.')],
            Capability::FEES => [__('Fees for the year'), __('For the school year: what is expected, collected and still owed, class by class and fee by fee, and how many students have paid. No student is named.')],
        ];
    }

    public static function name(string $capability): string
    {
        return self::names()[$capability][0] ?? ucfirst(str_replace('_', ' ', $capability));
    }

    public static function description(string $capability): ?string
    {
        return self::names()[$capability][1] ?? null;
    }
}
