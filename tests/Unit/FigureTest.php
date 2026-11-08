<?php

namespace Tests\Unit;

use App\Support\Figure;
use EdutrustPay\Contract\Money;
use Tests\TestCase;

/**
 * Figure and FigureAggregate.
 *
 * These enforce the rule the whole platform rests on — a missing figure and a
 * zero figure are different facts — at the point where a number becomes
 * something a bursary head reads. Everything above this is arithmetic;
 * everything below it is a decision someone makes about money.
 *
 * Extends the framework TestCase rather than PHPUnit's: FigureAggregate's
 * coverage statement goes through __() and trans_choice(), which need the
 * container and the translator booted.
 */
class FigureTest extends TestCase
{
    public function test_a_missing_figure_has_no_number_at_all(): void
    {
        foreach ([Figure::notCapable(), Figure::notReported(), Figure::notApplicable()] as $figure) {
            $this->assertTrue($figure->isMissing());
            $this->assertFalse($figure->hasValue());
            $this->assertNull($figure->money());

            // The critical one. There is no accessor that yields a number, so a
            // template cannot print "0" for something nobody reported.
            $this->assertNull($figure->formatted());
            $this->assertNotSame('0', $figure->formatted());
        }
    }

    public function test_a_genuine_zero_is_a_number_and_renders_as_one(): void
    {
        $figure = Figure::reported(Money::zero());

        $this->assertTrue($figure->hasValue());
        $this->assertFalse($figure->isMissing());
        $this->assertSame('0', $figure->formatted());
    }

    public function test_the_three_missing_states_say_different_things(): void
    {
        // "not yet reporting" will change; "not applicable" never will. A console
        // that rendered a pharmacy's enrolment as a gap that might close would
        // teach people to ignore the capability matrix.
        $this->assertSame("doesn't send this", Figure::notCapable()->label());
        $this->assertSame('not applicable', Figure::notApplicable()->label());
        $this->assertSame('no report', Figure::notReported()->label());
    }

    public function test_a_total_carries_its_own_coverage(): void
    {
        $aggregate = Figure::sum([
            'St Bede College' => Figure::reported(Money::of('4200000.00')),
            'Kumbo Hospital' => Figure::reported(Money::of('1800000.00')),
            'St Joseph Primary' => Figure::notCapable(),
        ]);

        $this->assertSame('6000000.00', $aggregate->total()->value());
        $this->assertSame(2, $aggregate->coveredCount());
        $this->assertSame(1, $aggregate->missingCount());
        $this->assertFalse($aggregate->isComplete());

        $this->assertSame(
            'Added up from 2 of 3 schools. 1 has no figure for this, so the total leaves it out.',
            $aggregate->coverageStatement()
        );
    }

    public function test_a_missing_institution_is_excluded_from_the_total_not_counted_as_zero(): void
    {
        $withMissing = Figure::sum([
            'A' => Figure::reported(Money::of('100.00')),
            'B' => Figure::notCapable(),
        ]);

        $withZero = Figure::sum([
            'A' => Figure::reported(Money::of('100.00')),
            'B' => Figure::reported(Money::zero()),
        ]);

        // The totals happen to match — that is exactly why the number alone is
        // not enough, and why coverage travels with it.
        $this->assertSame($withMissing->total()->value(), $withZero->total()->value());

        $this->assertFalse($withMissing->isComplete());
        $this->assertTrue($withZero->isComplete());
        $this->assertNotSame($withMissing->coverageStatement(), $withZero->coverageStatement());
    }

    public function test_a_complete_total_needs_no_qualification(): void
    {
        $aggregate = Figure::sum([
            'A' => Figure::reported(Money::of('100.00')),
            'B' => Figure::reported(Money::of('50.00')),
        ]);

        $this->assertTrue($aggregate->isComplete());
        $this->assertTrue($aggregate->isUnqualified());
        $this->assertSame('Added up from all 2 schools.', $aggregate->coverageStatement());
    }

    public function test_a_stale_figure_still_counts_but_flags_the_total(): void
    {
        $aggregate = Figure::sum([
            'A' => Figure::reported(Money::of('100.00')),
            'B' => Figure::stale(Money::of('50.00'), now()->subMonths(4)),
        ]);

        $this->assertSame('150.00', $aggregate->total()->value());
        $this->assertTrue($aggregate->isComplete());

        // Complete but not unqualified: nothing is missing, yet the number is
        // partly history and the console must say so.
        $this->assertTrue($aggregate->hasStale());
        $this->assertFalse($aggregate->isUnqualified());
    }

    public function test_an_empty_body_reports_no_coverage_rather_than_a_confident_zero(): void
    {
        $aggregate = Figure::sum([]);

        $this->assertSame('0.00', $aggregate->total()->value());
        $this->assertSame('No schools to add up.', $aggregate->coverageStatement());
    }

    public function test_missing_labels_name_the_institutions_left_out(): void
    {
        $aggregate = Figure::sum([
            'St Bede College' => Figure::reported(Money::of('1.00')),
            'Kumbo Hospital' => Figure::notCapable(),
            'St Joseph Primary' => Figure::notReported(),
        ]);

        $this->assertSame(
            ['Kumbo Hospital' => "doesn't send this", 'St Joseph Primary' => 'no report'],
            $aggregate->missingLabels()
        );
    }
}
