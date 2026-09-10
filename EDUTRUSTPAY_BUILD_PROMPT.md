# EdutrustPay — Build Prompt

> Paste this whole document as the opening brief for a new project.
> It is written to be self-contained: everything needed to start is here.

---

## 1. What you are building

**EdutrustPay** is a consolidation and oversight platform for a *body* — an
organisation that owns many institutions. The founding case is the **Archdiocese
of Bamenda**, which owns schools, hospitals, pharmacies and small businesses.

Today each institution's bursar prepares a financial report by hand and submits
it upward. That process is slow, inconsistent, and the single largest source of
financial fraud in the group.

EdutrustPay replaces "the body asks for a report" with **"the report is already
there."** Institutions push their figures automatically; the body sees a
consolidated position on any day of the year.

You are building **three surfaces in one Laravel application**:

| Surface | Path | Who |
|---|---|---|
| **Superadmin** | `/admin` | The EdutrustPay operator. Creates bodies, enrols institutions, provisions users and credentials. |
| **Body Console** | `/body` | The body's own people — e.g. the Archdiocesan Bursary Head. Consolidated position, drill-down, controls. |
| **Ingest API** | `/api/v1` | Machines. Institutions push signed period reports here. |

**You are NOT building the school or business systems.** They exist. You are
building what sits above them and the contract between them.

---

## 2. The systems on the ground (this is reality, not hypothesis)

Three separate, independently-deployed codebases feed EdutrustPay:

| # | System | Type | Stack | State |
|---|---|---|---|---|
| 1 | **`paxhitestbed`** | Higher education (universities) | Laravel 10, Spatie permissions | **Mature.** Full OHADA: `chart_of_accounts`, `journal_entries`, `journal_entry_lines`, `fiscal_years`, `accounting_periods`, `default_account_mappings`. Budget sheet, daybook, reconciliation, payroll with statutory tax and remittance, fixed assets, audit log, HMAC document verification. |
| 2 | **`edutrustshsm`** ("ScholarTrack") | Primary + secondary schools | Laravel 12, Blade + Tailwind v4, custom RBAC | **Under active development.** Same OHADA spine, ~92 models. Has forms/streams/terms, GCE registration, PTA, marks workflow, timetable, parent portal, branch scoping. **Many modules present in `paxhitestbed` are not yet built here.** |
| 3 | **`posinnova`** | Hospitals, pharmacies, shops, restaurants | Laravel 9, UltimatePOS-derived | **Mature but different.** Multi-business (`Business`, `BusinessLocation`), clinical modules, POS/stock. **Its ledger is NOT the schools' OHADA shape** — it uses `AccountingAccount`, `AccountingAccountType`, `AccountingAccountsTransaction`, `AccountingBudget`. It also has its own `Superadmin` module with `Package`/`Subscription`. |

### The constraint that shapes everything

**These three systems are at different levels of completeness, run different
Laravel majors, and do not share a ledger shape.** `edutrustshsm` in particular
is mid-build and will gain capabilities over months.

Therefore: **the contract must never assume a capability exists.** An institution
declares what it can report. The console renders *"not yet reporting"* — never a
silent zero. A missing figure and a zero figure are different facts and must
never be conflated. This is the single most important rule in this document.

---

## 3. Why this exists — the fraud model

Build the controls, not just the dashboard. Money goes missing four ways, and
automatic reporting only fixes the first:

| | How money is lost | Automatic reporting fixes it? | What actually catches it |
|---|---|---|---|
| **A** | Bursar edits the report before submitting | **Yes** — the report is generated, not authored | The contract itself |
| **B** | Bursar edits the *books*; a truthful report is generated from cooked books | **No** | Append-only journals, reversal-only corrections, **restatement detection** — a period that changes after it was reported is an *event* |
| **C** | Cash received and **never entered at all** | **No** — the books balance perfectly | **Bank/MoMo reconciliation** (a source the bursar does not author) + **expected-vs-actual** from body-set fee templates |
| **D** | Student attends but is never enrolled; pays the bursar directly | **No** — invisible to the ledger | **Academic/financial cross-checks**: someone in exam registration, attendance or results who is not enrolled and not billed |

