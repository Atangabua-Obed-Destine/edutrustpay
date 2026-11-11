<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

use EdutrustPay\Contract\Exceptions\CapabilityMismatchException;
use EdutrustPay\Contract\Exceptions\ContractException;

/**
 * Builds a contract payload.
 *
 * THE DESIGN IDEA
 * ---------------
 * The rule the platform rests on is that a missing figure and a zero figure are
 * different facts. Every other codebase states that rule in a comment and hopes.
 * Here, declaring a capability and supplying its figures are THE SAME ACT: you
 * declare `receivables` by calling receivables(). There is no way to declare a
 * capability you have no figures for, and no way to smuggle figures in without
 * declaring the capability, because neither is expressible.
 *
 * That means the two failure modes cannot occur:
 *   - a system reporting 0 for something it cannot actually measure
 *   - a system quietly attaching figures the console does not know to trust
 *
 * Omission is the way to say "cannot report". It requires no method call, which
 * is as it should be: an institution mid-build (edutrustshsm) or without a
 * working ledger at all (posinnova) simply calls fewer methods.
 *
 * Amounts are Money instances throughout. Counts are integers. Nothing is a
 * float — see Money and Canonical for why that is load-bearing rather than
 * fastidious.
 */
final class PayloadBuilder
{
    private string $institutionRef;

    private string $period;

    private string $status = 'provisional';

    private int $sequence = 1;

    private string $generatedAt;

    private string $currency = 'XAF';

    /** @var array<string, mixed> */
    private array $financial = [];

    /** @var array<string, mixed> */
    private array $operational = [];

    /** @var array<string, mixed>|null */
    private ?array $integrity = null;

    /** @var array<string, string> */
    private array $groupNames = [];

    public const GROUP_NAME_MAX = 120;

    /** The longest class name, session, staff name, number, department or post. */
    public const TEXT_MAX = 120;


    /** @var array<string, bool> capability => declared */
    private array $declared = [];

    private function __construct(string $institutionRef, string $period)
    {
        $this->institutionRef = $institutionRef;
        $this->period = $period;
        $this->generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        self::assertPeriod($period);

        if ($institutionRef === '') {
            throw new ContractException('An institution reference is required.');
        }
    }

    /**
     * @param  string  $period  calendar month, "YYYY-MM"
     */
    public static function for(string $institutionRef, string $period): self
    {
        return new self($institutionRef, $period);
    }

    /**
     * provisional — the month is still open and these figures may move.
     * final      — the month is closed.
     *
     * The distinction matters because restating a FINAL period is a control
     * event (fraud model B: books edited after the fact), while a provisional
     * month changing is just normal bookkeeping.
     */
    public function status(string $status): self
    {
        if (! in_array($status, ['provisional', 'final'], true)) {
            throw new ContractException(sprintf('Status must be provisional or final, got "%s".', $status));
        }

        $this->status = $status;

        return $this;
    }

    /**
     * Increments per (institution, period). A higher sequence for a period
     * already received supersedes it; the prior version is retained, never
     * overwritten (brief §4.7).
     */
    public function sequence(int $sequence): self
    {
        if ($sequence < 1) {
            throw new ContractException('Sequence starts at 1.');
        }

        $this->sequence = $sequence;

        return $this;
    }

    /** ISO-8601 UTC. Defaults to now. */
    public function generatedAt(string $isoUtc): self
    {
        if (strtotime($isoUtc) === false) {
            throw new ContractException(sprintf('generated_at must be a parseable timestamp, got "%s".', $isoUtc));
        }

        $this->generatedAt = $isoUtc;

        return $this;
    }

