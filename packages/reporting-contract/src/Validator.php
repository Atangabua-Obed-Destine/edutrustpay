<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

use EdutrustPay\Contract\Exceptions\CapabilityMismatchException;

/**
 * Validates a decoded contract payload.
 *
 * THE CENTRAL JUDGEMENT: what counts as an error
 * ----------------------------------------------
 * An ERROR means the payload is malformed and cannot be understood — a bad
 * period, an unknown capability, money that is not a decimal string. Errors are
 * rejected at ingest.
 *
 * A WARNING means the payload is well-formed but the figures in it disagree with
 * each other. These are ACCEPTED and stored, then turned into Findings by the
 * console.
 *
 * That distinction is the whole point, and getting it backwards would be a
 * serious mistake. A ledger whose debits do not equal its credits is the single
 * clearest sign that something is wrong with an institution's books — so it must
 * be *received*, not refused. Rejecting it at the door would mean the one report
 * that most needed a human to see it is the one that never arrives, and the
 * institution shows up as "not reporting" instead of "reporting something
 * alarming". Those are very different problems and they get different responses.
 *
 * The same reasoning applies to ageing buckets that do not sum to their total,
 * and to a surplus that does not equal income minus expenditure.
 */
final class Validator
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<array{code: string, message: string, context: array<string, mixed>}> */
    private array $warnings = [];

    /**
     * Validate a decoded payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{valid: bool, errors: list<string>, warnings: list<array{code: string, message: string, context: array<string, mixed>}>}
     */
    public function validate(array $payload): array
    {
        $this->errors = [];
        $this->warnings = [];

        // Normalise to wire shape first, so this method validates exactly what
        // will be transmitted rather than whatever in-memory representation the
        // caller happens to hold. It accepts both a freshly built payload (Money
        // objects, stdClass maps) and one decoded from JSON (all strings), and
        // checks the same bytes either way.
        try {
            $payload = Canonical::decode(Canonical::encode($payload));
        } catch (\Throwable $e) {
            // Canonical refuses anything that cannot be encoded reproducibly —
            // a float where money should be, most likely. That is a validation
            // failure, not a crash.
            $this->errors[] = $e->getMessage();

            return $this->result();
        }

        $this->checkEnvelope($payload);

        // Everything below reads the financial blocks, which is only meaningful
        // if the envelope parsed. Bail early rather than emit cascading noise.
        if ($this->errors !== []) {
            return $this->result();
        }

        $this->checkCapabilityCoupling($payload);
        $this->checkFinancial($payload);
        $this->checkOperational($payload);
        $this->checkIntegrity($payload);

        return $this->result();
    }

    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<array{code: string, message: string, context: array<string, mixed>}>}
     */
    private function result(): array
    {
        return [
            'valid' => $this->errors === [],
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Throw unless declared capabilities and supplied blocks agree exactly.
     *
     * Used by PayloadBuilder on the way out and by the ingest side on the way
     * in, so neither end can produce or accept a payload that conflates "cannot
     * report" with "zero".
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertCapabilityCoupling(array $payload): void
    {
        $validator = new self();
        $validator->checkCapabilityCoupling($payload);

        if ($validator->errors !== []) {
            throw new CapabilityMismatchException(implode(' ', $validator->errors));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkEnvelope(array $payload): void
    {
        foreach (['contract_version', 'institution_ref', 'period', 'status', 'sequence', 'generated_at', 'currency', 'capabilities'] as $key) {
            if (! array_key_exists($key, $payload)) {
                $this->errors[] = sprintf('Missing required field "%s".', $key);
            }
        }

        if ($this->errors !== []) {
            return;
        }

        if (! is_string($payload['contract_version']) || ! ContractVersion::supports($payload['contract_version'])) {
            $this->errors[] = sprintf(
                'Unsupported contract_version "%s". This server speaks %s.',
                is_string($payload['contract_version']) ? $payload['contract_version'] : gettype($payload['contract_version']),
                implode(', ', ContractVersion::SUPPORTED)
            );
        }

        if (! is_string($payload['period']) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $payload['period']) !== 1) {
            $this->errors[] = 'Field "period" must be a calendar month "YYYY-MM". The unit is always the calendar month, never a fiscal year.';
        }

        if (! in_array($payload['status'], ['provisional', 'final'], true)) {
            $this->errors[] = 'Field "status" must be "provisional" or "final".';
        }

        if (! is_int($payload['sequence']) || $payload['sequence'] < 1) {
            $this->errors[] = 'Field "sequence" must be an integer of at least 1.';
        }

        if (! is_string($payload['currency']) || preg_match('/^[A-Z]{3}$/', $payload['currency']) !== 1) {
            $this->errors[] = 'Field "currency" must be a 3-letter ISO code.';
        }

        if (! is_string($payload['generated_at']) || strtotime($payload['generated_at']) === false) {
            $this->errors[] = 'Field "generated_at" must be a parseable ISO-8601 timestamp.';
        }

        if (! is_array($payload['capabilities']) || $payload['capabilities'] === []) {
            $this->errors[] = 'Field "capabilities" must be a non-empty list. An institution with nothing to report sends a heartbeat, not an empty report.';

            return;
        }

        foreach ($payload['capabilities'] as $capability) {
            if (! is_string($capability) || ! Capability::isValid($capability)) {
                $this->errors[] = sprintf(
                    'Unknown capability "%s". Known capabilities: %s.',
                    is_string($capability) ? $capability : gettype($capability),
                    implode(', ', Capability::all())
                );
            }
        }

        if (count($payload['capabilities']) !== count(array_unique($payload['capabilities']))) {
            $this->errors[] = 'Field "capabilities" contains duplicates.';
        }
    }

    /**
     * Both directions of the missing-vs-zero rule.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkCapabilityCoupling(array $payload): void
    {
        $declared = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];

        foreach (Capability::all() as $capability) {
            $isDeclared = in_array($capability, $declared, true);

            foreach (Capability::blocksFor($capability) as $block) {
                $present = self::hasPath($payload, $block);

                if ($isDeclared && ! $present) {
                    $this->errors[] = sprintf(
                        'Capability "%s" is declared but "%s" is absent. Declaring a capability means '
                        .'the figures are supplied; if the institution cannot report it, omit the capability.',
                        $capability,
                        $block
                    );
                }

                if (! $isDeclared && $present) {
                    $this->errors[] = sprintf(
                        'Block "%s" is present but capability "%s" is not declared. Figures the console '
                        .'cannot attribute to a declared capability are not trustworthy and are refused.',
                        $block,
                        $capability
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkFinancial(array $payload): void
    {
        $financial = $payload['financial'] ?? null;

        if (! is_array($financial)) {
            return;
        }

        foreach (['income_by_group', 'expenditure_by_group'] as $key) {
            if (! isset($financial[$key])) {
                continue;
            }

            foreach ((array) $financial[$key] as $group => $amount) {
                if (preg_match('/^\d{2}$/', (string) $group) !== 1) {
                    $this->errors[] = sprintf('%s is keyed by two-digit OHADA group; got "%s".', $key, (string) $group);
                }

                $this->checkMoneyString($amount, "financial.$key.".(string) $group);
            }
        }

        if (array_key_exists('group_names', $financial)) {
            $this->checkGroupNames($payload, $financial);
        }

        foreach (['surplus_deficit'] as $key) {
            if (isset($financial[$key])) {
                $this->checkMoneyString($financial[$key], "financial.$key");
            }
        }

        if (isset($financial['cash']) && is_array($financial['cash'])) {
            foreach (['opening', 'movement', 'closing'] as $key) {
                $this->checkMoneyString($financial['cash'][$key] ?? null, "financial.cash.$key");
            }

            $this->checkCashContinuity($financial['cash']);
        }

        if (isset($financial['control']) && is_array($financial['control'])) {
            $this->checkControl($financial['control']);
        }

        foreach (['receivables', 'payables'] as $key) {
            if (isset($financial[$key]) && is_array($financial[$key])) {
                $this->checkAgeingBlock($financial[$key], "financial.$key");
            }
        }

        if (isset($financial['budget']) && is_array($financial['budget'])) {
            $this->checkMoneyString($financial['budget']['budgeted'] ?? null, 'financial.budget.budgeted');
            $this->checkMoneyString($financial['budget']['actual'] ?? null, 'financial.budget.actual');
        }

        if (isset($financial['payroll']) && is_array($financial['payroll'])) {
            $this->checkMoneyString($financial['payroll']['staff_cost'] ?? null, 'financial.payroll.staff_cost');
            $this->checkMoneyString($financial['payroll']['statutory_liabilities'] ?? null, 'financial.payroll.statutory_liabilities');
        }

        if (isset($financial['bank_recon']) && is_array($financial['bank_recon'])) {
            foreach (['statement_closing', 'ledger_closing', 'divergence'] as $key) {
                $this->checkMoneyString($financial['bank_recon'][$key] ?? null, "financial.bank_recon.$key");
            }
        }

        $this->checkSurplus($financial);
    }

    /**
     * The school's own names for its account groups. Words only, one per
     * two-digit group.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $financial
     */
    private function checkGroupNames(array $payload, array $financial): void
    {
        $version = is_string($payload['contract_version'] ?? null) ? $payload['contract_version'] : '0';

        if (version_compare($version, ContractVersion::GROUP_NAMES_FROM, '<')) {
            $this->errors[] = sprintf('financial.group_names arrived in contract %s; this report says %s.', ContractVersion::GROUP_NAMES_FROM, $version);

            return;
        }

        if (! in_array(Capability::LEDGER, (array) ($payload['capabilities'] ?? []), true)) {
            $this->errors[] = 'financial.group_names describes the ledger, so capability "ledger" must be declared.';
        }

        if (! is_array($financial['group_names'])) {
            $this->errors[] = 'financial.group_names must be a map of two-digit OHADA group to name.';

            return;
        }

        foreach ($financial['group_names'] as $group => $name) {
            if (preg_match('/^\d{2}$/', (string) $group) !== 1) {
                $this->errors[] = sprintf('financial.group_names is keyed by two-digit OHADA group; got "%s".', (string) $group);

                continue;
            }

            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > PayloadBuilder::GROUP_NAME_MAX) {
                $this->errors[] = sprintf('financial.group_names.%s must be a name of 1 to %d characters.', (string) $group, PayloadBuilder::GROUP_NAME_MAX);
            }
        }
    }

    /**
     * Debits must equal credits — and when they do not, we ACCEPT and warn.
     *
     * @param  array<string, mixed>  $control
     */
    private function checkControl(array $control): void
    {
        foreach (['total_debits', 'total_credits'] as $key) {
            $this->checkMoneyString($control[$key] ?? null, "financial.control.$key");
        }

        foreach (['journal_entry_count', 'unposted_count'] as $key) {
            if (! isset($control[$key]) || ! is_int($control[$key]) || $control[$key] < 0) {
                $this->errors[] = sprintf('financial.control.%s must be a non-negative integer.', $key);
            }
        }

        if ($this->errors !== []) {
            return;
        }

        $debits = Money::of((string) $control['total_debits']);
        $credits = Money::of((string) $control['total_credits']);

        if (! $debits->equals($credits)) {
            $this->warn(
                'unbalanced_ledger',
                sprintf(
                    'The ledger does not balance: debits %s against credits %s, a difference of %s.',
                    $debits->value(),
                    $credits->value(),
                    $debits->subtract($credits)->value()
                ),
                [
                    'total_debits' => $debits->value(),
                    'total_credits' => $credits->value(),
                    'difference' => $debits->subtract($credits)->value(),
                ]
            );
        }

        if ($control['unposted_count'] > 0) {
            $this->warn(
                'unposted_backlog',
                sprintf(
                    '%d journal entries are unposted and therefore excluded from these figures.',
                    $control['unposted_count']
                ),
                ['unposted_count' => $control['unposted_count']]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $cash
     */
    private function checkCashContinuity(array $cash): void
    {
        if ($this->errors !== []) {
            return;
        }

        foreach (['opening', 'movement', 'closing'] as $key) {
            if (! isset($cash[$key]) || ! is_string($cash[$key])) {
                return;
            }
        }

        $opening = Money::of($cash['opening']);
        $movement = Money::of($cash['movement']);
        $closing = Money::of($cash['closing']);

        if (! $opening->add($movement)->equals($closing)) {
            $this->warn(
                'cash_discontinuity',
                sprintf(
                    'Cash does not reconcile: opening %s plus movement %s is %s, but closing is stated as %s.',
                    $opening->value(),
                    $movement->value(),
                    $opening->add($movement)->value(),
                    $closing->value()
                ),
                [
                    'opening' => $opening->value(),
                    'movement' => $movement->value(),
                    'closing' => $closing->value(),
                    'expected_closing' => $opening->add($movement)->value(),
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function checkAgeingBlock(array $block, string $label): void
    {
        $this->checkMoneyString($block['total'] ?? null, "$label.total");

        $ageing = $block['ageing'] ?? null;

        if (! is_array($ageing)) {
            $this->errors[] = sprintf('%s.ageing is required and must be an object.', $label);

            return;
        }

        $buckets = ['0_30', '31_60', '61_90', '90_plus'];

        foreach ($buckets as $bucket) {
            $this->checkMoneyString($ageing[$bucket] ?? null, "$label.ageing.$bucket");
        }

        if ($this->errors !== []) {
            return;
        }

        $sum = Money::sum(array_map(
            static fn (string $bucket): Money => Money::of((string) $ageing[$bucket]),
            $buckets
        ));

        $total = Money::of((string) $block['total']);

        if (! $sum->equals($total)) {
            $this->warn(
                'ageing_inconsistent',
                sprintf(
                    '%s buckets sum to %s but the stated total is %s.',
                    $label,
                    $sum->value(),
                    $total->value()
                ),
                ['bucket_sum' => $sum->value(), 'stated_total' => $total->value(), 'block' => $label]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $financial
     */
    private function checkSurplus(array $financial): void
    {
        if ($this->errors !== [] || ! isset($financial['surplus_deficit'], $financial['income_by_group'], $financial['expenditure_by_group'])) {
            return;
        }

        $income = Money::sum(array_map(
            static fn ($a): Money => Money::of((string) $a),
            array_values((array) $financial['income_by_group'])
        ));

        $expenditure = Money::sum(array_map(
            static fn ($a): Money => Money::of((string) $a),
            array_values((array) $financial['expenditure_by_group'])
        ));

        $stated = Money::of((string) $financial['surplus_deficit']);
        $computed = $income->subtract($expenditure);

        if (! $stated->equals($computed)) {
            $this->warn(
                'surplus_mismatch',
                sprintf(
                    'Income %s less expenditure %s is %s, but surplus_deficit is stated as %s.',
                    $income->value(),
                    $expenditure->value(),
                    $computed->value(),
                    $stated->value()
                ),
                [
                    'income' => $income->value(),
                    'expenditure' => $expenditure->value(),
                    'computed' => $computed->value(),
                    'stated' => $stated->value(),
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkOperational(array $payload): void
    {
        $operational = $payload['operational'] ?? null;

        if (! is_array($operational)) {
            return;
        }

        if (isset($operational['enrolment']) && (! is_int($operational['enrolment']) || $operational['enrolment'] < 0)) {
            $this->errors[] = 'operational.enrolment must be a non-negative integer.';
        }

        foreach (['expected_fees', 'collected'] as $key) {
            if (isset($operational[$key])) {
                $this->checkMoneyString($operational[$key], "operational.$key");
            }
        }

        foreach (['collection_rate', 'cash_share'] as $key) {
            if (! isset($operational[$key])) {
                continue;
            }

            if (! is_string($operational[$key]) || preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', $operational[$key]) !== 1) {
                $this->errors[] = sprintf(
                    'operational.%s must be a decimal string between "0" and "1" — never a float.',
                    $key
                );
            }
        }

        $version = (string) ($payload['contract_version'] ?? '');
        $since = ContractVersion::STUDENTS_AND_STAFF_FROM;

        if (array_key_exists('students', $operational)) {
            if (version_compare($version, $since, '<')) {
                $this->errors[] = sprintf('operational.students arrived in contract %s; this report says %s.', $since, $version);
            } else {
                $this->checkStudents($operational['students']);
            }
        }

        if (array_key_exists('staff', $operational)) {
            if (version_compare($version, $since, '<')) {
                $this->errors[] = sprintf('operational.staff arrived in contract %s; this report says %s.', $since, $version);
            } else {
                $this->checkStaff($operational['staff']);
            }
        }
    }

    /**
     * @param  mixed  $block
     */
    private function checkStudents($block): void
    {
        if (! is_array($block) || ! $this->isText($block['session'] ?? null) || ! is_int($block['total'] ?? null) || $block['total'] < 0
            || ! is_array($block['by_class'] ?? null) || ! array_is_list($block['by_class'])) {
            $this->errors[] = 'operational.students must have a session name, a total and a list of classes.';

            return;
        }

        $seen = [];
        $sum = 0;

        foreach ($block['by_class'] as $i => $row) {
            if (! is_array($row) || ! $this->isText($row['level'] ?? null) || ! is_int($row['students'] ?? null) || $row['students'] < 0) {
                $this->errors[] = sprintf('operational.students.by_class[%d] needs a class name and a whole number of students.', $i);

                continue;
            }

            if (isset($seen[$row['level']])) {
                $this->errors[] = sprintf('operational.students lists the class "%s" twice.', $row['level']);
            }

            $seen[$row['level']] = true;
            $sum += $row['students'];
        }

        if ($sum !== $block['total']) {
            $this->errors[] = sprintf('operational.students.by_class adds up to %d, not the total of %d.', $sum, $block['total']);
        }
    }

    /**
     * @param  mixed  $block
     */
    private function checkStaff($block): void
    {
        if (! is_array($block) || ! is_int($block['total'] ?? null) || $block['total'] < 0
            || ! is_array($block['people'] ?? null) || ! array_is_list($block['people'])) {
            $this->errors[] = 'operational.staff must have a total and a list of people.';

            return;
        }

        foreach ($block['people'] as $i => $person) {
            $allowed = ['department', 'designation', 'name', 'number'];

            if (! is_array($person) || array_diff(array_keys($person), $allowed) !== [] || ! $this->isText($person['name'] ?? null)) {
                // Anything beyond these four is refused, pay above all: the
                // console was approved to hold who is posted where, not more.
                $this->errors[] = sprintf('operational.staff.people[%d] must carry a name, and nothing besides number, department and designation.', $i);

                continue;
            }

            foreach (['number', 'department', 'designation'] as $key) {
                if (($person[$key] ?? null) !== null && ! $this->isText($person[$key])) {
                    $this->errors[] = sprintf('operational.staff.people[%d].%s must be text of 1 to %d characters, or null.', $i, $key, PayloadBuilder::TEXT_MAX);
                }
            }
        }

        if (count($block['people']) !== $block['total']) {
            $this->errors[] = sprintf('operational.staff.total says %d but the list has %d people.', $block['total'], count($block['people']));
        }

        foreach (['by_department', 'by_designation'] as $key) {
            $rows = $block[$key] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                $this->errors[] = sprintf('operational.staff.%s must be a list of rows.', $key);

                continue;
            }

            $sum = 0;

            foreach ($rows as $i => $row) {
                if (! is_array($row) || ! (($row['name'] ?? null) === null || $this->isText($row['name'])) || ! is_int($row['staff'] ?? null) || $row['staff'] < 0) {
                    $this->errors[] = sprintf('operational.staff.%s[%d] needs a name (or null) and a whole number of staff.', $key, $i);

                    continue;
                }

                $sum += $row['staff'];
            }

            if ($sum !== $block['total']) {
                $this->errors[] = sprintf('operational.staff.%s adds up to %d, not the total of %d.', $key, $sum, $block['total']);
            }
        }
    }

    /**
     * @param  mixed  $value
     */
    private function isText($value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= PayloadBuilder::TEXT_MAX;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkIntegrity(array $payload): void
    {
        $integrity = $payload['integrity'] ?? null;

        if (! is_array($integrity)) {
            return;
        }

        if (! isset($integrity['ledger_hash']) || ! is_string($integrity['ledger_hash'])
            || preg_match('/^sha256:[0-9a-f]{64}$/', $integrity['ledger_hash']) !== 1) {
            $this->errors[] = 'integrity.ledger_hash must look like "sha256:<64 hex chars>".';
        }

        foreach (['audit_events', 'reversals', 'backdated_entries'] as $key) {
            if (! isset($integrity[$key]) || ! is_int($integrity[$key]) || $integrity[$key] < 0) {
                $this->errors[] = sprintf('integrity.%s must be a non-negative integer.', $key);
            }
        }

        if ($this->errors !== []) {
            return;
        }

        if ($integrity['backdated_entries'] > 0) {
            $this->warn(
                'backdated_entries',
                sprintf(
                    '%d entries were posted into a period earlier than the date they were created.',
                    $integrity['backdated_entries']
                ),
                ['backdated_entries' => $integrity['backdated_entries']]
            );
        }
    }

    /**
     * @param  mixed  $value
     */
    private function checkMoneyString($value, string $label): void
    {
        if (! is_string($value)) {
            $this->errors[] = sprintf(
                '%s must be a decimal STRING such as "4200000.00", not %s. Money is never a JSON number '
                .'here: float encoding is not reproducible across PHP versions and would break the signature.',
                $label,
                gettype($value)
            );

            return;
        }

        if (preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]{2}$/', $value) !== 1) {
            $this->errors[] = sprintf('%s is not a canonical decimal amount: "%s". Expected exactly 2 decimal places.', $label, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function warn(string $code, string $message, array $context = []): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message, 'context' => $context];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function hasPath(array $payload, string $path): bool
    {
        $current = $payload;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }
}
