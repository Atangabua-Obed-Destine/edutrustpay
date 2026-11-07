<?php

declare(strict_types=1);

/**
 * Regenerates schema/report.v1.json.
 *
 * Run:  php tools/generate-schema.php
 *
 * The schema is GENERATED from Capability::blocks(), the same source of truth
 * the Validator uses. Hand-maintaining both would guarantee they drift, and a
 * schema that disagrees with the validator is worse than no schema: it tells an
 * implementer their payload is fine when the server will refuse it.
 *
 * The schema exists mainly for implementers outside PHP and for tooling. PHP
 * clients should use the Validator, which also produces the semantic warnings
 * (unbalanced ledger, ageing that does not sum) that a schema cannot express.
 */

foreach (['ContractVersion', 'Capability'] as $class) {
    require __DIR__.'/../src/'.$class.'.php';
}

use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\ContractVersion;

/** A money amount on the wire: a decimal string, never a JSON number. */
$money = [
    'type' => 'string',
    'pattern' => '^-?(0|[1-9][0-9]*)\\.[0-9]{2}$',
    'description' => 'A decimal string such as "4200000.00". NEVER a JSON number: float encoding is not reproducible across PHP versions and would break the payload signature.',
];

$rate = [
    'type' => 'string',
    'pattern' => '^(0(\\.[0-9]{1,4})?|1(\\.0{1,4})?)$',
    'description' => 'A decimal string between "0" and "1", e.g. "0.50". Never a float.',
];

$ageing = [
    'type' => 'object',
    'required' => ['0_30', '31_60', '61_90', '90_plus'],
    'additionalProperties' => false,
    'properties' => [
        '0_30' => $money,
        '31_60' => $money,
        '61_90' => $money,
        '90_plus' => $money,
    ],
];

$ageingBlock = [
    'type' => 'object',
    'required' => ['total', 'ageing'],
    'additionalProperties' => false,
    'properties' => ['total' => $money, 'ageing' => $ageing],
];

/*
 * A map keyed by two-digit OHADA group.
 *
 * Expressed as patternProperties + additionalProperties:false rather than the
 * more obvious propertyNames, because propertyNames is widely unimplemented —
 * opis/json-schema ignores it entirely, and so a schema relying on it would
 * accept a one-digit group while appearing to forbid it. This formulation says
 * the same thing and is enforced by every validator worth using: keys matching
 * two digits must be money, and no other key is permitted at all.
 *
 * propertyNames is kept alongside for validators that do implement it.
 */
$groupMap = [
    'type' => 'object',
    'description' => 'Keyed by two-digit OHADA group. Consolidation happens at OHADA class/group level so no institution has to adopt anyone else\'s chart of accounts. An empty month is {} — never [].',
    'propertyNames' => ['pattern' => '^[0-9]{2}$'],
    'patternProperties' => ['^[0-9]{2}$' => $money],
    'additionalProperties' => false,
];

