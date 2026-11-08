<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

/**
 * One month of a school's daybook: every movement of money, one line each.
 *
 * Sent as its own document rather than inside the monthly report. A report is a
 * small set of totals that must get through on a poor connection, and a large
 * daybook failing should never stop the report from arriving.
 *
 * Unlike a report, THIS HOLDS PEOPLE'S NAMES, in `description` and `party`. The
 * body chose to hold them so it can analyse and compare its schools. A console
 * must keep it behind its own permission and record who reads it.
 *
 * Amounts are decimal strings, as everywhere in this contract. `sequence` is this
 * document's own and has nothing to do with the report's. An empty `lines` means
 * the school recorded no movement that month, which is different from not having
 * sent a daybook at all.
 */
final class Daybook
{
    public const DOCUMENT = 'daybook';

    public const VERSION = 1;

    public const IN = 'in';

    public const OUT = 'out';

    /** Comparable across schools, whatever each one calls its own headings. */
    public const KINDS = ['fee', 'income', 'expense', 'payroll', 'refund', 'remittance', 'other'];

    public const METHODS = ['cash', 'bank', 'cheque', 'card', 'mobile_money', 'online', 'other'];

    /**
     * @param  array<int, array{date: string, ref: string, description: string, party?: ?string, heading: string,
     *     kind: string, direction: string, amount: Money, method?: ?string, source_id: string}>  $lines
     * @return array<string, mixed>
     */
    public static function build(
        string $institutionRef,
        string $period,
        int $sequence,
        string $generatedAt,
        string $currency,
        array $lines
    ): array {
        // Sorted so the same month always serialises, and signs, the same way.
        usort($lines, fn (array $a, array $b) => [$a['date'], $a['kind'], $a['source_id']]
            <=> [$b['date'], $b['kind'], $b['source_id']]);

        $in = [];
        $out = [];
        $rows = [];

        foreach ($lines as $line) {
            if ($line['direction'] === self::IN) {
                $in[] = $line['amount'];
            } else {
                $out[] = $line['amount'];
            }

            $rows[] = [
                'date' => $line['date'],
                'ref' => $line['ref'],
                'description' => $line['description'],
                'party' => self::blankToNull($line['party'] ?? null),
                'heading' => $line['heading'],
                'kind' => $line['kind'],
                'direction' => $line['direction'],
                'amount' => $line['amount']->value(),
                'method' => $line['method'] ?? null,
                'source_id' => $line['source_id'],
            ];
        }

        return [
            'document' => self::DOCUMENT,
            'version' => self::VERSION,
            'institution_ref' => $institutionRef,
            'period' => $period,
            'sequence' => $sequence,
            'generated_at' => $generatedAt,
            'currency' => $currency,
            'money_in' => Money::sum($in)->value(),
            'money_out' => Money::sum($out)->value(),
            'lines' => $rows,
        ];
    }

    /**
     * What is wrong with this document. Empty means it is acceptable.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    public static function errors(array $document): array
    {
        if (($document['document'] ?? null) !== self::DOCUMENT) {
            return ['This is not a daybook document.'];
        }

        if (($document['version'] ?? null) !== self::VERSION) {
            return [sprintf('Daybook version %d is the only one understood here.', self::VERSION)];
        }

        $errors = [];
        $period = (string) ($document['period'] ?? '');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $errors[] = 'period must be a calendar month, YYYY-MM.';
        }

        if (! is_int($document['sequence'] ?? null) || $document['sequence'] < 1) {
            $errors[] = 'sequence must be a whole number from 1.';
        }

        foreach (['institution_ref', 'generated_at', 'currency'] as $field) {
            if (! is_string($document[$field] ?? null) || $document[$field] === '') {
                $errors[] = $field.' is required.';
            }
        }

        if (! is_array($document['lines'] ?? null)) {
            return array_merge($errors, ['lines must be a list.']);
        }

        $in = [];
        $out = [];

        foreach ($document['lines'] as $i => $line) {
            $problem = is_array($line) ? self::lineProblem($line, $period) : 'is not an object';

            if ($problem !== null) {
                $errors[] = sprintf('Line %d %s.', $i + 1, $problem);

                continue;
            }

            if ($line['direction'] === self::IN) {
                $in[] = Money::of($line['amount']);
            } else {
                $out[] = Money::of($line['amount']);
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        // Stated totals have to agree with the lines, or one of them is wrong.
        if ((string) ($document['money_in'] ?? '') !== Money::sum($in)->value()
            || (string) ($document['money_out'] ?? '') !== Money::sum($out)->value()) {
            $errors[] = 'money_in and money_out do not agree with the lines.';
        }

        return $errors;
    }

    /**
     * Do these lines differ from what was last filed?
     *
     * Only the lines count: generated_at differs on every build, and the totals
     * follow from the lines.
     *
     * @param  string  $filedJson  the document as it was last delivered
     * @param  array<string, mixed>  $document  the document as it stands now
     */
    public static function changed(string $filedJson, array $document): bool
    {
        return hash('sha256', Canonical::encode(['lines' => Canonical::decode($filedJson)['lines'] ?? []]))
            !== hash('sha256', Canonical::encode(['lines' => $document['lines'] ?? []]));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function lineProblem(array $line, string $period): ?string
    {
        foreach (['date', 'ref', 'description', 'heading', 'source_id'] as $field) {
            if (! is_string($line[$field] ?? null) || trim($line[$field]) === '') {
                return 'needs '.$field;
            }
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $line['date']) || substr($line['date'], 0, 7) !== $period) {
            return 'has a date outside '.$period;
        }

        if (! in_array($line['kind'] ?? null, self::KINDS, true)) {
            return 'has an unknown kind';
        }

        if (! in_array($line['direction'] ?? null, [self::IN, self::OUT], true)) {
            return 'must go in or out';
        }

        if (isset($line['method']) && ! in_array($line['method'], self::METHODS, true)) {
            return 'has an unknown method';
        }

        try {
            Money::of($line['amount'] ?? null);
        } catch (\Throwable) {
            return 'has an amount that is not a decimal string';
        }

        return null;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
