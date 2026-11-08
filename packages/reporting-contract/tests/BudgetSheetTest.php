<?php

declare(strict_types=1);

namespace EdutrustPay\Contract\Tests;

use EdutrustPay\Contract\BudgetSheet;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Money;
use PHPUnit\Framework\TestCase;

final class BudgetSheetTest extends TestCase
{
    public function test_a_built_sheet_is_valid_and_totals_lines_but_not_headings(): void
    {
        $sheet = $this->sheet();

        $this->assertSame([], BudgetSheet::errors($sheet));
        $this->assertSame('1000000.00', $sheet['totals']['expenditure']['budget'], 'The heading is not counted twice.');
        $this->assertSame('250000.00', $sheet['totals']['expenditure']['to_date']);
    }

    public function test_it_survives_being_sent_and_keeps_the_schools_order(): void
    {
        $decoded = Canonical::decode(Canonical::encode($this->sheet()));

        $this->assertSame([], BudgetSheet::errors($decoded));
        $this->assertSame(['Running costs', 'Fuel', 'Stationery', 'Tuition'], array_column($decoded['lines'], 'name'));
    }

    public function test_totals_that_disagree_are_refused(): void
    {
        $sheet = $this->sheet();
        $sheet['totals']['expenditure']['budget'] = '1.00';

        $this->assertSame(['totals do not agree with the lines.'], BudgetSheet::errors($sheet));
    }

    public function test_only_a_change_in_the_sheet_counts(): void
    {
        $filed = Canonical::encode($this->sheet());

        $later = $this->sheet();
        $later['generated_at'] = '2027-01-01T00:00:00Z';
        $this->assertFalse(BudgetSheet::changed($filed, $later));

        $later['lines'][1]['to_date'] = '1.00';
        $this->assertTrue(BudgetSheet::changed($filed, $later));
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(): array
    {
        $m = fn (string $v) => Money::of($v);

        return BudgetSheet::build('ref-1', '2026-10', 1, '2026-11-01T00:00:00Z', 'XAF',
            ['title' => '2026/2027', 'starts' => '2026-09-01', 'ends' => '2027-08-31'],
            [
                ['code' => '6', 'name' => 'Running costs', 'section' => 'expenditure', 'heading' => true, 'budget' => $m('1000000.00'), 'month' => $m('50000.00'), 'to_date' => $m('250000.00')],
                ['code' => '6.1', 'name' => 'Fuel', 'section' => 'expenditure', 'heading' => false, 'budget' => $m('600000.00'), 'month' => $m('50000.00'), 'to_date' => $m('200000.00')],
                ['code' => '6.2', 'name' => 'Stationery', 'section' => 'expenditure', 'heading' => false, 'budget' => $m('400000.00'), 'month' => $m('0.00'), 'to_date' => $m('50000.00')],
                ['code' => null, 'name' => 'Tuition', 'section' => 'income', 'heading' => false, 'budget' => $m('5000000.00'), 'month' => $m('900000.00'), 'to_date' => $m('2000000.00')],
            ]
        );
    }
}
