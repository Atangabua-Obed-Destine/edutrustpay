<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

/**
 * A school's budget sheet as it stood at the end of one month: each line's budget
 * for the year, what moved on it in the month, and the running total so far.
 *
 * It is the sheet the bursar already keeps, sent as it is. Lines stay in the
 * school's own order, headings included, because that order is how the sheet reads.
 * A heading's figures already contain its lines, so section totals count lines only.
 *
 * Holds no names. Sent as its own document, like the daybook, so a problem with it
 * can never hold the monthly report back.
 */
final class BudgetSheet
{
    public const DOCUMENT = 'budget_sheet';

    public const VERSION = 1;

    /**
     * @param  array{title: string, starts: string, ends: string}  $budget
     * @param  array<int, array{code?: ?string, name: string, section: string, heading: bool,
     *     budget: Money, month: Money, to_date: Money}>  $lines  in the school's own order
     * @return array<string, mixed>
     */
    public static function build(
        string $institutionRef,
        string $period,
        int $sequence,
        string $generatedAt,
        string $currency,
        array $budget,
        array $lines
    ): array {
        $totals = [];
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                'code' => isset($line['code']) && trim((string) $line['code']) !== '' ? (string) $line['code'] : null,
                'name' => $line['name'],
                'section' => $line['section'],
                'heading' => (bool) $line['heading'],
                'budget' => $line['budget']->value(),
                'month' => $line['month']->value(),
                'to_date' => $line['to_date']->value(),
            ];

            if (! $line['heading']) {
                $totals[$line['section']] ??= ['budget' => Money::zero(), 'month' => Money::zero(), 'to_date' => Money::zero()];

                foreach (['budget', 'month', 'to_date'] as $column) {
                    $totals[$line['section']][$column] = $totals[$line['section']][$column]->add($line[$column]);
                }
            }
        }

        ksort($totals);

        return [
            'document' => self::DOCUMENT,
            'version' => self::VERSION,
            'institution_ref' => $institutionRef,
            'period' => $period,
            'sequence' => $sequence,
            'generated_at' => $generatedAt,
            'currency' => $currency,
            'budget' => [
                'title' => $budget['title'],
                'starts' => $budget['starts'],
                'ends' => $budget['ends'],
            ],
            'totals' => array_map(fn (array $t) => array_map(fn (Money $m) => $m->value(), $t), $totals),
            'lines' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    public static function errors(array $document): array
    {
        if (($document['document'] ?? null) !== self::DOCUMENT) {
            return ['This is not a budget sheet.'];
        }

        if (($document['version'] ?? null) !== self::VERSION) {
            return [sprintf('Budget sheet version %d is the only one understood here.', self::VERSION)];
        }

        $errors = [];

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($document['period'] ?? ''))) {
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

        $budget = $document['budget'] ?? null;

        if (! is_array($budget) || ! is_string($budget['title'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($budget['starts'] ?? ''))
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($budget['ends'] ?? ''))) {
            $errors[] = 'budget needs a title, and starts and ends as dates.';
        }

        if (! is_array($document['lines'] ?? null) || $document['lines'] === []) {
            return array_merge($errors, ['lines must list the sheet.']);
        }

        $totals = [];

        foreach ($document['lines'] as $i => $line) {
            if (! is_array($line) || ! is_string($line['name'] ?? null) || trim($line['name']) === ''
                || ! is_string($line['section'] ?? null) || ! preg_match('/^[a-z_]{1,30}$/', $line['section'])
                || ! is_bool($line['heading'] ?? null)) {
                $errors[] = sprintf('Line %d needs a name, a section and whether it is a heading.', $i + 1);

                continue;
            }

            try {
                $figures = array_map(fn (string $c) => Money::of($line[$c] ?? null), ['budget' => 'budget', 'month' => 'month', 'to_date' => 'to_date']);
            } catch (\Throwable) {
                $errors[] = sprintf('Line %d has a figure that is not a decimal string.', $i + 1);

                continue;
            }

            if (! $line['heading']) {
                foreach ($figures as $column => $money) {
                    $totals[$line['section']][$column] = ($totals[$line['section']][$column] ?? Money::zero())->add($money);
                }
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach ($totals as $section => $columns) {
            foreach ($columns as $column => $money) {
                if ((string) ($document['totals'][$section][$column] ?? '') !== $money->value()) {
                    return ['totals do not agree with the lines.'];
                }
            }
        }

        return [];
    }

    /**
     * Has the sheet changed since it was last filed? generated_at does not count.
     *
     * @param  array<string, mixed>  $document
     */
    public static function changed(string $filedJson, array $document): bool
    {
        $filed = Canonical::decode($filedJson);
        $key = fn (array $d) => hash('sha256', Canonical::encode(['budget' => $d['budget'] ?? [], 'lines' => $d['lines'] ?? []]));

        return $key($filed) !== $key($document);
    }
}
