<?php

namespace App\Support;

use App\Models\PeriodReport;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Money;

/**
 * A school's fee position for the academic year, as at the end of one month,
 * read from the fees part of its report (contract 1.4).
 *
 * The four amounts arrive already added up; the class-by-class and fee-by-fee
 * views are added up here from the same rows, so every view agrees with the
 * totals to the franc. Owed and overpaid were worked out fee by fee at the
 * school, so a class's owed is never reduced by one student's overpayment.
 */
final class FeePosition
{
    /**
     * @param  array{paid: int, part_paid: int, not_paid: int, not_charged: int}  $students
     * @param  list<array{level: string, category: string, expected: Money, collected: Money, owed: Money, overpaid: Money}>  $rows
     */
    private function __construct(
        public readonly string $session,
        public readonly Money $expected,
        public readonly Money $collected,
        public readonly Money $owed,
        public readonly Money $overpaid,
        public readonly array $students,
        public readonly array $rows,
    ) {}

    public static function fromReport(?PeriodReport $report): ?self
    {
        if (! $report || ! $report->declares(Capability::FEES)) {
            return null;
        }

        $block = $report->decodedPayload()['operational']['fees'] ?? null;

        if (! is_array($block)) {
            return null;
        }

        $rows = array_map(fn (array $row) => [
            'level' => (string) $row['level'],
            'category' => (string) $row['category'],
            'expected' => Money::of((string) $row['expected']),
            'collected' => Money::of((string) $row['collected']),
            'owed' => Money::of((string) $row['owed']),
            'overpaid' => Money::of((string) $row['overpaid']),
        ], $block['rows'] ?? []);

        return new self(
            (string) $block['session'],
            Money::of((string) $block['expected']),
            Money::of((string) $block['collected']),
            Money::of((string) $block['owed']),
            Money::of((string) $block['overpaid']),
            [
                'paid' => (int) ($block['students']['paid'] ?? 0),
                'part_paid' => (int) ($block['students']['part_paid'] ?? 0),
                'not_paid' => (int) ($block['students']['not_paid'] ?? 0),
                'not_charged' => (int) ($block['students']['not_charged'] ?? 0),
            ],
            $rows,
        );
    }

    /** Collected as a whole-number share of expected; null when nothing is billed. */
    public function collectedShare(): ?int
    {
        return self::share($this->collected, $this->expected);
    }

    public static function share(Money $collected, Money $expected): ?int
    {
        $billed = (float) $expected->value();

        return $billed > 0 ? (int) round((float) $collected->value() / $billed * 100) : null;
    }

    public function studentTotal(): int
    {
        return array_sum($this->students);
    }

    /**
     * @return array<string, array{expected: Money, collected: Money, owed: Money, overpaid: Money}>  by class name
     */
    public function byClass(): array
    {
        return $this->groupBy('level');
    }

    /**
     * @return array<string, array{expected: Money, collected: Money, owed: Money, overpaid: Money}>  by fee name
     */
    public function byFee(): array
    {
        return $this->groupBy('category');
    }

    private function groupBy(string $key): array
    {
        $groups = [];

        foreach ($this->rows as $row) {
            $name = $row[$key];
            $groups[$name] ??= ['expected' => Money::zero(), 'collected' => Money::zero(), 'owed' => Money::zero(), 'overpaid' => Money::zero()];

            foreach (['expected', 'collected', 'owed', 'overpaid'] as $amount) {
                $groups[$name][$amount] = $groups[$name][$amount]->add($row[$amount]);
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    }
}