The institutions' own budget workbook concedes C and D by carrying permanent
provision lines: *Cash Shortages*, *Bad Debts*, *Provision For Uncollected Fees*,
*Provision For Contingencies*.

**Design implication:** the console's job is not to display numbers. It is to
make *discrepancies* impossible to miss. A beautiful consolidated statement that
faithfully reports a consistent lie is a failure.

---

## 4. Philosophy — non-negotiables

1. **The institution keeps its own books.** EdutrustPay is never the authority on
   an institution's ledger. It receives, it does not own.
2. **Consolidate at OHADA class/group level**, never by forcing one chart of
   accounts on everyone. Each institution maps its own accounts to OHADA groups
   *once, locally*. OHADA is the statutory framework in Cameroon.
3. **Never hold money.** EdutrustPay is not in the payment path. Institution
   payments land in the institution's own account.
4. **Push, never pull.** Institutions reach out; EdutrustPay never reaches in.
5. **Silence is an alarm.** "Which institutions have not reported, and for how
   long" is more valuable than the consolidated total.
6. **Say what you don't know.** Missing ≠ zero. Stale ≠ current. Every figure
   carries the time it was true.
7. **Nothing is deleted where money is concerned.** Restatements supersede; they
   never overwrite. Prior versions are retained and visible.
8. **Patient-level data never leaves a hospital. Student-level data never leaves
   a school.** The body receives totals, counts and ageing buckets — never rows
   about identifiable people.

---

## 5. Technology

Match `edutrustshsm` exactly:

- **PHP 8.2+**, **Laravel 12**
- **Blade** templates — no Livewire, no Inertia, no SPA
- **Tailwind CSS v4** via `@tailwindcss/vite`, **Vite 7**
- **MySQL/MariaDB**
- **`barryvdh/laravel-dompdf`** for PDF export
- **Custom RBAC** — own `Role` and `Permission` models. **Do not use Spatie.**
- Laravel queues (database driver is fine) for ingest processing
- Laravel scheduler for staleness sweeps and digests

Carry these patterns across from `edutrustshsm`:

- `app/Models/Concerns/Auditable.php` — model-level audit trail
- `app/Support/*Context.php` — request-scoped context objects (`BranchContext`
  is the model for a `BodyContext`)
- A **global scope** for tenancy, as `BelongsToBranch` does — here it will be
  `BelongsToBody`
- A thick **service layer** in `app/Services/` (`BranchProvisioningService`,
  `ConfigurationHealthService` and `AgingReportService` are good shape references)

---

## 6. Domain model

```
Body                       (Archdiocese of Bamenda) — may nest (deaneries, provinces)
 ├── parent_body_id (nullable, self-referencing)
 ├── BodyUser              (bursary head, auditor, viewer — via custom RBAC)
 └── Institution
      ├── type: school_basic | school_higher | business
      ├── system: edutrustshsm | paxhitestbed | posinnova
      ├── endpoint / connectivity mode
      ├── InstitutionCredential      (API key id + hashed secret, rotatable)
      ├── InstitutionCapability      (what it can currently report)  ← critical
      ├── AccountMapping             (local account → OHADA group), optional mirror
      ├── PeriodReport               (one per institution per calendar month)
      │    ├── version / supersedes_id      ← restatements
      │    ├── payload (JSON), payload_hash, signature
      │    └── ReportLine             (OHADA group → amount)
      ├── OperationalReport           (enrolment, occupancy, turnover…)
      ├── SyncEvent                   (received / rejected / stale / restated)
      └── Finding                     (a control that fired — see §12)
```

**Nesting:** a body may contain bodies. A deanery sees its own institutions; the
centre sees all. Implement as a self-referencing tree with a materialised path or
nested set so "everything under this body" is one query.

**Institution type decides the edition**, and the edition decides which
capabilities are even applicable. A `business` institution has no enrolment.

---

## 7. The Reporting Contract v1 — the core artefact

**Design this before anything else.** Four codebases must agree on it. Everything
else is downstream. Version it from day one (`contract_version: "1.0"`).

### 7.1 Period semantics

- **The unit is the calendar month.** Never a fiscal year.
- `paxhitestbed` runs **January–December** fiscal years; the schools' workbook
  runs **July–June**. A body will have both. If institutions report "year to
  date", nothing is comparable.
