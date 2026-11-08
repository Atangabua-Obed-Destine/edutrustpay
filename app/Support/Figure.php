<?php

namespace App\Support;

use Carbon\CarbonInterface;
use EdutrustPay\Contract\Money;

/**
 * A single figure, together with WHY it is what it is.
 *
 * The rule this class exists to enforce: a missing figure and a zero figure are
 * different facts and must never be conflated (brief §4.6). Everywhere else in
 * this codebase that rule is a comment; here it is the type.
 *
 * A figure is in exactly one of four states, and only one of them has a number:
 *
 *   reported      the institution can report this and did — the value stands
 *   stale         it reported, but long enough ago that the figure is history
 *   notReported   it CAN report this, but this period has not arrived
 *   notCapable    it cannot report this at all (mid-build, or not applicable)
 *
 * The last two both render as text, never as 0. There is no accessor that
 * returns a number for them and no default to fall back on, so a Blade template
 * cannot accidentally print "0" for a figure nobody ever sent. Getting this
 * wrong is not a display bug — it converts every gap in the group's reporting
 * into a confident, wrong consolidated total.
 */
final class Figure
{
    public const REPORTED = 'reported';

    public const STALE = 'stale';

    public const NOT_REPORTED = 'not_reported';

    public const NOT_CAPABLE = 'not_capable';

    public const NOT_APPLICABLE = 'not_applicable';

    private string $state;

    private ?Money $value;

    private ?CarbonInterface $asOf;

    private ?string $note;

    private function __construct(string $state, ?Money $value, ?CarbonInterface $asOf = null, ?string $note = null)
    {
        $this->state = $state;
        $this->value = $value;
        $this->asOf = $asOf;
        $this->note = $note;
    }

    /**
     * A figure that was reported and stands.
     *
     * `asOf` is not optional decoration: every figure carries the time it was
     * true (brief §4.6), because a consolidated position assembled from reports
     * of different ages is only honest if it says so.
     */
    public static function reported(Money $value, ?CarbonInterface $asOf = null): self
    {
        return new self(self::REPORTED, $value, $asOf);
    }

    /**
     * Reported, but old enough that it should be read as history.
     */
    public static function stale(Money $value, CarbonInterface $asOf): self
    {
        return new self(self::STALE, $value, $asOf);
    }

    /**
     * The institution can report this, but has not for the period in question.
     */
    public static function notReported(?string $note = null): self
    {
        return new self(self::NOT_REPORTED, null, null, $note);
    }

    /**
     * The institution cannot report this — typically a system mid-build.
     *
     * This is the state that must never render as zero. edutrustshsm will move
     * capabilities out of here over the coming months; posinnova's ledger
     * capabilities may never arrive at all.
     */
    public static function notCapable(?string $note = null): self
    {
        return new self(self::NOT_CAPABLE, null, null, $note);
    }

    /**
     * The capability could never apply here — enrolment at a pharmacy.
     *
     * Distinct from notCapable because it is not a gap that will ever close, and
     * showing it as one would train people to ignore the capability matrix.
     */
    public static function notApplicable(): self
    {
        return new self(self::NOT_APPLICABLE, null);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }

    public function isMissing(): bool
    {
        return $this->value === null;
    }

    public function isStale(): bool
    {
        return $this->state === self::STALE;
    }

    /**
     * The amount, or null. Callers must handle null; there is deliberately no
     * valueOrZero() convenience, because that is the exact mistake this class
     * exists to prevent.
     */
    public function money(): ?Money
    {
        return $this->value;
    }

    public function asOf(): ?CarbonInterface
    {
        return $this->asOf;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /**
     * What to show when there is no number. Never "0", never "-" alone.
     */
    public function label(): string
    {
        switch ($this->state) {
            case self::NOT_CAPABLE:
                return __('doesn\'t send this');
            case self::NOT_APPLICABLE:
                return __('not applicable');
            case self::NOT_REPORTED:
                return __('no report');
            default:
                return '';
        }
    }

    /**
     * Formatted amount, or null when there is nothing to format.
     */
    public function formatted(): ?string
    {
        return $this->value === null
            ? null
            : number_format((float) $this->value->value(), 0, '.', ' ');
    }

    /**
     * Sum a set of figures, keeping track of what was left out.
     *
     * Coverage is returned WITH the total rather than alongside it, so a caller
     * cannot obtain the number without also obtaining the fact that it covers 7
     * of 9 institutions. That is the whole design: brief §7.4 requires the
     * console to state coverage explicitly, and the reliable way to guarantee
     * that is to make the total inseparable from it.
     *
     * @param  array<string, Figure>  $figures  keyed by a label for the source
     */
    public static function sum(array $figures): FigureAggregate
    {
        return FigureAggregate::of($figures);
    }
}
