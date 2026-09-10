<?php

declare(strict_types=1);

/**
 * Regenerates the contract test vectors in fixtures/.
 *
 * Run:  php tools/generate-fixtures.php
 *
 * Each fixture pins a payload, its canonical byte string, and the signature that
 * results from signing those bytes with the published test secret. Any client in
 * any of the four codebases can build the same payload and compare — if the
 * bytes differ by one character the signature differs completely, and the test
 * fails loudly at build time instead of quietly in production at month end.
 *
 * REGENERATE ONLY WHEN THE CONTRACT ITSELF CHANGES, and bump the contract
 * version when you do. A fixture changing without a version bump means somebody
 * has altered the wire format underneath four implementations.
 */

foreach ([
    'ContractVersion',
    'Exceptions/ContractException',
    'Exceptions/InvalidAmountException',
    'Exceptions/CapabilityMismatchException',
    'Money',
    'Canonical',
    'Capability',
    'Signer',
    'Validator',
    'PayloadBuilder',
    'TestVectors',
] as $class) {
    require __DIR__.'/../src/'.$class.'.php';
}

use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\PayloadBuilder;
use EdutrustPay\Contract\Signer;
use EdutrustPay\Contract\TestVectors;
use EdutrustPay\Contract\Validator;

$out = __DIR__.'/../fixtures';

if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

$m = static fn (string $v): Money => Money::of($v);

/** @var array<string, array{description: string, builder: callable(): array}> $fixtures */
$fixtures = [];

/*
 * 1. A fully capable institution — what paxhitestbed can honestly send today.
 *    Its accounting services already produce every figure here.
 */
$fixtures['full-capability'] = [
    'description' => 'An institution reporting every capability. Models a mature paxhitestbed deployment.',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-08')
        ->status('final')
        ->sequence(1)
        ->generatedAt('2026-09-02T08:14:00Z')
        ->currency('XAF')
        ->ledger(
            ['70' => $m('4200000.00'), '71' => $m('150000.00'), '75' => $m('0.00')],
            ['60' => $m('900000.00'), '62' => $m('310000.00'), '66' => $m('2100000.00')],
            $m('1040000.00'),
            ['opening' => $m('2300000.00'), 'movement' => $m('1040000.00'), 'closing' => $m('3340000.00')],
            ['total_debits' => $m('12480000.00'), 'total_credits' => $m('12480000.00'), 'journal_entry_count' => 431, 'unposted_count' => 2]
        )
        ->receivables($m('5400000.00'), ['0_30' => $m('2000000.00'), '31_60' => $m('1500000.00'), '61_90' => $m('900000.00'), '90_plus' => $m('1000000.00')])
        ->payables($m('1200000.00'), ['0_30' => $m('800000.00'), '31_60' => $m('400000.00'), '61_90' => $m('0.00'), '90_plus' => $m('0.00')])
        ->budget($m('5000000.00'), $m('4200000.00'))
        ->payroll($m('3100000.00'), $m('465000.00'))
        ->enrolment(842, $m('8400000.00'), $m('4200000.00'), '0.50', '0.31')
        ->integrity('sha256:'.str_repeat('ab', 32), 12, 1, 0)
        ->build(),
];

/*
 * 2. Ledger only — an institution mid-build, e.g. edutrustshsm today.
 *
 * THE MOST IMPORTANT FIXTURE IN THIS SET. It carries no receivables block at
 * all. The console must render receivables for this institution as "not yet
 * reporting", and must exclude it from any consolidated receivables total while
 * saying so. A zero here would be a lie with a decimal point on it.
 */