    public function currency(string $currency): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ContractException(sprintf('Currency must be a 3-letter ISO code, got "%s".', $currency));
        }

        $this->currency = $currency;

        return $this;
    }

    /**
     * Income and expenditure by OHADA group, cash position, and control totals.
     *
     * Consolidation happens at OHADA class/group level precisely so that no
     * institution has to adopt anyone else's chart of accounts (brief §4.2).
     * Each maps its own accounts to OHADA groups once, locally.
     *
     * The control totals let the console verify that the ledger balances itself
     * rather than trusting a summary. Note that an UNBALANCED set is accepted
     * here on purpose — see Validator for why rejecting it would be a mistake.
     *
     * @param  array<string, Money>  $incomeByGroup       OHADA group => amount, e.g. ['70' => Money]
     * @param  array<string, Money>  $expenditureByGroup
     * @param  array{opening: Money, movement: Money, closing: Money}  $cash
     * @param  array{total_debits: Money, total_credits: Money, journal_entry_count: int, unposted_count: int}  $control
     */
    public function ledger(
        array $incomeByGroup,
        array $expenditureByGroup,
        Money $surplusDeficit,
        array $cash,
        array $control
    ): self {
        self::assertGroupMap($incomeByGroup, 'income_by_group');
        self::assertGroupMap($expenditureByGroup, 'expenditure_by_group');
        self::assertShape($cash, ['opening', 'movement', 'closing'], 'cash');
        self::assertMoneyValues($cash, 'cash');
        self::assertShape($control, ['total_debits', 'total_credits', 'journal_entry_count', 'unposted_count'], 'control');

        foreach (['total_debits', 'total_credits'] as $key) {
            if (! $control[$key] instanceof Money) {
                throw new ContractException(sprintf('control.%s must be a Money instance.', $key));
            }
        }

        foreach (['journal_entry_count', 'unposted_count'] as $key) {
            if (! is_int($control[$key]) || $control[$key] < 0) {
                throw new ContractException(sprintf('control.%s must be a non-negative integer.', $key));
            }
        }

        $this->financial['income_by_group'] = self::asMap($incomeByGroup);
        $this->financial['expenditure_by_group'] = self::asMap($expenditureByGroup);
        $this->financial['surplus_deficit'] = $surplusDeficit;
        $this->financial['cash'] = $cash;
        $this->financial['control'] = $control;

        return $this->declare(Capability::LEDGER);
    }

    /**
     * The school's own name for each OHADA group it uses, from its own chart of
     * accounts ("71" => "Scolarites").
     *
     * Schools book the same kind of money under different groups, so a fixed
     * list of names on the console would mislabel someone. These are the
     * school's words for its own books.
     *
     * Send the names of all the groups the school uses for income and spending,
     * not only this month's: a closed month is never filed again, so names sent
     * only with the groups in use would leave the rest of the year unnamed. They
     * are sorted, so the same chart always produces the same bytes.
     *
     * @param  array<string, string>  $names  two-digit group => name
     */
    public function groupNames(array $names): self
    {
        foreach ($names as $group => $name) {
            if (preg_match('/^\d{2}$/', (string) $group) !== 1) {
                throw new ContractException(sprintf('group_names is keyed by two-digit OHADA group, got "%s".', (string) $group));
            }

            $name = is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : '';

            if ($name === '' || mb_strlen($name) > self::GROUP_NAME_MAX) {
                throw new ContractException(sprintf('group_names["%s"] must be a name of 1 to %d characters.', (string) $group, self::GROUP_NAME_MAX));
            }

            $this->groupNames[(string) $group] = $name;
        }

        return $this;
    }

    /**
     * @param  array{0_30: Money, 31_60: Money, 61_90: Money, 90_plus: Money}  $ageing
     */
    public function receivables(Money $total, array $ageing): self
    {
        $this->financial['receivables'] = ['total' => $total, 'ageing' => self::assertAgeing($ageing, 'receivables')];

        return $this->declare(Capability::RECEIVABLES);
    }

    /**
     * @param  array{0_30: Money, 31_60: Money, 61_90: Money, 90_plus: Money}  $ageing
     */
    public function payables(Money $total, array $ageing): self
    {
        $this->financial['payables'] = ['total' => $total, 'ageing' => self::assertAgeing($ageing, 'payables')];

        return $this->declare(Capability::PAYABLES);
    }

    public function budget(Money $budgeted, Money $actual): self
    {
        $this->financial['budget'] = ['budgeted' => $budgeted, 'actual' => $actual];

        return $this->declare(Capability::BUDGET);
    }

    /**
     * Staff cost for the month and the statutory liabilities standing against it.
     *
     * Statutory liabilities (CNPS, tax withheld) are carried separately because
     * an institution that is paying salaries but not remitting withholdings is a
     * specific and common failure, and it is invisible in a staff-cost total.
     */
    public function payroll(Money $staffCost, Money $statutoryLiabilities): self
    {
        $this->financial['payroll'] = [
            'staff_cost' => $staffCost,
            'statutory_liabilities' => $statutoryLiabilities,
        ];

        return $this->declare(Capability::PAYROLL);
    }

    /**
     * Reconciliation against a source the bursar does not author.
     *
     * The highest-value control in the platform (brief §11): it is the only one
     * that compares the ledger against something outside the bursar's reach.
     */
    public function bankRecon(Money $statementClosing, Money $ledgerClosing, string $statementRef): self
    {
        $this->financial['bank_recon'] = [
            'statement_closing' => $statementClosing,
            'ledger_closing' => $ledgerClosing,
            'divergence' => $statementClosing->subtract($ledgerClosing),
            'statement_ref' => $statementRef,
        ];

        return $this->declare(Capability::BANK_RECON);
    }

    /**
     * Enrolment and fee collection.
     *
     * expected_fees is the anti-fraud figure: enrolment multiplied by the fee
     * structure the BODY set, not a number the institution chose. Compared
     * against collected, it turns "do we trust this report" into arithmetic.
     *
     * cash_share — the proportion taken as physical cash — is the exposure
     * measure for interception (fraud model C).
     *
     * @param  string  $collectionRate  decimal string, e.g. "0.50" (never a float)
     * @param  string  $cashShare       decimal string, e.g. "0.31"
     */
    public function enrolment(
        int $enrolment,
        Money $expectedFees,
        Money $collected,
        string $collectionRate,
        string $cashShare
    ): self {
        if ($enrolment < 0) {
            throw new ContractException('Enrolment cannot be negative.');
        }

        self::assertRate($collectionRate, 'collection_rate');
        self::assertRate($cashShare, 'cash_share');

        $this->operational['enrolment'] = $enrolment;
        $this->operational['expected_fees'] = $expectedFees;
        $this->operational['collected'] = $collected;
        $this->operational['collection_rate'] = $collectionRate;
        $this->operational['cash_share'] = $cashShare;

        return $this->declare(Capability::ENROLMENT);
    }

    /**
     * What this institution is actually charging, per class and fee.
     *
     * The console compares this with the fee schedule the body set. No student is
     * named: a row is a class, a fee, an amount and how many pay it. Two rows for
     * the same class and fee mean people in one class pay different amounts, which
     * is worth seeing in itself.
     *
     * @param  array<int, array{level: string, category: string, amount: Money, students: int}>  $rows
     */
    public function feesCharged(string $session, array $rows): self
    {
        if ($rows === []) {
            throw new ContractException('Declaring fees_charged means supplying at least one fee.');
        }

        foreach ($rows as $row) {
            if (! ($row['amount'] instanceof Money) || trim((string) $row['level']) === '' || trim((string) $row['category']) === '') {
                throw new ContractException('Each fee needs a level, a category and a Money amount.');
            }
        }

        // Sorted so the same fees always serialise, and sign, the same way.
        usort($rows, fn (array $a, array $b) => [$a['level'], $a['category'], $a['amount']->value()]
            <=> [$b['level'], $b['category'], $b['amount']->value()]);

        $this->operational['fees_charged'] = ['session' => $session, 'rows' => array_values($rows)];

        return $this->declare(Capability::FEES_CHARGED);
    }

    /**
     * How many students the institution has in the session the month falls in,
     * class by class. Since contract 1.3.
     *
     * A row is a class name and a count; no student is named. Use the same class
     * names as feesCharged(), so the console can set the two side by side. The
     * total is the sum of the classes, worked out here so the two never disagree.
     *
     * @param  array<int, array{level: string, students: int}>  $rows
     */
    public function students(string $session, array $rows): self
    {
        $session = self::cleanText($session);

        if ($session === '' || mb_strlen($session) > self::TEXT_MAX) {
            throw new ContractException(sprintf('students needs a session name of 1 to %d characters.', self::TEXT_MAX));
        }

        $clean = [];

        foreach ($rows as $row) {
            $level = self::cleanText((string) ($row['level'] ?? ''));
            $students = $row['students'] ?? null;

            if ($level === '' || mb_strlen($level) > self::TEXT_MAX || ! is_int($students) || $students < 0) {
                throw new ContractException('Each class needs a name of 1 to '.self::TEXT_MAX.' characters and a whole number of students.');
            }

            if (isset($clean[$level])) {
                throw new ContractException(sprintf('The class "%s" appears twice. Add its students together.', $level));
            }

            $clean[$level] = ['level' => $level, 'students' => $students];
        }

        ksort($clean, SORT_STRING);

        $this->operational['students'] = [
            'session' => $session,
            'total' => array_sum(array_column($clean, 'students')),
            'by_class' => array_values($clean),
        ];

        return $this->declare(Capability::STUDENTS);
    }

    /**
     * The staff on the books at the end of the month. Since contract 1.3.
     *
     * Each person is their staff number, name, department and post, and nothing
     * else: no pay, no contact details. The counts by department and by post
     * are worked out here from the same list, so the two can never disagree.
     * A department or post the school has not recorded is sent as null.
     *
     * @param  array<int, array{number: ?string, name: string, department: ?string, designation: ?string}>  $people
     */
    public function staff(array $people): self
    {
        $clean = [];

        foreach ($people as $person) {
            $name = self::cleanText((string) ($person['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > self::TEXT_MAX) {
                throw new ContractException('Each member of staff needs a name of 1 to '.self::TEXT_MAX.' characters.');
            }

            $entry = ['name' => $name];

            foreach (['number', 'department', 'designation'] as $key) {
                $value = isset($person[$key]) ? self::cleanText((string) $person[$key]) : '';

                if (mb_strlen($value) > self::TEXT_MAX) {
                    throw new ContractException(sprintf('A staff %s can be at most %d characters.', $key, self::TEXT_MAX));
                }

                $entry[$key] = $value === '' ? null : $value;
            }

            ksort($entry);
            $clean[] = $entry;
        }

        // A fixed order, so the same staff always serialise, and sign, the same way.
        usort($clean, fn (array $a, array $b) => [$a['name'], (string) $a['number'], (string) $a['department'], (string) $a['designation']]
            <=> [$b['name'], (string) $b['number'], (string) $b['department'], (string) $b['designation']]);

        $this->operational['staff'] = [
            'total' => count($clean),
            'by_department' => self::countBy($clean, 'department'),
            'by_designation' => self::countBy($clean, 'designation'),
            'people' => $clean,
        ];

        return $this->declare(Capability::STAFF);
    }

    /**
     * The fee position for the academic year the month falls in, as at the end
     * of the month. Since contract 1.4.
     *
     * A row is one class and one fee (the same class names as students() and
     * feesCharged()), with four amounts summed over that class's fees of that
     * kind: expected (billed, net of discounts and fines), collected, owed and
     * overpaid. Owed and overpaid are worked out fee by fee BEFORE summing, so
     * one student's overpayment never hides another's debt; on every row,
     * expected - collected = owed - overpaid. The totals are added up here from
     * the rows, so the two can never disagree.
     *
     * $students counts students, not fees: paid (owes nothing for the year),
     * part_paid, not_paid (owes, and has paid nothing), and not_charged
     * (enrolled in the year, no fee at all). No student is named.
     *
     * A year with nothing billed yet is sent with no rows and zero totals: that
     * is a true zero, not a missing figure.
     *
     * @param  array<int, array{level: string, category: string, expected: Money, collected: Money, owed: Money, overpaid: Money}>  $rows
     * @param  array{paid: int, part_paid: int, not_paid: int, not_charged: int}  $students
     */
    public function fees(string $session, array $rows, array $students): self
    {
        $session = self::cleanText($session);

        if ($session === '' || mb_strlen($session) > self::TEXT_MAX) {
            throw new ContractException(sprintf('fees needs a session name of 1 to %d characters.', self::TEXT_MAX));
        }

        $counts = [];

        foreach (self::FEE_STUDENT_COUNTS as $key) {
            $value = $students[$key] ?? null;

            if (! is_int($value) || $value < 0) {
                throw new ContractException(sprintf('fees needs a whole number of students for "%s".', $key));
            }

            $counts[$key] = $value;
        }

        if (array_diff(array_keys($students), self::FEE_STUDENT_COUNTS) !== []) {
            throw new ContractException('fees counts students as paid, part_paid, not_paid and not_charged, and nothing else.');
        }

        $clean = [];
        $totals = array_fill_keys(self::FEE_AMOUNTS, Money::zero());

        foreach ($rows as $row) {
            $level = self::cleanText((string) ($row['level'] ?? ''));
            $category = self::cleanText((string) ($row['category'] ?? ''));

            if ($level === '' || $category === '' || mb_strlen($level) > self::TEXT_MAX || mb_strlen($category) > self::TEXT_MAX) {
                throw new ContractException('Each fee row needs a class and a fee name of 1 to '.self::TEXT_MAX.' characters.');
            }

            $key = $level."\x1F".$category;

            if (isset($clean[$key])) {
                throw new ContractException(sprintf('The fee "%s" for "%s" appears twice. Add its amounts together.', $category, $level));
            }

            $entry = ['category' => $category, 'level' => $level];

            foreach (self::FEE_AMOUNTS as $amount) {
                if (! (($row[$amount] ?? null) instanceof Money)) {
                    throw new ContractException(sprintf('Each fee row needs "%s" as Money.', $amount));
                }

                if ($row[$amount]->isNegative()) {
                    throw new ContractException(sprintf('"%s" cannot be negative, for "%s" in "%s".', $amount, $category, $level));
                }

                $entry[$amount] = $row[$amount];
            }

            if (! $entry['expected']->subtract($entry['collected'])->equals($entry['owed']->subtract($entry['overpaid']))) {
                throw new ContractException(sprintf(
                    'For "%s" in "%s", expected less collected must equal owed less overpaid.',
                    $category,
                    $level
                ));
            }

            foreach (self::FEE_AMOUNTS as $amount) {
                $totals[$amount] = $totals[$amount]->add($entry[$amount]);
            }

            ksort($entry);
            $clean[$key] = $entry;
        }

        ksort($clean, SORT_STRING);

        $this->operational['fees'] = [
            'collected' => $totals['collected'],
            'expected' => $totals['expected'],
            'overpaid' => $totals['overpaid'],
            'owed' => $totals['owed'],
            'rows' => array_values($clean),
            'session' => $session,
            'students' => $counts,
        ];

        return $this->declare(Capability::FEES);
    }

    /** The four amounts on every fee row and in the fee totals. */
    public const FEE_AMOUNTS = ['expected', 'collected', 'owed', 'overpaid'];

    /** How the fee part counts students. */
    public const FEE_STUDENT_COUNTS = ['paid', 'part_paid', 'not_paid', 'not_charged'];

    /**
     * @param  list<array<string, ?string>>  $people
     * @return list<array{name: ?string, staff: int}>  by name, with "not recorded" (null) last
     */
    private static function countBy(array $people, string $key): array
    {
        $counts = [];

        foreach ($people as $person) {
            $counts[$person[$key] ?? ''] = ($counts[$person[$key] ?? ''] ?? 0) + 1;
        }

        $unset = $counts[''] ?? null;
        unset($counts['']);
        ksort($counts, SORT_STRING);

        $rows = [];
        foreach ($counts as $name => $staff) {
            $rows[] = ['name' => (string) $name, 'staff' => $staff];
        }

        if ($unset !== null) {
            $rows[] = ['name' => null, 'staff' => $unset];
        }

        return $rows;
    }

    private static function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Tamper-evidence for the period.
     *
     * ledger_hash is taken over the period's journal lines, so the console can
     * tell that a period's underlying detail changed even when the totals did
     * not — which is exactly how fraud model B looks from the outside.
     *
     * On backdated_entries: count entries whose entry_date precedes the date
     * they were created, EXCLUDING reversals. paxhitestbed deliberately dates a
     * reversal with the original entry's date when that period is still open
     * (TransactionAutoMapService::reversalPlacement), so counting reversals here
     * would make the finding fire constantly on correct behaviour and train
     * everyone to ignore it.
     */
    public function integrity(string $ledgerHash, int $auditEvents, int $reversals, int $backdatedEntries): self
    {
        foreach (['audit_events' => $auditEvents, 'reversals' => $reversals, 'backdated_entries' => $backdatedEntries] as $key => $count) {
            if ($count < 0) {
                throw new ContractException(sprintf('integrity.%s cannot be negative.', $key));
            }
        }

        if (preg_match('/^sha256:[0-9a-f]{64}$/', $ledgerHash) !== 1) {
            throw new ContractException(sprintf(
                'ledger_hash must look like "sha256:<64 hex chars>", got "%s".',
                $ledgerHash
            ));
        }

        $this->integrity = [
            'ledger_hash' => $ledgerHash,
            'audit_events' => $auditEvents,
            'reversals' => $reversals,
            'backdated_entries' => $backdatedEntries,
        ];

        return $this->declare(Capability::INTEGRITY);
    }

    /**
     * Assemble the payload.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        if ($this->declared === []) {
            throw new CapabilityMismatchException(
                'A report must declare at least one capability. An institution with nothing to '
                .'report should send a heartbeat instead — silence and "nothing happened" are '
                .'different facts, and only one of them is an alarm.'
            );
        }

        $capabilities = array_values(array_filter(
            Capability::all(),
            fn (string $c): bool => isset($this->declared[$c])
        ));

        $payload = [
            'contract_version' => ContractVersion::CURRENT,
            'institution_ref' => $this->institutionRef,
            'period' => $this->period,
            'status' => $this->status,
            'sequence' => $this->sequence,
            'generated_at' => $this->generatedAt,
            'currency' => $this->currency,
            'capabilities' => $capabilities,
        ];

        if ($this->groupNames !== [] && ! isset($this->declared[Capability::LEDGER])) {
            throw new CapabilityMismatchException('Group names describe the ledger, so they need the ledger to be reported too.');
        }

        $financial = $this->financial;

        if ($this->groupNames !== []) {
            $names = $this->groupNames;
            ksort($names, SORT_STRING);
            $financial['group_names'] = self::asMap($names);
        }

        if ($financial !== []) {
            $payload['financial'] = $financial;
        }

        if ($this->operational !== []) {
            $payload['operational'] = $this->operational;
        }

        if ($this->integrity !== null) {
            $payload['integrity'] = $this->integrity;
        }

        // Belt and braces: prove the invariant actually holds on the way out,
        // rather than trusting that every method above maintained it.
        Validator::assertCapabilityCoupling($payload);

        return $payload;
    }

    /**
     * Build and serialise in one step.
     */
    public function toJson(): string
    {
        return Canonical::encode($this->build());
    }

    private function declare(string $capability): self
    {
        $this->declared[$capability] = true;

        return $this;
    }

    private static function assertPeriod(string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new ContractException(sprintf(
                'Period must be a calendar month "YYYY-MM", got "%s". The unit is always the '
                .'calendar month, never a fiscal year: paxhitestbed runs January-December and the '
                .'schools run July-June, so a body holds both. Institutions report months and the '
                .'console assembles any fiscal year from them.',
                $period
            ));
        }
    }

    /**
     * @param  array<string, Money>  $map
     */
    private static function assertGroupMap(array $map, string $label): void
    {
        foreach ($map as $group => $amount) {
            if (preg_match('/^\d{2}$/', (string) $group) !== 1) {
                throw new ContractException(sprintf(
                    '%s is keyed by two-digit OHADA group, got "%s".',
                    $label,
                    (string) $group
                ));
            }

            if (! $amount instanceof Money) {
                throw new ContractException(sprintf('%s["%s"] must be a Money instance.', $label, (string) $group));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $ageing
     * @return array<string, Money>
     */
    private static function assertAgeing(array $ageing, string $label): array
    {
        self::assertShape($ageing, ['0_30', '31_60', '61_90', '90_plus'], $label.'.ageing');
        self::assertMoneyValues($ageing, $label.'.ageing');

        return $ageing;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  list<string>  $required
     */
    private static function assertShape(array $actual, array $required, string $label): void
    {
        $missing = array_diff($required, array_keys($actual));

        if ($missing !== []) {
            throw new ContractException(sprintf('%s is missing: %s.', $label, implode(', ', $missing)));
        }

        $unexpected = array_diff(array_keys($actual), $required);

        if ($unexpected !== []) {
            throw new ContractException(sprintf('%s has unexpected keys: %s.', $label, implode(', ', $unexpected)));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function assertMoneyValues(array $values, string $label): void
    {
        foreach ($values as $key => $value) {
            if (! $value instanceof Money) {
                throw new ContractException(sprintf('%s.%s must be a Money instance.', $label, (string) $key));
            }
        }
    }

    private static function assertRate(string $rate, string $label): void
    {
        if (preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', $rate) !== 1) {
            throw new ContractException(sprintf(
                '%s must be a decimal string between "0" and "1", e.g. "0.50" — not a float. Got "%s".',
                $label,
                $rate
            ));
        }
    }

    /**
     * Mark an OHADA group map as a map, unconditionally.
     *
     * Two failure modes this closes, both silent:
     *
     *   - A month with genuinely no income in any group would serialise as []
     *     rather than {}, and a truthful report would fail schema validation.
     *   - PHP cannot tell ['0' => x, '1' => y] from a list, so a group map whose
     *     keys happened to look list-shaped would encode as a JSON array and the
     *     group codes would vanish. OHADA groups run "10".."99" so this cannot
     *     bite today, but the contract should not depend on that staying true.
     *
     * @param  array<string, Money>  $map
     */
    private static function asMap(array $map): \stdClass
    {
        return (object) $map;
    }
}
