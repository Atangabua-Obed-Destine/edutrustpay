<?php

declare(strict_types=1);

namespace EdutrustPay\Contract\Tests;

use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Exceptions\CapabilityMismatchException;
use EdutrustPay\Contract\Exceptions\ContractException;
use EdutrustPay\Contract\Exceptions\InvalidAmountException;
use EdutrustPay\Contract\Fingerprint;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\PayloadBuilder;
use EdutrustPay\Contract\Signer;
use EdutrustPay\Contract\TestVectors;
use EdutrustPay\Contract\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The contract's own test suite.
 *
 * Roughly in order of how expensive the failure would be if it slipped through:
 * the fixture signatures first (silent cross-codebase drift), then the
 * missing-vs-zero rule, then the accept-don't-reject judgement, then money.
 */
final class ContractTest extends TestCase
{
    // ---------------------------------------------------------------
    // Test vectors — the cross-codebase contract
    // ---------------------------------------------------------------

    #[DataProvider('fixtures')]
    public function test_fixture_canonicalises_to_its_pinned_bytes(string $name): void
    {
        $fixture = $this->fixture($name);

        $this->assertSame(
            $fixture['canonical_json'],
            Canonical::encode($fixture['payload']),
            "Fixture '$name' no longer canonicalises to its pinned bytes. If this was intentional, "
            ."the contract version must be bumped — four codebases sign against these bytes."
        );
    }

    #[DataProvider('fixtures')]
    public function test_fixture_signs_to_its_pinned_signature(string $name): void
    {
        $fixture = $this->fixture($name);

        $this->assertSame(
            $fixture['signing']['signature'],
            Signer::signRaw($fixture['canonical_json'], $fixture['signing']['timestamp'], $fixture['signing']['secret']),
            "Fixture '$name' no longer produces its pinned signature."
        );
    }