- Institutions report months. **The console assembles any fiscal year.**
- A month may be reported while still open (provisional) and again when closed
  (final). Carry an explicit `status: provisional | final`.

### 7.2 Payload shape

```jsonc
{
  "contract_version": "1.0",
  "institution_ref": "uuid",
  "period": "2026-08",                  // calendar month, always
  "status": "final",                    // provisional | final
  "sequence": 3,                        // increments per (institution, period)
  "generated_at": "2026-09-02T08:14:00Z",
  "currency": "XAF",

  "capabilities": ["ledger", "receivables", "budget", "enrolment", "payroll"],

  "financial": {
    "income_by_group":      { "70": 4200000, "71": 150000, "75": 0 },
    "expenditure_by_group": { "60": 900000, "62": 310000, "66": 2100000 },
    "surplus_deficit": 1040000,

    "cash": { "opening": 2300000, "movement": 1040000, "closing": 3340000 },

    "receivables": { "total": 5400000,
      "ageing": { "0_30": 2000000, "31_60": 1500000, "61_90": 900000, "90_plus": 1000000 } },
    "payables":    { "total": 1200000,
      "ageing": { "0_30": 800000, "31_60": 400000, "61_90": 0, "90_plus": 0 } },

    "budget": { "budgeted": 5000000, "actual": 4200000 },

    // Control totals — so the console can verify the ledger balances itself
    // rather than trusting a summary. Cheap, and catches a great deal.
    "control": { "total_debits": 12480000, "total_credits": 12480000,
                 "journal_entry_count": 431, "unposted_count": 2 }
  },

  "operational": {
    // shape depends on institution type; omit what does not apply
    "enrolment": 842,
    "expected_fees": 8400000,           // enrolment × fee structure  ← anti-fraud
    "collected": 4200000,
    "collection_rate": 0.50,
    "cash_share": 0.31                  // proportion taken as physical cash
  },

  "integrity": {
    "ledger_hash": "sha256:…",          // hash over the period's journal lines
    "audit_events": 12,                 // audit-log entries touching this period
    "reversals": 1,
    "backdated_entries": 0              // entries posted into an earlier period
  }
}
```

### 7.3 Rules

- **Idempotent** on `(institution_ref, period, sequence)`. A resend must never
  double-count.
- **Restatement**: a higher `sequence` for a period already received supersedes
  it. **Retain the prior version.** Raise a `Finding` if a `final` period is
  restated — that is control B.
- **Signed**: HMAC-SHA256 over the canonical JSON with the institution's secret.
  Reject on mismatch. (`paxhitestbed`'s receipt verification is a working
  reference for this pattern.)
- **Omission is meaningful.** A key absent from `capabilities` means *cannot
  report*. A key present with value `0` means *genuinely zero*. Never coerce.
- **Amounts** in minor-unit-free XAF as decimal(18,2). Do not assume integers;
  do not use floats for money anywhere.

### 7.4 Capability declaration — the accommodation for incomplete systems

Because `edutrustshsm` is mid-build and `posinnova` has a different ledger:

```
ledger        — income/expenditure by OHADA group, cash position
receivables   — debtors with ageing
payables      — creditors with ageing
budget        — budget vs actual
enrolment     — student counts and expected fees
payroll       — staff cost and statutory liabilities
integrity     — ledger hash, reversal and backdating counts
bank_recon    — reconciliation against bank/MoMo statements
```

The console must render a **capability matrix**: institutions down, capabilities
across, so the body sees at a glance who is reporting what — and so the roadmap
for `edutrustshsm` is visible as gaps that close over time.

**Never render a missing capability as zero, and never include a
non-reporting institution silently in a consolidated total.** State coverage
explicitly: *"Consolidated across 7 of 9 institutions. 2 not reporting."*

---

## 8. Ingestion

```
POST /api/v1/reports          signed payload, idempotent
POST /api/v1/heartbeat        liveness even when there is nothing to report
GET  /api/v1/contract         current contract version + schema
POST /api/v1/credentials/rotate
```

- Authenticate by **API key id + HMAC signature**, not a bearer token in a
  header alone.
