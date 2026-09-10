<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

use EdutrustPay\Contract\Exceptions\InvalidAmountException;

/**
 * An amount of money, carried as a decimal string.
 *
 * WHY NOT FLOATS, ANYWHERE
 * ------------------------
 * The HMAC that authenticates a report is computed over the *serialised bytes*
 * of the payload. json_encode() of a float is not reproducible across PHP
 * versions and platforms (serialize_precision, and the underlying double), so
 * two systems holding arithmetically equal figures can emit different bytes and
 * therefore different signatures. A correct report would be rejected as
 * tampered. Worse is the reverse: rounding drift quietly changing a
 * consolidated total that nobody reconciles.
 *
 * This matters here more than in most projects because the four codebases
 * implementing the contract run PHP 8.0 (posinnova), 8.1 (paxhitestbed) and
 * 8.2 (edutrustshsm, console).
 *
 * So money crosses the wire as a string — "4200000.00" — and all arithmetic
 * goes through bcmath. This also matches how the institutions already work:
 * edutrustshsm's ChartOfAccount::postedBalance() computes with bcmath and
 * treats the stored current_balance as "cached/advisory only".
 *
 * This deliberately diverges from the example payload in the build brief §7.2,
 * which shows bare JSON numbers. The brief's example needs amending.
 *
 * Scale is fixed at 2. XAF has no minor unit in daily use, but every ledger
 * feeding this contract is decimal(18,2), so 2 is what the books actually hold.
 */
final class Money
{
    public const SCALE = 2;

    /** Canonical form: optional '-', digits, '.', exactly 2 digits. */
    private const PATTERN = '/^-?(0|[1-9][0-9]*)\.[0-9]{2}$/';

    /** Accepted input before normalisation: 0, 1 or 2 decimal places. */
    private const INPUT_PATTERN = '/^-?(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/';

    private string $amount;

    private function __construct(string $amount)
    {
        $this->amount = $amount;
    }

    /**
     * Build from an exact value.
     *
     * Strict on purpose. Floats are refused outright, and a string carrying
     * more than two decimal places is refused rather than silently rounded. A
     * caller holding a value that needs rounding must say so via fromNumeric(),
     * so the rounding is a visible decision in the client's own code rather
     * than something this class did quietly to somebody's money.
     *
     * @param  string|int  $value
     */
    public static function of($value): self
    {
        if (is_float($value)) {
            throw new InvalidAmountException(
                'Refusing to build Money from a float: float encoding is not reproducible '
                .'across systems and would break the payload signature. Use Money::fromNumeric() '
                .'to round explicitly, or pass a decimal string.'
            );
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidAmountException('Money requires a decimal string or an integer.');
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidAmountException('Money cannot be built from an empty string.');
        }

        // Tolerate a leading '+', but nothing else exotic.
        if (strncmp($value, '+', 1) === 0) {
            $value = substr($value, 1);
        }

        if (preg_match(self::INPUT_PATTERN, $value) !== 1) {
            throw new InvalidAmountException(sprintf(
                'Not a valid decimal amount: "%s". Expected digits with at most 2 decimal places, e.g. "4200000.00".',
                $value
            ));
        }

        return new self(self::normalise($value));
    }

    /**
     * Build from a value that may need rounding — a float from a legacy report,
     * a SUM() returned by a database driver, a computed rate.
     *
     * Rounds half-up, not PHP's default banker's rounding, because half-up is
     * what the bursars' spreadsheets and the printed OHADA statements already
     * do. A consolidated figure that disagrees with the institution's own
     * printed statement is worse than useless: it is an argument.
     *
     * @param  string|int|float  $value
     */
    public static function fromNumeric($value): self
    {
        if (is_int($value)) {
            return self::of($value);
        }

        if (is_string($value)) {
            $value = trim($value);

            if (preg_match(self::INPUT_PATTERN, $value) === 1) {
                return self::of($value);
            }

            if (! is_numeric($value)) {
                throw new InvalidAmountException(sprintf('Not a numeric amount: "%s".', $value));
            }

            $value = (float) $value;
        }

        if (! is_float($value)) {
            throw new InvalidAmountException('fromNumeric() expects a string, int or float.');
        }

        if (! is_finite($value)) {
            throw new InvalidAmountException('Refusing to build Money from NAN or INF.');
        }

        return new self(self::normalise(number_format($value, self::SCALE, '.', '')));
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    /**
     * @param  iterable<Money>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            if (! $amount instanceof self) {
                throw new InvalidAmountException('Money::sum() accepts only Money instances.');
            }

            $total = bcadd($total, $amount->amount, self::SCALE);
        }

        return new self(self::normalise($total));
    }

    public function add(self $other): self
    {
        return new self(self::normalise(bcadd($this->amount, $other->amount, self::SCALE)));
    }

    public function subtract(self $other): self
    {
        return new self(self::normalise(bcsub($this->amount, $other->amount, self::SCALE)));
    }

    /** -1 if this is less than $other, 0 if equal, 1 if greater. */
    public function compare(self $other): int
    {
        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0.00', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0.00', self::SCALE) < 0;
    }

    /** The canonical decimal string. This is exactly what goes on the wire. */
    public function value(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /**
     * Normalise to exactly SCALE decimal places.
     *
     * Input has already been validated as exact, so bcadd pads rather than
     * rounds. The negative-zero guard matters: bcsub can render "-0.00", and
     * two arithmetically equal amounts MUST serialise to identical bytes or the
     * signature breaks.
     */
    private static function normalise(string $value): string
    {
        $padded = bcadd($value, '0', self::SCALE);

        if ($padded === '-0.00') {
            $padded = '0.00';
        }

        if (preg_match(self::PATTERN, $padded) !== 1) {
            throw new InvalidAmountException(sprintf('Amount failed normalisation: "%s".', $value));
        }

        return $padded;
    }
}
