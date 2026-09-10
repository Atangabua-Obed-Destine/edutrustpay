<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutions, their credentials, and what they can actually report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();

            /*
             * The contract's `institution_ref`. A UUID rather than the numeric id
             * because it is quoted back to us by an external system over a public
             * endpoint, and sequential ids leak how many institutions exist and
             * invite enumeration.
             */
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('slug')->index();

            // school_basic | school_higher | business — decides the edition, and
            // the edition decides which capabilities are even applicable
            // (a business has no enrolment).
            $table->string('type');

            // edutrustshsm | paxhitestbed | posinnova
            $table->string('system');

            $table->string('endpoint')->nullable();

            /*
             * push (default) | tunnel.
             *
             * Push over outbound HTTPS is the only mode daily reporting may
             * depend on: no inbound access, no NAT traversal, no port forwarding.
             * A Cloudflare Tunnel is additive, for live drill-down only — tunnels
             * die, and reporting has to survive that (brief §8).
             */
            $table->string('connectivity_mode')->default('push');

            // Per institution, so a contract rollout can be staged rather than
            // flag-day across every deployment at once.
            $table->string('contract_version')->default('1.0');

            $table->string('city', 100)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();

            // Overrides the body's threshold, which overrides the platform default.
            $table->unsignedSmallInteger('not_reporting_days')->nullable();

            /*
             * Denormalised liveness. "Which institutions have not reported, and
             * for how long" is the most valuable widget on the platform (brief
             * §4.5), so it must not require scanning period_reports on every page
             * load.
             *
             * Both are nullable and mean "never" — distinct from "long ago".
             */
            $table->timestamp('last_report_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_period_received', 7)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['body_id', 'is_active'], 'institutions_body_active_index');
        });

        /*
         * An institution liaison sees exactly one institution (brief §10); null
         * for every other role.
         *
         * Added here rather than in the users migration because the constraint
         * needs `institutions` to exist. Creating the column and its foreign key
         * in one statement matters: SQLite cannot ALTER a table to add a foreign
         * key afterwards, and the test suite runs on SQLite.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('body_id')
                ->constrained('institutions')->nullOnDelete();
        });

        Schema::create('institution_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // Public, safe to log and to show in the console.
            $table->string('key_id', 64)->unique();

            /*
             * The shared secret, ENCRYPTED at rest.
             *
             * NOTE A CONTRADICTION IN THE BRIEF, resolved here deliberately.
             * §12 asks for secrets "hashed at rest", but §7.3 requires HMAC-SHA256
             * signing — and verifying an HMAC requires the actual secret on both
             * sides. A hash is by definition not recoverable, so the two cannot
             * both hold. Hashing would only work if the client sent the secret
             * itself, which is precisely the bearer-token-in-a-header the brief
             * rules out in §8.
             *
             * So: encrypted with the application key, not hashed. The security
             * property is different and weaker than hashing, and worth being
             * honest about — an attacker with BOTH the database and APP_KEY
             * recovers every institution's secret. Mitigations that follow from
             * that: APP_KEY must not live in the same backup as the database,
             * rotation is one click (see revoked_at), and every use is stamped in
             * last_used_at so an unexpected one is visible.
             *
             * TEXT rather than string: Laravel's encrypted payload is a
             * base64-encoded JSON envelope, comfortably longer than 255 bytes.
             */
            $table->text('secret_ciphertext');

            $table->string('label')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();

            /*
             * Revocation is a first-class, one-click action: a departing bursar
             * holding a live API key is a real risk, not a theoretical one
             * (brief §9). Revoked rows are RETAINED, never deleted, so the audit
             * trail can still explain a report signed with an old key.
             */
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['institution_id', 'revoked_at'], 'inst_cred_active_index');
        });

        Schema::create('institution_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // ledger | receivables | payables | budget | enrolment | payroll |
            // integrity | bank_recon
            $table->string('capability', 40);

            /*
             * declared — the operator recorded that this institution can report it
             * observed — a report arrived carrying it
             *
             * Keeping both lets the console show the gap between what an
             * institution was expected to send and what it actually sends, which
             * is a quieter failure than not reporting at all.
             */
            $table->string('source')->default('declared');

            $table->timestamp('declared_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'capability'], 'inst_capability_unique');
        });

        /*
         * Optional local-account -> OHADA group mirror.
         *
         * Unused by paxhitestbed and edutrustshsm, whose chart_of_accounts
         * already carries the OHADA code in `account_code` and the class in
         * `class_number` — the mapping there is intrinsic. This exists for
         * posinnova, whose accounts have no enforced code semantics at all
         * (gl_code is free text and empty on all 87 rows).
         *
         * It is a MIRROR for the console to explain a figure with; the mapping
         * itself is owned and applied locally by the institution (brief §4.2).
         */
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('local_code', 64);
            $table->string('local_name')->nullable();
            $table->char('ohada_group', 2);
            $table->timestamps();

            $table->unique(['institution_id', 'local_code'], 'account_mapping_unique');
            $table->index(['institution_id', 'ohada_group'], 'account_mapping_group_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
        });

        Schema::dropIfExists('account_mappings');
        Schema::dropIfExists('institution_capabilities');
        Schema::dropIfExists('institution_credentials');
        Schema::dropIfExists('institutions');
    }
};
