<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyInstitutionSignature as Sig;
use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\PeriodReport;
use App\Models\SyncEvent;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\ContractVersion;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\PayloadBuilder;
use EdutrustPay\Contract\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The ingest API end to end.
 *
 * Everything here goes through the real signature middleware with a real HMAC —
 * nothing is faked past the front door, because the front door is the part with
 * the security properties.
 */
class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private InstitutionCredential $credential;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);

        $this->institution = Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => 'Catholic University of Bamenda',
            'type' => 'school_higher',
            'system' => 'paxhitestbed',
        ]);

        $issued = InstitutionCredential::issue($this->institution, 'Test');
        $this->credential = $issued['credential'];
        $this->secret = $issued['secret'];
    }

    // ---------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------

    public function test_a_correctly_signed_report_is_accepted(): void
    {
        $response = $this->push($this->payload());

        $response->assertStatus(202)->assertJson(['status' => 'accepted', 'contract_version' => ContractVersion::CURRENT]);

        $this->assertDatabaseHas('period_reports', [
            'institution_id' => $this->institution->id,
            'period' => '2026-08',
            'sequence' => 1,
        ]);
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $this->postJson('/api/v1/reports', [])->assertStatus(401)
            ->assertJson(['error' => 'missing_credentials']);
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $json = Canonical::encode($this->payload());
        $timestamp = now()->toIso8601String();
        $signature = Signer::signRaw($json, $timestamp, $this->secret);

        // Sign the honest figures, send inflated ones.
        $tampered = str_replace('"4200000.00"', '"9200000.00"', $json);
        $this->assertNotSame($json, $tampered);

        $this->call('POST', '/api/v1/reports', [], [], [], $this->headers($timestamp, $signature), $tampered)
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_signature']);

        $this->assertSame(0, PeriodReport::withoutBodyScope()->count());
    }

    public function test_the_wrong_secret_is_refused(): void
    {
        $json = Canonical::encode($this->payload());
        $timestamp = now()->toIso8601String();

        $this->call('POST', '/api/v1/reports', [], [], [], $this->headers($timestamp, Signer::signRaw($json, $timestamp, 'wrong-secret')), $json)
            ->assertStatus(401);
    }

    public function test_a_replayed_request_outside_the_window_is_refused(): void
    {
        $json = Canonical::encode($this->payload());

        // A captured request, resent an hour later. The timestamp is part of the
        // signed material, so it cannot simply be rewritten to look fresh.
        $timestamp = now()->subHour()->toIso8601String();
        $signature = Signer::signRaw($json, $timestamp, $this->secret);

        $this->call('POST', '/api/v1/reports', [], [], [], $this->headers($timestamp, $signature), $json)
            ->assertStatus(401)
            ->assertJson(['error' => 'stale_timestamp']);
    }

    public function test_a_rewritten_timestamp_invalidates_the_signature(): void
    {
        $json = Canonical::encode($this->payload());
        $signedAt = now()->subHour()->toIso8601String();
        $signature = Signer::signRaw($json, $signedAt, $this->secret);

        // The attacker moves the timestamp forward to defeat the staleness
        // window, keeping the captured signature.
        $this->call('POST', '/api/v1/reports', [], [], [], $this->headers(now()->toIso8601String(), $signature), $json)
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_signature']);
    }

    public function test_a_revoked_credential_stops_working(): void
    {
        $this->credential->revoke('Bursar left');

        $this->push($this->payload())->assertStatus(401);
    }

    public function test_an_institution_cannot_file_a_report_about_another_institution(): void
    {
        $other = Institution::withoutBodyScope()->create([
            'body_id' => $this->institution->body_id,
            'name' => 'St Bede College',
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
        ]);

        // Correctly signed with our own key, but claiming to be about a sibling.
        // The HMAC proves who sent it, not who it is about — this is the gap the
        // signature alone does not close.
        $this->push($this->payload($other->uuid))
            ->assertStatus(422)
            ->assertJson(['error' => 'institution_mismatch']);
    }

    // ---------------------------------------------------------------
    // Idempotency and restatement
    // ---------------------------------------------------------------

    public function test_an_identical_resend_does_not_double_count(): void
    {
        $payload = $this->payload();

        $this->push($payload)->assertStatus(202);
        $this->push($payload)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, PeriodReport::withoutBodyScope()->count());
    }

    public function test_different_figures_at_the_same_sequence_are_refused(): void
    {
        $this->push($this->payload())->assertStatus(202);

        // Same period, same sequence, different money. This is not a retry: it
        // is the restatement path being bypassed.
        $this->push($this->payload(null, '3900000.00'))
            ->assertStatus(409)
            ->assertJson(['error' => 'sequence_conflict']);

        $this->assertSame(1, PeriodReport::withoutBodyScope()->count());
        $this->assertSame(
            '4200000.00',
            PeriodReport::withoutBodyScope()->first()->decodedPayload()['financial']['income_by_group']['70']
        );
    }

    public function test_a_higher_sequence_supersedes_and_both_versions_remain(): void
    {
        $this->push($this->payload())->assertStatus(202);
        $this->push($this->payload(null, '3900000.00', 2))->assertStatus(202);

        $reports = PeriodReport::withoutBodyScope()->orderBy('sequence')->get();

        $this->assertCount(2, $reports, 'The superseded version must be retained, never overwritten.');

        [$first, $second] = [$reports[0], $reports[1]];

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertSame($second->id, $first->fresh()->superseded_by_id);
        $this->assertSame($first->id, $second->fresh()->supersedes_id);

        // The original figures are still readable, which is the whole point of
        // superseding rather than overwriting.
        $this->assertSame('4200000.00', $first->decodedPayload()['financial']['income_by_group']['70']);
    }

    public function test_restating_a_final_period_is_recorded_as_an_event(): void
    {
        $this->push($this->payload())->assertStatus(202);
        $this->push($this->payload(null, '3900000.00', 2))->assertStatus(202);

        $event = SyncEvent::where('type', SyncEvent::RESTATED)->first();

        $this->assertNotNull($event, 'Restating a period must raise an event for the findings engine.');
        $this->assertSame('final', $event->detail['superseded_status']);
    }

    public function test_out_of_order_delivery_keeps_the_highest_sequence_current(): void
    {
        // An outbox retrying an old report after a newer one already arrived.
        $this->push($this->payload(null, '3900000.00', 2))->assertStatus(202);
        $this->push($this->payload())->assertStatus(202);

        $current = PeriodReport::withoutBodyScope()->where('is_current', true)->get();

        $this->assertCount(1, $current);
        $this->assertSame(2, $current->first()->sequence, 'The latest arrival must not beat the highest sequence.');
    }

    // ---------------------------------------------------------------
    // Meaning
    // ---------------------------------------------------------------

    public function test_an_unbalanced_ledger_is_accepted_and_recorded_as_a_warning(): void
    {
        $payload = PayloadBuilder::for($this->institution->uuid, '2026-08')
            ->status('final')
            ->generatedAt(now()->toIso8601String())
            ->ledger(
                ['70' => Money::of('2000000.00')],
                ['60' => Money::of('1500000.00')],
                Money::of('500000.00'),
                ['opening' => Money::zero(), 'movement' => Money::of('500000.00'), 'closing' => Money::of('500000.00')],
                ['total_debits' => Money::of('5000000.00'), 'total_credits' => Money::of('4750000.00'), 'journal_entry_count' => 120, 'unposted_count' => 0]
            )
            ->build();

        // Accepted, not rejected. Refusing it would make the most alarming report
        // of the year the one that never arrives.
        $response = $this->push($payload)->assertStatus(202);

        $this->assertContains('unbalanced_ledger', $response->json('warnings'));
        $this->assertContains(
            'unbalanced_ledger',
            array_column(PeriodReport::withoutBodyScope()->first()->warningList(), 'code')
        );
    }

    public function test_a_malformed_payload_is_rejected_and_the_rejection_is_visible(): void
    {
        $payload = $this->payload();
        // Money as a JSON number rather than a decimal string.
        $payload['financial']['surplus_deficit'] = 1040000;

        $this->push($payload)->assertStatus(422)->assertJson(['error' => 'invalid_payload']);

        $this->assertDatabaseHas('sync_events', [
            'institution_id' => $this->institution->id,
            'type' => SyncEvent::REJECTED,
        ]);
    }

    public function test_processing_explodes_the_payload_into_figures_and_lines(): void
    {
        $this->push($this->payload())->assertStatus(202);

        $report = PeriodReport::withoutBodyScope()->first();

        $this->assertSame('1040000.00', $report->surplus_deficit);
        $this->assertSame('3340000.00', $report->cash_closing);
        $this->assertSame(431, $report->journal_entry_count);
        $this->assertNotNull($report->processed_at);

        $this->assertSame('4200000.00', $report->lines()->where('ohada_group', '70')->value('amount'));
        $this->assertSame(2, $report->lines()->where('section', 'income')->count());

        // Never declared, so it stays NULL — not 0.
        $this->assertNull($report->receivables_total);
    }

    public function test_an_undeclared_capability_leaves_its_columns_null_rather_than_zero(): void
    {
        $this->push($this->payload())->assertStatus(202);

        $report = PeriodReport::withoutBodyScope()->first();

        foreach (['receivables_total', 'payables_total', 'budgeted', 'staff_cost', 'enrolment'] as $column) {
            $this->assertNull($report->$column, "$column must be NULL, not 0 — missing is not zero.");
        }
    }

    public function test_liveness_is_updated_so_the_console_need_not_scan_reports(): void
    {
        $this->assertNull($this->institution->last_report_at);

        $this->push($this->payload())->assertStatus(202);

        $fresh = $this->institution->fresh();
        $this->assertNotNull($fresh->last_report_at);
        $this->assertSame('2026-08', $fresh->last_period_received);
    }

    public function test_capabilities_that_arrive_are_recorded_as_observed(): void
    {
        $this->push($this->payload())->assertStatus(202);

        $this->assertDatabaseHas('institution_capabilities', [
            'institution_id' => $this->institution->id,
            'capability' => 'ledger',
            'source' => 'observed',
        ]);
    }

    // ---------------------------------------------------------------
    // Heartbeat, contract, rotation
    // ---------------------------------------------------------------

    public function test_a_heartbeat_records_liveness_without_any_figures(): void
    {
        $body = ['note' => 'Nothing new this month.'];
        $json = json_encode($body);
        $timestamp = now()->toIso8601String();

        $this->call('POST', '/api/v1/heartbeat', [], [], [], $this->headers($timestamp, Signer::signRaw($json, $timestamp, $this->secret)), $json)
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertNotNull($this->institution->fresh()->last_heartbeat_at);

        // Alive but with nothing to say — which is a different fact from silence.
        $this->assertSame(0, PeriodReport::withoutBodyScope()->count());
        $this->assertDatabaseHas('sync_events', ['type' => SyncEvent::HEARTBEAT]);
    }

    public function test_a_school_still_on_the_previous_contract_is_accepted(): void
    {
        // Schools upgrade when they can, so 1.0 has to keep working.
        $this->push(['contract_version' => '1.0'] + $this->payload())->assertStatus(202);
    }

    public function test_the_contract_endpoint_is_readable_without_a_signature(): void
    {
        // A client that cannot sign is exactly the one that needs to read this.
        $this->getJson('/api/v1/contract')
            ->assertStatus(200)
            ->assertJson(['contract_version' => ContractVersion::CURRENT])
            ->assertJsonStructure(['capabilities', 'signing' => ['algorithm', 'headers'], 'schema']);
    }

    public function test_rotation_issues_a_new_secret_and_leaves_a_grace_period(): void
    {
        $json = json_encode([]);
        $timestamp = now()->toIso8601String();

        $response = $this->call('POST', '/api/v1/credentials/rotate', [], [], [], $this->headers($timestamp, Signer::signRaw($json, $timestamp, $this->secret)), $json)
            ->assertStatus(201);

        $newSecret = $response->json('secret');
        $this->assertNotSame($this->secret, $newSecret);

        // The new key works.
        $newKeyId = $response->json('key_id');
        $payload = Canonical::encode($this->payload());
        $ts = now()->toIso8601String();

        $this->call('POST', '/api/v1/reports', [], [], [], [
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::KEY_HEADER)) => $newKeyId,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::TIMESTAMP_HEADER)) => $ts,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::SIGNATURE_HEADER)) => Signer::signRaw($payload, $ts, $newSecret),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(202);

        // And the old one still works during the grace window, so a client that
        // crashed before storing the new secret is not locked out.
        $this->push($this->payload(null, '4200000.00', 2))->assertStatus(202);
    }

    public function test_the_report_is_queued_rather_than_processed_inline(): void
    {
        Queue::fake();

        $this->push($this->payload())->assertStatus(202);

        // Accept fast: the bursar may have thirty seconds of hotspot.
        Queue::assertPushed(\App\Jobs\ProcessPeriodReport::class);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $ref = null, string $income = '4200000.00', int $sequence = 1): array
    {
        return PayloadBuilder::for($ref ?? $this->institution->uuid, '2026-08')
            ->status('final')
            ->sequence($sequence)
            ->generatedAt(now()->toIso8601String())
            ->ledger(
                ['70' => Money::of($income), '71' => Money::of('150000.00')],
                ['60' => Money::of('900000.00'), '62' => Money::of('310000.00'), '66' => Money::of('2100000.00')],
                Money::of('1040000.00'),
                ['opening' => Money::of('2300000.00'), 'movement' => Money::of('1040000.00'), 'closing' => Money::of('3340000.00')],
                ['total_debits' => Money::of('12480000.00'), 'total_credits' => Money::of('12480000.00'), 'journal_entry_count' => 431, 'unposted_count' => 0]
            )
            ->build();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(array $payload): \Illuminate\Testing\TestResponse
    {
        $json = Canonical::encode($payload);
        $timestamp = now()->toIso8601String();

        return $this->call(
            'POST',
            '/api/v1/reports',
            [], [], [],
            $this->headers($timestamp, Signer::signRaw($json, $timestamp, $this->secret)),
            $json
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $timestamp, string $signature): array
    {
        return [
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::KEY_HEADER)) => $this->credential->key_id,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::SIGNATURE_HEADER)) => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