- Accept fast, **process on a queue**. The institution may be on a phone hotspot
  with seconds of connectivity.
- **Heartbeat matters as much as the report.** An institution with nothing new
  must still say it is alive, otherwise you cannot distinguish "quiet month"
  from "stopped reporting".
- Return the contract version so a client can detect it is out of date.

### Connectivity

- **Default: push over outbound HTTPS.** No inbound access, no NAT traversal, no
  port forwarding, no tunnel required. A hotspot for thirty seconds a day works.
- **Institutions run locally** on a bursary server with power cuts. Assume an
  **outbox with retry** on their side; on yours, assume out-of-order and repeated
  delivery and be idempotent.
- **Cloudflare Tunnel is optional and additive** — only for live interactive
  drill-down into a local institution. **Daily reporting must never depend on
  it.** Tunnels die; reporting must survive that.

---

## 9. Superadmin (`/admin`)

- Create and nest **bodies**; brand them (name, logo, fiscal year preference).
- **Enrol an institution**: choose type (`school_basic` | `school_higher` |
  `business`), which places it on the right system, and generate credentials.
- **Provision the body's users** — e.g. create the bursary head's account on body
  creation. Use an **invitation with a one-time link**, never a generated
  password shown on screen or emailed in plain text.
- **Credential lifecycle**: issue, rotate, revoke. *A departing bursar with a live
  API key is a real risk* — make rotation a first-class, one-click action.
- **Onboarding checklist per institution**, visible to both operator and body:

  ```
  ☐ Merchant account in the institution's own name   ← start first, takes longest
  ☐ Server + power backup
  ☐ Some internet access
  ☐ Chart of accounts mapped to OHADA groups         ← decides whether any of this is worth anything
  ☐ Fiscal year and periods opened
  ☐ Credentials issued and first successful push
  ☐ Named data owner
  ☐ Bursar trained
  ```

- **Contract version per institution**, so a rollout can be staged.

---

## 10. Body Console (`/body`)

Order the console by **what needs attention**, not by what is easy to render.

**Landing page, in this order:**

1. **Who has not reported** — institution, days silent, last period received.
   This is the most valuable widget on the platform. Put it first.
2. **Findings** — controls that fired (§12), most severe first.
3. **Coverage statement** — *"Consolidated across 7 of 9 institutions."*
4. **Consolidated position** — income, expenditure, surplus/deficit, cash.
5. **Collection rates and arrears ageing** across the body.
6. **Budget vs actual**, per institution and for the body.

**Then:**

- **Drill down** body → institution → OHADA group → (link out to the institution's
  own system for the line detail, since EdutrustPay does not hold rows).
- **Compare like with like** — a college against a clinic, because both arrive in
  OHADA classes.
- **Capability matrix** (§7.4).
- **Trend** per institution across months — a step change is a signal.
- **Inter-institution transfers** visible from both ends; **net contributors vs
  net dependents**, which is the subvention question the General Bursar actually
  needs answered.
- **Export**: PDF (dompdf) and Excel, in the shape the auditors already use.

**Roles:** body admin, bursary head, auditor (read-only, sees everything
including findings), institution liaison (one institution only).

---

## 11. Anti-fraud controls — implement these as first-class `Finding` records

A `Finding` has: institution, period, rule, severity, explanation in plain
language, the figures behind it, status (open / explained / accepted), and who
resolved it. **A control that only writes to a log is not a control.**

| Rule | Fires when | Catches |
|---|---|---|
| `not_reporting` | No report or heartbeat for N days | Concealment by silence |
| `final_restated` | A `final` period is superseded | B — retro-edited books |
| `backdated_entries` | `integrity.backdated_entries > 0` | B |
| `unbalanced_ledger` | `total_debits ≠ total_credits` | Broken or manipulated ledger |
| `unposted_backlog` | `unposted_count` rising | Figures kept out of the ledger |
| `collection_gap` | `collected` far below `expected_fees` | C and D |
| `cash_share_high` | Physical-cash share above threshold or rising | C — exposure to interception |
| `arrears_spike` | 90+ bucket jumps period on period | C disguised as arrears |
| `enrolment_finance_mismatch` | Enrolment up, fee income flat | D — off-book students |
| `budget_variance` | Actual vs budget beyond tolerance | Expenditure fraud |
| `reversal_rate` | Reversals unusually high | Cover-up of posted entries |
| `bank_divergence` | Ledger cash movement ≠ bank/MoMo statement | **C — the strongest control** |