$fixtures['ledger-only'] = [
    'description' => 'An institution that can only report a ledger. Receivables are ABSENT, not zero — the console must render "not yet reporting" and exclude it from consolidated receivables.',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF_2, '2026-08')
        ->status('final')
        ->sequence(1)
        ->generatedAt('2026-09-02T08:14:00Z')
        ->ledger(
            ['70' => $m('1800000.00')],
            ['60' => $m('1200000.00')],
            $m('600000.00'),
            ['opening' => $m('500000.00'), 'movement' => $m('600000.00'), 'closing' => $m('1100000.00')],
            ['total_debits' => $m('3000000.00'), 'total_credits' => $m('3000000.00'), 'journal_entry_count' => 88, 'unposted_count' => 0]
        )
        ->build(),
];

/*
 * 3. Genuine zeros. Distinct from fixture 2 in exactly the way that matters:
 *    here receivables IS declared and IS zero. The institution has no debtors.
 *    Same rendered number, entirely different fact.
 */
$fixtures['genuine-zeros'] = [
    'description' => 'Receivables declared and genuinely zero. Contrast with ledger-only, where the same figure is absent — the console must not render these two alike.',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF_2, '2026-07')
        ->status('final')
        ->sequence(1)
        ->generatedAt('2026-08-02T08:00:00Z')
        ->ledger(
            ['70' => $m('900000.00')],
            ['60' => $m('900000.00')],
            $m('0.00'),
            ['opening' => $m('500000.00'), 'movement' => $m('0.00'), 'closing' => $m('500000.00')],
            ['total_debits' => $m('1800000.00'), 'total_credits' => $m('1800000.00'), 'journal_entry_count' => 40, 'unposted_count' => 0]
        )
        ->receivables($m('0.00'), ['0_30' => $m('0.00'), '31_60' => $m('0.00'), '61_90' => $m('0.00'), '90_plus' => $m('0.00')])
        ->build(),
];

/*
 * 4. A restatement of a FINAL period: higher sequence, same period, lower
 *    income. Must raise final_restated — fraud model B, books edited after the
 *    fact. The prior version is retained and stays visible.
 */
$fixtures['restatement-of-final'] = [
    'description' => 'Sequence 2 for a period already reported as final, with income revised down. Supersedes the prior version, which is retained. Must raise final_restated.',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-08')
        ->status('final')
        ->sequence(2)
        ->generatedAt('2026-09-20T11:00:00Z')
        ->ledger(
            ['70' => $m('3900000.00'), '71' => $m('150000.00'), '75' => $m('0.00')],
            ['60' => $m('900000.00'), '62' => $m('310000.00'), '66' => $m('2100000.00')],
            $m('740000.00'),
            ['opening' => $m('2300000.00'), 'movement' => $m('740000.00'), 'closing' => $m('3040000.00')],
            ['total_debits' => $m('12180000.00'), 'total_credits' => $m('12180000.00'), 'journal_entry_count' => 433, 'unposted_count' => 0]
        )
        ->integrity('sha256:'.str_repeat('cd', 32), 31, 3, 2)
        ->build(),
];

/*
 * 5. An unbalanced ledger. MUST BE ACCEPTED at ingest and turned into a
 *    finding. Refusing it would mean the most alarming report of the year is
 *    the one that never arrives, and the institution would show as "not
 *    reporting" rather than "reporting something wrong".
 */
$fixtures['unbalanced-ledger'] = [
    'description' => 'Debits do not equal credits, and there is an unposted backlog. Must be ACCEPTED and raise unbalanced_ledger — never rejected at the door.',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF, '2026-06')
        ->status('provisional')
        ->sequence(1)
        ->generatedAt('2026-07-02T08:00:00Z')
        ->ledger(
            ['70' => $m('2000000.00')],
            ['60' => $m('1500000.00')],
            $m('500000.00'),
            ['opening' => $m('100000.00'), 'movement' => $m('500000.00'), 'closing' => $m('600000.00')],
            ['total_debits' => $m('5000000.00'), 'total_credits' => $m('4750000.00'), 'journal_entry_count' => 120, 'unposted_count' => 7]
        )
        ->build(),
];

