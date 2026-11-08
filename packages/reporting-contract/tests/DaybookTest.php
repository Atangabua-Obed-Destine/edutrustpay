<?php

declare(strict_types=1);

namespace EdutrustPay\Contract\Tests;

use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Daybook;
use EdutrustPay\Contract\Money;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class DaybookTest extends TestCase
{
    public function test_a_built_daybook_is_valid_and_totals_itself(): void
    {
        $document = $this->document();

        $this->assertSame([], Daybook::errors($document));
        $this->assertSame('150000.00', $document['money_in']);
        $this->assertSame('40000.00', $document['money_out']);
    }

    public function test_it_survives_being_sent(): void
    {
        $decoded = Canonical::decode(Canonical::encode($this->document()));

        $this->assertSame([], Daybook::errors($decoded));
        $this->assertNull($decoded['lines'][0]['party']);
        $this->assertSame('150000.00', $decoded['lines'][1]['amount']);
    }

    public function test_it_satisfies_the_published_schema(): void
    {
        $schema = json_decode((string) file_get_contents(__DIR__.'/../schema/daybook.v1.json'));
        $document = json_decode(Canonical::encode($this->document()));

        $this->assertTrue((new JsonSchemaValidator())->validate($document, $schema)->isValid());

        $document->lines[0]->extra = 'anything';

        $this->assertFalse((new JsonSchemaValidator())->validate($document, $schema)->isValid());
    }

    public function test_the_same_month_always_serialises_the_same_way(): void
    {
        $lines = $this->lines();

        $this->assertSame(
            Canonical::encode($this->document($lines)),
            Canonical::encode($this->document(array_reverse($lines)))
        );
    }

    public function test_a_month_with_no_movement_is_a_real_answer(): void
    {
        $document = $this->document([]);

        $this->assertSame([], Daybook::errors($document));
        $this->assertSame('0.00', $document['money_in']);
    }

    public function test_a_line_dated_outside_the_month_is_refused(): void
    {
        $document = $this->document();
        $document['lines'][0]['date'] = '2026-11-01';

        $this->assertStringContainsString('outside 2026-10', Daybook::errors($document)[0]);
    }

    public function test_totals_that_disagree_with_the_lines_are_refused(): void
    {
        $document = $this->document();
        $document['money_in'] = '1.00';

        $this->assertStringContainsString('do not agree', Daybook::errors($document)[0]);
    }

    public function test_only_a_change_in_the_lines_counts_as_a_change(): void
    {
        $filed = Canonical::encode($this->document());

        $later = $this->document();
        $later['generated_at'] = '2026-12-25T00:00:00Z';
        $this->assertFalse(Daybook::changed($filed, $later));

        $later['lines'][0]['description'] = 'Reworded';
        $this->assertTrue(Daybook::changed($filed, $later));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        return [
            ['date' => '2026-10-02', 'ref' => 'R-1', 'description' => 'Bih — Tuition', 'party' => 'Bih', 'heading' => 'Tuition', 'kind' => 'fee', 'direction' => 'in', 'amount' => Money::of('150000.00'), 'method' => 'cash', 'source_id' => 'fee:1'],
            ['date' => '2026-10-01', 'ref' => 'E-1', 'description' => 'Chalk', 'party' => ' ', 'heading' => 'Supplies', 'kind' => 'expense', 'direction' => 'out', 'amount' => Money::of('40000.00'), 'source_id' => 'expense:1'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    private function document(?array $lines = null): array
    {
        return Daybook::build('ref-1', '2026-10', 1, '2026-11-01T00:00:00Z', 'XAF', $lines ?? $this->lines());
    }
}
