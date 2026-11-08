<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anti-fraud controls (brief §11)
    |--------------------------------------------------------------------------
    |
    | Each rule is a class implementing App\Findings\Rule, with its thresholds
    | here rather than in the code, because thresholds are a governance decision
    | and will be argued about. A bursary head who cannot change the arrears
    | tolerance without a deployment will simply stop trusting the finding.
    |
    | Severity ordering drives the console's landing page, which is ordered by
    | what needs attention rather than by what is easy to render (brief §10).
    | Each rule works out its own severity from the facts. Setting 'severity' on
    | a rule here overrides that for every case, so do it only deliberately.
    |
    */

    'rules' => [

        /*
         * Silence. The most valuable widget on the platform: "which institutions
         * have not reported, and for how long" is worth more than the
         * consolidated total (brief §4.5).
         *
         * 35 days rather than 30: a month-end close plus a few days' slack, so a
         * bursar who reports on the 3rd every month is not permanently in
         * breach. The threshold is overridable per body and per institution —
         * a rural mission reaching a hotspot monthly and a city hospital on
         * fibre should not share one.
         */
        'not_reporting' => [
            'enabled' => true,
            'days' => 35,
        ],

        /*
         * A FINAL period was superseded. This is fraud model B — books edited
         * after the fact — and it is the reason restatements supersede rather
         * than overwrite.
         *
         * Critical, because unlike most rules here it cannot be explained by
         * connectivity, a mid-build system, or a bursar being behind.
         */
        'final_restated' => [
            'enabled' => true,
            // Severity is set by the rule: critical for a month declared final,
            // high or medium for a provisional month changed late.
            // A provisional month whose ledger changes this long after it ended
            // is raised too, because some schools never close their periods.
            'late_after_days' => 30,
        ],

        /*
         * Debits do not equal credits. Accepted at ingest and raised here — see
         * the contract's Validator for why rejecting it would be a mistake.
         */
        'unbalanced_ledger' => [
            'enabled' => true,
        ],

        /*
         * Entries posted into a period earlier than the date they were created.
         *
         * EXCLUDES REVERSALS, and the client is responsible for that exclusion:
         * paxhitestbed deliberately dates a reversal to the original entry when
         * its period is still open, so counting them would make this fire
         * constantly on correct behaviour and train everyone to ignore it.
         */
        'backdated_entries' => [
            'enabled' => true,
            'threshold' => 1,
        ],

        /*
         * Figures being kept out of the ledger. Rising is what matters more than
         * the absolute number — every institution has a few unposted entries
         * mid-month.
         */
        'unposted_backlog' => [
            'enabled' => true,
            'threshold' => 10,
        ],

        /*
         * Collected far below what the fee structure says should have been.
         * Catches fraud models C and D — cash never entered, and students who
         * attend but were never enrolled.
         *
         * expected_fees is a figure the institution chose, so comparing it to
         * collected proves nothing by itself. 0.70 is a starting point, not a
         * finding of fact.
         */
        'collection_gap' => [
            'enabled' => true,
            'min_collection_rate' => '0.70',
        ],

        /*
         * The share of collections taken as physical cash.
         *
         * Added after the edutrustshsm client reported 99.6% on real data: at a
         * school where almost every franc passes through someone's hands before
         * it reaches an account, the ledger can be immaculate and the money
         * still gone. This is fraud model C — cash received and never entered —
         * and it is the exposure measure, not the loss itself.
         *
         * The threshold is high on purpose. Cash is normal in Cameroon and a
         * rural mission may have no realistic alternative, so firing at 40%
         * would flag the whole group and mean nothing. What matters is a school
         * that is nearly all-cash, or one whose share is climbing.
         */
        'cash_share_high' => [
            'enabled' => true,
            'threshold' => '0.80',
            // Also fire below the threshold when the share is rising steadily,
            // because the trend is the earlier signal.
            'rise_over_months' => '0.15',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | How old a figure may be before the console renders it as stale rather than
    | current. Distinct from not_reporting: a figure can be stale without the
    | institution being silent, if it reports some capabilities and not others.
    |
    */

    'stale_after_days' => 45,

];