/*
 * 6. A business institution, posinnova-shaped: revenue derived from the
 *    transactions table, no budget, no integrity block, no enrolment. This is
 *    the honest shape for a system whose double-entry ledger has never been
 *    written to — verified: accounting_accounts_transactions is empty.
 */
$fixtures['business-reduced'] = [
    'description' => 'A pharmacy on posinnova. Ledger and receivables only — no budget, no integrity, no enrolment, because those genuinely do not exist there.',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF_3, '2026-08')
        ->status('final')
        ->sequence(1)
        ->generatedAt('2026-09-01T06:30:00Z')
        ->ledger(
            ['70' => $m('7450000.00')],
            ['60' => $m('5980000.00')],
            $m('1470000.00'),
            ['opening' => $m('820000.00'), 'movement' => $m('1470000.00'), 'closing' => $m('2290000.00')],
            ['total_debits' => $m('13430000.00'), 'total_credits' => $m('13430000.00'), 'journal_entry_count' => 517, 'unposted_count' => 0]
        )
        ->receivables($m('340000.00'), ['0_30' => $m('210000.00'), '31_60' => $m('90000.00'), '61_90' => $m('40000.00'), '90_plus' => $m('0.00')])
        ->build(),
];

/*
 * 7. A dormant month. Exercises the empty-map case: group maps must serialise
 *    as {} and not [], or a truthful report fails schema validation.
 */
$fixtures['empty-groups'] = [
    'description' => 'A dormant month: no income and no expenditure in any OHADA group. Group maps must serialise as {} not [].',
    'builder' => static fn (): array => PayloadBuilder::for(TestVectors::INSTITUTION_REF_2, '2026-05')
        ->status('final')
        ->sequence(1)
        ->generatedAt('2026-06-01T05:00:00Z')
        ->ledger(
            [],
            [],
            $m('0.00'),
            ['opening' => $m('500000.00'), 'movement' => $m('0.00'), 'closing' => $m('500000.00')],
            ['total_debits' => $m('0.00'), 'total_credits' => $m('0.00'), 'journal_entry_count' => 0, 'unposted_count' => 0]
        )
        ->build(),
];

$index = [];
$validator = new Validator();

foreach ($fixtures as $name => $spec) {
    $payload = ($spec['builder'])();
    $canonical = Canonical::encode($payload);
    $signature = Signer::signRaw($canonical, TestVectors::TIMESTAMP, TestVectors::SECRET);

    $result = $validator->validate($payload);

    // Every fixture must be structurally valid. Warnings are expected in some
    // of them (that is the point of the unbalanced one), but an ERROR here means
    // the fixture itself is wrong and would teach four codebases the wrong shape.
    if (! $result['valid']) {
        fwrite(STDERR, "FIXTURE $name IS INVALID:\n  ".implode("\n  ", $result['errors'])."\n");
        exit(1);
    }

    file_put_contents(
        $out.'/'.$name.'.json',
        json_encode([
            'name' => $name,
            'description' => $spec['description'],
            'contract_version' => $payload['contract_version'],
            'signing' => [
                'note' => 'The secret below is a published test vector, not a credential.',
                'secret' => TestVectors::SECRET,
                'timestamp' => TestVectors::TIMESTAMP,
                'signature_base' => 'timestamp . "." . canonical_json',
                'signature' => $signature,
            ],
            'expected_warnings' => array_column($result['warnings'], 'code'),
            'canonical_json' => $canonical,
            'payload' => json_decode($canonical, true),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
    );

    $index[$name] = [
        'description' => $spec['description'],
        'signature' => $signature,
        'expected_warnings' => array_column($result['warnings'], 'code'),
    ];

    printf("%-22s %s  %s\n", $name, substr($signature, 0, 24).'...', implode(',', array_column($result['warnings'], 'code')));
}

file_put_contents($out.'/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo "\n".count($fixtures)." fixtures written to fixtures/\n";