**Two structural notes:**

- `expected_fees` is only trustworthy if the **body pushes fee structure
  templates down** and the institution reports enrolment against them. Build the
  template push-down; it converts fraud detection from "do we trust the report"
  into arithmetic.
- `bank_divergence` is the highest-value and hardest control, because it compares
  the ledger against a source the bursar does not author. Design for it even if
  it ships last — at minimum, accept a periodic statement upload.

---

## 12. Security and privacy

- **Least data.** Totals, counts and ageing buckets. **No patient rows, no
  student rows, ever.** If a feature seems to need one, it is the wrong feature.
- Per-institution HMAC secrets, hashed at rest, rotatable.
- Rate limiting and replay protection on ingest (reject stale `generated_at`).
- Full audit log on the console too — who looked at what, who resolved a finding.
- Body-level tenancy enforced by **global scope**, not by remembering to filter.
  Test that a body user cannot read another body's data by ID manipulation.
- Two-factor for body admin and superadmin.

---

## 13. What NOT to build

- **Not a payment processor.** EdutrustPay never holds funds.
- **Not a general ledger.** It receives summaries; institutions keep the books.
- **Not a replacement bursary system.** No data entry for institutions here.
- **Not a student information system.** No student rows.
- **Do not force a shared chart of accounts.**
- **Do not build a pull/scraper** against institution databases.
- **Do not invent figures.** Where data is absent, say so.

---

## 14. Build order

1. **The Reporting Contract v1** — spec + JSON schema + signing + a validator
   and fixtures. Nothing else starts until this is fixed. Publish it as a
   versioned document other codebases can implement against.
2. **Ingest API** + idempotency + restatement + queue processing.
3. **Superadmin**: bodies, institutions, credentials, capability declaration.
4. **A reference client** implemented in **`paxhitestbed` first** — it is the most
   mature and its accounting services already produce every figure the contract
   asks for. Prove the contract end to end on real data before touching others.
5. **Body Console**: not-reporting, coverage, consolidated position, drill-down.
6. **Findings engine** — start with `not_reporting`, `final_restated`,
   `unbalanced_ledger`, `collection_gap`.
7. **`edutrustshsm` client**, declaring only the capabilities it has today.
8. **`posinnova` adapter** — needs its own mapping from `AccountingAccount` /
   `AccountingAccountsTransaction` to OHADA groups.
9. Fee-template push-down, then bank reconciliation.

---

## 15. Definition of done for the first milestone

- A body exists with two institutions, one of which is a real `paxhitestbed`
  deployment pushing signed monthly reports.
- The console shows a consolidated position **with an explicit coverage
  statement**, and drill-down to each institution.
- Stopping the pushes raises a `not_reporting` finding within the threshold.
- Restating a `final` period raises `final_restated` and both versions remain
  visible.
- An institution declaring only `ledger` renders as *"not yet reporting"* for
  receivables — **never as zero**.
- A body user provably cannot read another body's data.
- The consolidated statement exports as PDF in OHADA shape.

---

## 16. Naming

The platform is currently called EdutrustPay, which is a payments brand. Once it
carries hospitals, pharmacies and shops, the name describes one part of what it
does. **The public fee-payment portal can keep the name; the body console
probably should not.** Keep the naming configurable — do not hard-code
"EdutrustPay" into the console's UI strings.

---

## 17. Open decisions — ask before assuming

1. **How is the EdutrustPay service fee charged** — invoiced to the institution
   monthly, or surcharged to the payer? (Affects billing models here.)
2. **Does the body see institution-level user activity**, or only figures?
3. **What is the governance response** to an institution that stops reporting?
   The platform can only make it visible.
4. **Does `posinnova`'s existing `Package`/`Subscription` tenancy get reused**,
   or does EdutrustPay hold institution identity independently?
5. **Fiscal year per body or per institution** — the console must assemble both,
   but which is the default view?