    #[DataProvider('fixtures')]
    public function test_fixture_is_structurally_valid(string $name): void
    {
        $result = (new Validator())->validate($this->fixture($name)['payload']);

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['valid']);
    }

    #[DataProvider('fixtures')]
    public function test_fixture_raises_exactly_its_expected_warnings(string $name): void
    {
        $fixture = $this->fixture($name);
        $result = (new Validator())->validate($fixture['payload']);

        $this->assertSame(
            $fixture['expected_warnings'],
            array_column($result['warnings'], 'code'),
            "Fixture '$name' changed which warnings it raises. Those warnings become Findings."
        );
    }

    #[DataProvider('fixtures')]
    public function test_fixture_canonical_form_is_stable_under_round_trip(string $name): void
    {
        $json = $this->fixture($name)['canonical_json'];

        $this->assertTrue(Canonical::isCanonical($json));
        $this->assertSame($json, Canonical::encode(Canonical::decode($json)));
    }

    // ---------------------------------------------------------------
    // The rule the platform rests on: missing is not zero
    // ---------------------------------------------------------------

    public function test_absent_capability_and_zero_figure_are_different_documents(): void
    {
        $absent = $this->fixture('ledger-only')['payload'];
        $zero = $this->fixture('genuine-zeros')['payload'];

        // Cannot report receivables at all.
        $this->assertNotContains(Capability::RECEIVABLES, $absent['capabilities']);
        $this->assertArrayNotHasKey('receivables', $absent['financial']);

        // Can report receivables, and they are genuinely nil.
        $this->assertContains(Capability::RECEIVABLES, $zero['capabilities']);
        $this->assertSame('0.00', $zero['financial']['receivables']['total']);
    }

    public function test_declaring_a_capability_without_its_figures_is_refused(): void
    {
        $payload = $this->fixture('ledger-only')['payload'];
        $payload['capabilities'][] = Capability::RECEIVABLES;

        $result = (new Validator())->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('is declared but', $result['errors'][0]);
    }

    public function test_supplying_figures_without_declaring_the_capability_is_refused(): void
    {
        $payload = $this->fixture('ledger-only')['payload'];
        $payload['financial']['budget'] = ['budgeted' => '1000.00', 'actual' => '900.00'];

        $result = (new Validator())->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('is not declared', $result['errors'][0]);
    }

    public function test_builder_cannot_produce_a_payload_that_conflates_missing_with_zero(): void
    {
        // There is simply no way to express it: declaring receivables IS
        // supplying them. The closest a caller can get is an empty report.
        $this->expectException(CapabilityMismatchException::class);

        PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-08')->build();
    }

    // ---------------------------------------------------------------
    // Fees charged: what the body compares with its own schedule
    // ---------------------------------------------------------------

    public function test_fees_charged_is_valid_sorted_and_stated_as_strings(): void
    {
        $payload = PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-10')
            ->status('provisional')
            ->sequence(1)
            ->generatedAt('2026-11-01T00:00:00Z')
            ->currency('XAF')
            ->feesCharged('2026/2027', [
                ['level' => 'Form 2', 'category' => 'Tuition', 'amount' => Money::of('210000.00'), 'students' => 12],
                ['level' => 'Form 1', 'category' => 'Tuition', 'amount' => Money::of('200000.00'), 'students' => 30],
            ])
            ->build();

        $this->assertContains(Capability::FEES_CHARGED, $payload['capabilities']);
        $this->assertTrue((new Validator())->validate($payload)['valid']);

        $rows = Canonical::decode(Canonical::encode($payload))['operational']['fees_charged']['rows'];

        $this->assertSame('Form 1', $rows[0]['level'], 'The same fees must always sign the same way.');
        $this->assertSame('200000.00', $rows[0]['amount']);
    }

    public function test_a_change_in_fees_refiles_an_open_month_but_not_a_closed_one(): void
    {
        $report = fn (string $status, string $amount) => PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-10')
            ->status($status)
            ->sequence(1)
            ->generatedAt('2026-11-01T00:00:00Z')
            ->currency('XAF')
            ->feesCharged('2026/2027', [
                ['level' => 'Form 1', 'category' => 'Tuition', 'amount' => Money::of($amount), 'students' => 30],
            ])
            ->build();

        $filed = fn (string $status) => Canonical::encode($report($status, '200000.00'));

        $this->assertFalse(Fingerprint::needsRefiling($filed('provisional'), $report('provisional', '200000.00')));
        $this->assertTrue(Fingerprint::needsRefiling($filed('provisional'), $report('provisional', '250000.00')));
        $this->assertFalse(Fingerprint::needsRefiling($filed('final'), $report('final', '250000.00')));
    }

    public function test_declaring_fees_charged_with_no_fees_is_refused(): void
    {
        // Saying "we charge nothing" and "we cannot say" are different facts.
        $this->expectException(ContractException::class);

        PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-10')->feesCharged('2026/2027', []);
    }

    // ---------------------------------------------------------------
    // Group names: the school's own words for its own account groups
    // ---------------------------------------------------------------

    private function namedLedger(array $names, string $status = 'provisional', string $income = '100000.00'): array
    {
        $m = fn (string $v) => Money::of($v);

        return PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-10')
            ->status($status)
            ->sequence(1)
            ->generatedAt('2026-11-01T00:00:00Z')
            ->currency('XAF')
            ->ledger(
                ['71' => $m($income)],
                ['60' => $m('40000.00')],
                $m($income)->subtract($m('40000.00')),
                ['opening' => $m('0.00'), 'movement' => $m($income)->subtract($m('40000.00')), 'closing' => $m($income)->subtract($m('40000.00'))],
                ['total_debits' => $m('140000.00'), 'total_credits' => $m('140000.00'), 'journal_entry_count' => 2, 'unposted_count' => 0]
            )
            ->groupNames($names)
            ->build();
    }

    public function test_group_names_cover_the_schools_whole_chart_in_a_fixed_order(): void
    {
        // 79 has no amount this month, and is still named: a closed month is
        // never filed again, so this is how its earlier use gets a name.
        $payload = $this->namedLedger(['79' => 'Reprises de provisions', '71' => '  Scolarites ', '60' => 'Achats']);

        $names = Canonical::decode(Canonical::encode($payload))['financial']['group_names'];

        $this->assertSame(['60' => 'Achats', '71' => 'Scolarites', '79' => 'Reprises de provisions'], $names);
        $this->assertTrue((new Validator())->validate(Canonical::decode(Canonical::encode($payload)))['valid']);
    }

    public function test_a_new_group_name_refiles_an_open_month_but_never_a_closed_one(): void
    {
        // The day a school starts sending names, its closed months must not all
        // look changed: each refile of a closed month raises a finding.
        $filed = Canonical::encode($this->namedLedger(['71' => 'Scolarites']));
        $filedFinal = Canonical::encode($this->namedLedger(['71' => 'Scolarites'], 'final'));

        $this->assertFalse(Fingerprint::needsRefiling($filed, $this->namedLedger(['71' => 'Scolarites'])));
        $this->assertTrue(Fingerprint::needsRefiling($filed, $this->namedLedger(['71' => 'Frais de scolarite'])));
        $this->assertFalse(Fingerprint::needsRefiling($filedFinal, $this->namedLedger(['71' => 'Frais de scolarite'], 'final')));

        // And the console's "a closed month was changed" check sees no change.
        $this->assertSame(Fingerprint::ofJson($filedFinal), Fingerprint::of($this->namedLedger(['71' => 'Frais de scolarite'], 'final')));
    }

    public function test_group_names_are_refused_on_an_older_contract_or_when_malformed(): void
    {
        $payload = Canonical::decode(Canonical::encode($this->namedLedger(['71' => 'Scolarites'])));

        $older = $payload;
        $older['contract_version'] = '1.1';
        $this->assertFalse((new Validator())->validate($older)['valid']);

        $stray = $payload;
        $stray['financial']['group_names']['7'] = 'Produits';
        $this->assertFalse((new Validator())->validate($stray)['valid']);

        $blank = $payload;
        $blank['financial']['group_names']['71'] = ' ';
        $this->assertFalse((new Validator())->validate($blank)['valid']);
    }

    public function test_group_names_need_the_ledger(): void
    {
        $this->expectException(CapabilityMismatchException::class);

        PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-10')
            ->status('provisional')->sequence(1)->generatedAt('2026-11-01T00:00:00Z')->currency('XAF')
            ->feesCharged('2026/2027', [['level' => 'Form 1', 'category' => 'Tuition', 'amount' => Money::of('200000.00'), 'students' => 1]])
            ->groupNames(['71' => 'Scolarites'])
            ->build();
    }

    public function test_a_report_on_the_previous_contract_version_is_still_valid(): void
    {
        // Schools upgrade when they can.
        $payload = $this->fixture('full-capability')['payload'];
        unset($payload['financial']['group_names']);
        $payload['contract_version'] = '1.0';

        $this->assertTrue((new Validator())->validate($payload)['valid']);
    }

    // ---------------------------------------------------------------
    // Accept and warn, do not reject
    // ---------------------------------------------------------------

    public function test_an_unbalanced_ledger_is_accepted_so_that_it_can_raise_a_finding(): void
    {
        $result = (new Validator())->validate($this->fixture('unbalanced-ledger')['payload']);

        // The judgement that matters: this report is ACCEPTED. Refusing it would
        // make the institution look silent rather than alarming, and those get
        // very different responses from a bursary head.
        $this->assertTrue($result['valid'], 'An unbalanced ledger must be accepted at ingest, not rejected.');
        $this->assertContains('unbalanced_ledger', array_column($result['warnings'], 'code'));
    }

    public function test_ageing_buckets_that_do_not_sum_to_their_total_are_accepted_and_warned(): void
    {
        $payload = $this->fixture('full-capability')['payload'];
        $payload['financial']['receivables']['ageing']['90_plus'] = '999.00';

        $result = (new Validator())->validate($payload);

        $this->assertTrue($result['valid']);
        $this->assertContains('ageing_inconsistent', array_column($result['warnings'], 'code'));
    }

    public function test_cash_that_does_not_reconcile_is_accepted_and_warned(): void
    {
        $payload = $this->fixture('ledger-only')['payload'];
        $payload['financial']['cash']['closing'] = '9999999.00';

        $result = (new Validator())->validate($payload);

        $this->assertTrue($result['valid']);
        $this->assertContains('cash_discontinuity', array_column($result['warnings'], 'code'));
    }

    public function test_a_surplus_that_disagrees_with_the_groups_is_accepted_and_warned(): void
    {
        $payload = $this->fixture('ledger-only')['payload'];
        $payload['financial']['surplus_deficit'] = '123456.00';

        $result = (new Validator())->validate($payload);

        $this->assertTrue($result['valid']);
        $this->assertContains('surplus_mismatch', array_column($result['warnings'], 'code'));
    }

    // ---------------------------------------------------------------
    // Signing
    // ---------------------------------------------------------------

    public function test_signature_covers_the_timestamp_so_the_replay_window_cannot_be_moved(): void
    {
        $json = $this->fixture('full-capability')['canonical_json'];
        $signature = Signer::signRaw($json, '2026-09-02T08:15:00Z', 'secret');

        $this->assertTrue(Signer::verifyRaw($json, '2026-09-02T08:15:00Z', $signature, 'secret'));
        $this->assertFalse(
            Signer::verifyRaw($json, '2026-09-02T09:15:00Z', $signature, 'secret'),
            'Rewriting the timestamp must invalidate the signature, or replay protection is defeated.'
        );
    }

    public function test_a_tampered_figure_invalidates_the_signature(): void
    {
        $json = $this->fixture('full-capability')['canonical_json'];
        $signature = Signer::signRaw($json, TestVectors::TIMESTAMP, 'secret');
        $tampered = str_replace('"4200000.00"', '"9200000.00"', $json);

        $this->assertNotSame($json, $tampered);
        $this->assertFalse(Signer::verifyRaw($tampered, TestVectors::TIMESTAMP, $signature, 'secret'));
    }

    public function test_the_wrong_secret_does_not_verify(): void
    {
        $json = $this->fixture('full-capability')['canonical_json'];
        $signature = Signer::signRaw($json, TestVectors::TIMESTAMP, 'right');

        $this->assertFalse(Signer::verifyRaw($json, TestVectors::TIMESTAMP, $signature, 'wrong'));
    }

    public function test_signing_with_an_empty_secret_is_refused(): void
    {
        $this->expectException(ContractException::class);

        Signer::signRaw('{}', TestVectors::TIMESTAMP, '');
    }

    public function test_stale_and_future_timestamps_are_both_rejected(): void
    {
        $now = strtotime('2026-09-02T08:15:00Z');

        $this->assertTrue(Signer::timestampIsFresh('2026-09-02T08:15:00Z', $now));
        $this->assertTrue(Signer::timestampIsFresh('2026-09-02T08:12:00Z', $now));
        $this->assertFalse(Signer::timestampIsFresh('2026-09-02T07:00:00Z', $now), 'Too old.');
        $this->assertFalse(Signer::timestampIsFresh('2026-09-02T09:30:00Z', $now), 'A clock set forward is a problem too.');
        $this->assertFalse(Signer::timestampIsFresh('not a timestamp', $now));
    }

    // ---------------------------------------------------------------
    // Canonical serialisation
    // ---------------------------------------------------------------

    public function test_key_order_does_not_affect_the_bytes(): void
    {
        $this->assertSame(
            Canonical::encode(['b' => 1, 'a' => ['z' => 2, 'y' => 3]]),
            Canonical::encode(['a' => ['y' => 3, 'z' => 2], 'b' => 1])
        );
    }

    public function test_a_map_never_degrades_into_a_json_array(): void
    {
        // PHP cannot tell ['0' => 'a', '1' => 'b'] from a list, which is why the
        // caller marks maps with stdClass and PayloadBuilder always does so.
        $this->assertSame('{"m":["a","b"]}', json_encode(['m' => ['0' => 'a', '1' => 'b']]));
        $this->assertSame('{"m":{"0":"a","1":"b"}}', Canonical::encode(['m' => (object) ['0' => 'a', '1' => 'b']]));
    }

    public function test_an_empty_group_map_serialises_as_an_object(): void
    {
        $json = $this->fixture('empty-groups')['canonical_json'];

        $this->assertStringContainsString('"income_by_group":{}', $json);
        $this->assertStringNotContainsString('"income_by_group":[]', $json);
    }

    public function test_a_genuine_list_stays_a_list(): void
    {
        $this->assertSame('{"l":["a","b"]}', Canonical::encode(['l' => ['a', 'b']]));
    }

    public function test_floats_cannot_reach_the_wire(): void
    {
        $this->expectException(ContractException::class);
        $this->expectExceptionMessageMatches('/Float found/');

        Canonical::encode(['amount' => 1040000.0]);
    }

    // ---------------------------------------------------------------
    // Money
    // ---------------------------------------------------------------

    public function test_money_refuses_floats(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::of(4200000.50);
    }

    public function test_money_refuses_silent_rounding(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::of('1.234');
    }

    public function test_from_numeric_rounds_half_up_because_that_is_what_the_statements_do(): void
    {
        $this->assertSame('2.35', Money::fromNumeric(2.345)->value());
        $this->assertSame('2.45', Money::fromNumeric(2.445)->value());
        $this->assertSame('0.30', Money::fromNumeric(0.1 + 0.2)->value());
    }

    public function test_negative_zero_normalises_so_equal_amounts_always_share_bytes(): void
    {
        $this->assertSame('0.00', Money::of('5.00')->subtract(Money::of('5.00'))->value());
    }

    public function test_arithmetic_is_exact_at_scale(): void
    {
        $total = Money::sum([Money::of('0.01'), Money::of('0.02'), Money::of('0.03')]);

        $this->assertSame('0.06', $total->value());
        $this->assertTrue($total->equals(Money::of('0.06')));
    }

    public function test_large_amounts_do_not_lose_precision(): void
    {
        // Well beyond a float's exact integer range, and entirely plausible in
        // XAF, where a modest annual budget runs to hundreds of millions.
        $this->assertSame(
            '99999999999999.99',
            Money::of('99999999999999.98')->add(Money::of('0.01'))->value()
        );
    }

    // ---------------------------------------------------------------
    // Envelope
    // ---------------------------------------------------------------

    public function test_the_period_must_be_a_calendar_month(): void
    {
        $this->expectException(ContractException::class);

        // A fiscal year is exactly what must not be reportable: paxhitestbed runs
        // January-December and the schools run July-June.
        PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026');
    }

    public function test_money_sent_as_a_json_number_is_refused(): void
    {
        $payload = $this->fixture('ledger-only')['payload'];
        $payload['financial']['surplus_deficit'] = 600000;

        $result = (new Validator())->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be a decimal STRING', $result['errors'][0]);
    }

    public function test_an_unknown_capability_is_refused(): void
    {
        $payload = $this->fixture('ledger-only')['payload'];
        $payload['capabilities'][] = 'telepathy';

        $result = (new Validator())->validate($payload);

        $this->assertFalse($result['valid']);
    }

    public function test_a_business_institution_has_no_enrolment_capability(): void
    {
        $this->assertNotContains(Capability::ENROLMENT, Capability::applicableTo('business'));
        $this->assertNotContains(Capability::ENROLMENT, Capability::applicableTo('health'));
        $this->assertNotContains(Capability::FEES_CHARGED, Capability::applicableTo('health'));
        $this->assertContains(Capability::ENROLMENT, Capability::applicableTo('school_primary'));
        $this->assertContains(Capability::ENROLMENT, Capability::applicableTo('school_secondary'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array<int, array{0: string}>
     */
    public static function fixtures(): array
    {
        $index = json_decode((string) file_get_contents(__DIR__.'/../fixtures/index.json'), true);

        return array_map(static fn (string $name): array => [$name], array_keys($index));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $document = json_decode((string) file_get_contents(__DIR__.'/../fixtures/'.$name.'.json'), true);

        // Take the payload from the pinned canonical bytes rather than from the
        // fixture file's convenience copy, and decode it the way the ingest API
        // does. A plain json_decode(assoc) would turn {} into [] and quietly
        // destroy the empty-map distinction before the test could check it.
        $document['payload'] = Canonical::decode($document['canonical_json']);

        return $document;
    }
}