$financial = [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'income_by_group' => $groupMap,
        'expenditure_by_group' => $groupMap,
        'surplus_deficit' => $money,
        'cash' => [
            'type' => 'object',
            'required' => ['opening', 'movement', 'closing'],
            'additionalProperties' => false,
            'properties' => ['opening' => $money, 'movement' => $money, 'closing' => $money],
        ],
        'control' => [
            'type' => 'object',
            'description' => 'Control totals, so the console can verify the ledger balances itself rather than trusting a summary. An unbalanced set is ACCEPTED and raises a finding — it is never rejected at ingest.',
            'required' => ['total_debits', 'total_credits', 'journal_entry_count', 'unposted_count'],
            'additionalProperties' => false,
            'properties' => [
                'total_debits' => $money,
                'total_credits' => $money,
                'journal_entry_count' => ['type' => 'integer', 'minimum' => 0],
                'unposted_count' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'receivables' => $ageingBlock,
        'payables' => $ageingBlock,
        'budget' => [
            'type' => 'object',
            'required' => ['budgeted', 'actual'],
            'additionalProperties' => false,
            'properties' => ['budgeted' => $money, 'actual' => $money],
        ],
        'payroll' => [
            'type' => 'object',
            'required' => ['staff_cost', 'statutory_liabilities'],
            'additionalProperties' => false,
            'properties' => ['staff_cost' => $money, 'statutory_liabilities' => $money],
        ],
        'bank_recon' => [
            'type' => 'object',
            'description' => 'Reconciliation against a source the bursar does not author — the highest-value control in the platform.',
            'required' => ['statement_closing', 'ledger_closing', 'divergence', 'statement_ref'],
            'additionalProperties' => false,
            'properties' => [
                'statement_closing' => $money,
                'ledger_closing' => $money,
                'divergence' => $money,
                'statement_ref' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
    ],
];

$operational = [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'enrolment' => ['type' => 'integer', 'minimum' => 0],
        'expected_fees' => array_merge($money, [
            'description' => 'Enrolment multiplied by the fee structure the BODY set — not a figure the institution chose. Compared against collected, it turns "do we trust this report" into arithmetic.',
        ]),
        'collected' => $money,
        'collection_rate' => $rate,
        'fees_charged' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['session', 'rows'],
            'description' => 'What is actually being charged, per class and fee. No student is named.',
            'properties' => [
                'session' => ['type' => 'string', 'minLength' => 1],
                'rows' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['level', 'category', 'amount', 'students'],
                        'properties' => [
                            'level' => ['type' => 'string', 'minLength' => 1],
                            'category' => ['type' => 'string', 'minLength' => 1],
                            'amount' => $money,
                            'students' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                ],
            ],
        ],
        'cash_share' => array_merge($rate, [
            'description' => 'Proportion taken as physical cash. The exposure measure for interception.',
        ]),
    ],
];

$integrity = [
    'type' => 'object',
    'required' => ['ledger_hash', 'audit_events', 'reversals', 'backdated_entries'],
    'additionalProperties' => false,
    'properties' => [
        'ledger_hash' => [
            'type' => 'string',
            'pattern' => '^sha256:[0-9a-f]{64}$',
            'description' => 'Hash over the period\'s journal lines, so the console can tell that a period\'s underlying detail changed even when its totals did not.',
        ],
        'audit_events' => ['type' => 'integer', 'minimum' => 0],
        'reversals' => ['type' => 'integer', 'minimum' => 0],
        'backdated_entries' => [
            'type' => 'integer',
            'minimum' => 0,
            'description' => 'Entries whose entry date precedes the date they were created, EXCLUDING reversals. Reversals are legitimately dated to the original entry when its period is still open, so counting them here would make the finding fire constantly on correct behaviour.',
        ],
    ],
];

/*
 * The capability coupling, expressed as if/then pairs generated from
 * Capability::blocks(). Both directions: declaring a capability requires its
 * blocks, and omitting it forbids them.
 */
$coupling = [];

foreach (Capability::blocks() as $capability => $blocks) {
    $declared = ['properties' => ['capabilities' => ['contains' => ['const' => $capability]]]];

    $requireBlocks = [];
    $forbidBlocks = [];

    foreach ($blocks as $block) {
        $parts = explode('.', $block);

        if (count($parts) === 1) {
            $requireBlocks['required'] = array_merge($requireBlocks['required'] ?? [], [$parts[0]]);
            $forbidBlocks['properties'][$parts[0]] = false;

            continue;
        }

        [$section, $key] = $parts;
        $requireBlocks['properties'][$section]['required'] = array_merge(
            $requireBlocks['properties'][$section]['required'] ?? [],
            [$key]
        );
        $requireBlocks['required'] = array_values(array_unique(array_merge($requireBlocks['required'] ?? [], [$section])));
        $forbidBlocks['properties'][$section]['properties'][$key] = false;
    }

    $coupling[] = [
        'title' => sprintf('Capability "%s" is declared if and only if its figures are present.', $capability),
        'if' => $declared,
        'then' => $requireBlocks,
        'else' => $forbidBlocks,
    ];
}

$schema = [
    '$schema' => 'http://json-schema.org/draft-07/schema#',
    '$id' => 'https://edutrustpay.local/schema/report.v'.ContractVersion::CURRENT.'.json',
    'title' => 'EdutrustPay Reporting Contract v'.ContractVersion::CURRENT,
    'description' => implode(' ', [
        'A single institution\'s figures for a single CALENDAR MONTH.',
        'The unit is always the calendar month, never a fiscal year: paxhitestbed runs January-December while the schools run July-June, so a body holds both and the console assembles any fiscal year from months.',
        'A capability absent from "capabilities" means CANNOT REPORT; a figure present with value 0 means GENUINELY ZERO. These are different facts and must never be conflated.',
    ]),
    'type' => 'object',
    'required' => ['contract_version', 'institution_ref', 'period', 'status', 'sequence', 'generated_at', 'currency', 'capabilities'],
    'additionalProperties' => false,
    'properties' => [
        'contract_version' => ['type' => 'string', 'enum' => ContractVersion::SUPPORTED],
        'institution_ref' => ['type' => 'string', 'minLength' => 1],
        'period' => [
            'type' => 'string',
            'pattern' => '^[0-9]{4}-(0[1-9]|1[0-2])$',
            'description' => 'Calendar month, "YYYY-MM". Never a fiscal year.',
        ],
        'status' => [
            'type' => 'string',
            'enum' => ['provisional', 'final'],
            'description' => 'A month may be reported while still open (provisional) and again when closed (final). Restating a FINAL period is a control event.',
        ],
        'sequence' => [
            'type' => 'integer',
            'minimum' => 1,
            'description' => 'Increments per (institution, period). A higher sequence supersedes; the prior version is retained, never overwritten.',
        ],
        'generated_at' => ['type' => 'string', 'format' => 'date-time'],
        'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
        'capabilities' => [
            'type' => 'array',
            'minItems' => 1,
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'enum' => Capability::all()],
            'description' => 'What this institution can report. Omission means cannot report. An institution with nothing new to say sends a heartbeat, not an empty report.',
        ],
        'financial' => $financial,
        'operational' => $operational,
        'integrity' => $integrity,
    ],
    'allOf' => $coupling,
];

file_put_contents(
    __DIR__.'/../schema/report.v'.ContractVersion::CURRENT.'.json',
    json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
);

echo 'Wrote schema/report.v'.ContractVersion::CURRENT.".json\n";
echo count($coupling)." capability coupling rules generated from Capability::blocks()\n";
