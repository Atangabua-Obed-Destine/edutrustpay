<?php

namespace App\Support;

use EdutrustPay\Contract\Money;

/**
 * A consolidated total, and an honest account of what it covers.
 *
 * "Consolidated across 7 of 9 institutions. 2 not reporting."
 *
 * The coverage statement is not a caption someone remembered to add next to the
 * number — it is produced by the same act that produced the number, and cannot
 * be obtained separately. A total that quietly omitted two institutions while
 * looking complete is the failure mode this whole platform exists to prevent
 * (brief §3: "a beautiful consolidated statement that faithfully reports a
 * consistent lie is a failure").
 */
final class FigureAggregate
{
    private Money $total;

    /** @var array<int, string> */
    private array $covered;

    /** @var array<string, string> label => reason */
    private array $missing;

    private bool $anyStale;

    /**
     * @param  array<int, string>  $covered
     * @param  array<string, string>  $missing
     */
    private function __construct(Money $total, array $covered, array $missing, bool $anyStale)
    {
        $this->total = $total;
        $this->covered = $covered;
        $this->missing = $missing;
        $this->anyStale = $anyStale;
    }

    /**
     * @param  array<string, Figure>  $figures  keyed by a label for each source
     */
    public static function of(array $figures): self
    {
        $amounts = [];
        $covered = [];
        $missing = [];
        $anyStale = false;

        foreach ($figures as $label => $figure) {
            $label = (string) $label;

            if ($figure->hasValue()) {
                $amounts[] = $figure->money();
                $covered[] = $label;

                if ($figure->isStale()) {
                    $anyStale = true;
                }

                continue;
            }

            // Not summed. Recorded, so the number can never quietly pretend
            // these were zero.
            $missing[$label] = $figure->label();
        }

        return new self(Money::sum($amounts), $covered, $missing, $anyStale);
    }

    public function total(): Money
    {
        return $this->total;
    }

    /**
     * The total for display, or the fact that there is no total.
     *
     * With nothing reported the arithmetic still yields 0, and printing it put
     * "0 XAF" on the dashboard for a month no institution had filed: the exact
     * confusion of missing with zero this class exists to prevent. Handled here
     * rather than in each template so a new card cannot get it wrong.
     */
    public function formatted(): string
    {
        if ($this->covered === []) {
            return __('no figures yet');
        }

        return number_format((float) $this->total->value(), 0, '.', ' ');
    }

    public function hasFigures(): bool
    {
        return $this->covered !== [];
    }

    public function coveredCount(): int
    {
        return count($this->covered);
    }

    public function missingCount(): int
    {
        return count($this->missing);
    }

    public function total_sources(): int
    {
        return $this->coveredCount() + $this->missingCount();
    }

    /** @return array<int, string> */
    public function coveredLabels(): array
    {
        return $this->covered;
    }

    /** @return array<string, string> */
    public function missingLabels(): array
    {
        return $this->missing;
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    public function hasStale(): bool
    {
        return $this->anyStale;
    }

    /**
     * Is this total safe to present as a headline figure without qualification?
     *
     * Only when nothing is missing and nothing is stale. Anywhere this returns
     * false, the console must show the coverage statement beside the number.
     */
    public function isUnqualified(): bool
    {
        return $this->isComplete() && ! $this->anyStale;
    }

    /**
     * The sentence that goes next to the number.
     */
    public function coverageStatement(): string
    {
        $total = $this->total_sources();

        if ($total === 0) {
            return __('No schools to add up.');
        }

        if ($this->isComplete()) {
            return trans_choice(
                '{1}From the one school.|[2,*]Added up from all :count schools.',
                $total,
                ['count' => $total]
            );
        }

        // Named, so the reader knows whose figure the total lacks and why.
        $left = collect($this->missing)->map(fn ($why, $name) => $name.' ('.mb_strtolower((string) $why).')')->implode(', ');

        return __('Added up from :covered of :total schools. Left out: :left.', [
            'covered' => $this->coveredCount(),
            'total' => $total,
            'left' => $left,
        ]);
    }
}
