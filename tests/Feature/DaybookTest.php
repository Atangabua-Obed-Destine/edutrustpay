<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyInstitutionSignature as Sig;
use App\Models\AuditLog;
use App\Models\Body;
use App\Models\DaybookFiling;
use App\Models\DaybookLine;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Daybook;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daybooks: how they arrive, who can read them, and how schools are compared.
 */
class DaybookTest extends TestCase
{
    use RefreshDatabase;

    private Body $bamenda;

    private Institution $school;

    private InstitutionCredential $credential;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->bamenda = Body::create(['name' => 'Archdiocese of Bamenda', 'country' => 'Cameroon', 'currency' => 'XAF', 'fiscal_year_start_month' => 1])->fresh();
        $this->school = $this->institution($this->bamenda, 'St Mary');

        $issued = InstitutionCredential::issue($this->school, 'Test');
        $this->credential = $issued['credential'];
        $this->secret = $issued['secret'];
    }

    // ------------------------------------------------------------- arriving

    public function test_a_signed_daybook_is_stored_line_by_line(): void
    {
        $this->push($this->document())->assertStatus(202)->assertJson(['status' => 'accepted', 'lines' => 2]);

        $filing = DaybookFiling::withoutBodyScope()->sole();

        $this->assertTrue($filing->is_current);
        $this->assertSame('150000.00', $filing->money_in);
        $this->assertSame(2, DaybookLine::withoutBodyScope()->where('daybook_filing_id', $filing->id)->count());
    }

    public function test_a_retry_changes_nothing_even_with_a_fresher_timestamp(): void
    {
        $this->push($this->document())->assertStatus(202);

        $this->push($this->document(generatedAt: '2026-11-07T11:00:00Z'))->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, DaybookFiling::withoutBodyScope()->count());
        $this->assertSame(2, DaybookLine::withoutBodyScope()->count());
    }

    public function test_a_correction_keeps_the_old_version(): void
    {
        $this->push($this->document())->assertStatus(202);
        $this->push($this->document(sequence: 2, feeAmount: '175000.00'))->assertStatus(202);

        $this->assertSame(2, DaybookFiling::withoutBodyScope()->count());
        $this->assertSame(4, DaybookLine::withoutBodyScope()->count(), 'Nothing is deleted where money is concerned.');
        $this->assertSame(2, DaybookFiling::withoutBodyScope()->where('is_current', true)->sole()->sequence);
    }

    public function test_changed_lines_at_the_same_sequence_are_refused(): void
    {
        $this->push($this->document(sequence: 3))->assertStatus(202);

        $this->push($this->document(sequence: 3, feeAmount: '175000.00'))
            ->assertStatus(409)
            ->assertJson(['error' => 'sequence_conflict', 'current_sequence' => 3]);
    }

    public function test_an_old_copy_cannot_replace_a_newer_one(): void
    {
        $this->push($this->document(sequence: 3))->assertStatus(202);

        $this->push($this->document(sequence: 2, feeAmount: '1.00'))->assertStatus(409);

        $this->assertSame(3, DaybookFiling::withoutBodyScope()->where('is_current', true)->sole()->sequence);
    }

    public function test_a_school_cannot_file_for_another_school(): void
    {
        $other = $this->institution($this->bamenda, 'St Paul');

        $this->push($this->document(ref: $other->uuid))->assertStatus(422)->assertJson(['error' => 'institution_mismatch']);

        $this->assertSame(0, DaybookFiling::withoutBodyScope()->count());
    }

    public function test_an_unsigned_daybook_is_refused(): void
    {
        $this->postJson('/api/v1/daybook', $this->document())->assertStatus(401);
    }

    public function test_a_daybook_does_not_count_as_the_monthly_report(): void
    {
        $this->push($this->document())->assertStatus(202);

        $this->assertNull($this->school->fresh()->last_report_at, 'Only a report proves a school has reported.');
    }

    // -------------------------------------------------------------- reading

    public function test_the_school_page_shows_the_lines_and_records_who_looked(): void
    {
        $this->push($this->document())->assertStatus(202);
        $user = $this->person('secretary@bamenda.test', 'education_secretary');

        $this->as($user)->get(route('body.institutions.daybook', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertOk()
            ->assertSee('Bih NGWA')
            ->assertSee('Chalk');

        $this->assertSame(1, AuditLog::where('action', 'daybook.viewed')->where('user_id', $user->id)->where('model_id', $this->school->id)->count());
    }

    public function test_the_lines_can_be_searched_and_filtered(): void
    {
        $this->push($this->document())->assertStatus(202);
        $url = fn (array $query) => route('body.institutions.daybook', ['institution' => $this->school, 'period' => '2026-10'] + $query);

        $this->as($this->person('s@bamenda.test', 'education_secretary'));

        $this->get($url(['q' => 'NGWA']))->assertSee('Bih NGWA')->assertDontSee('Chalk');
        $this->get($url(['direction' => 'out']))->assertSee('Chalk')->assertDontSee('Bih NGWA');
        $this->get($url(['kind' => 'expense']))->assertSee('Chalk')->assertDontSee('Bih NGWA');
        $this->get($url(['method' => 'cash']))->assertSee('Bih NGWA')->assertDontSee('Chalk');
        $this->get($url(['q' => '%']))->assertSee('Nothing matches');
    }

    public function test_a_month_not_sent_is_not_shown_as_no_money(): void
    {
        $this->as($this->person('s@bamenda.test', 'education_secretary'))
            ->get(route('body.institutions.daybook', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertOk()
            ->assertSee('has not sent its daybook')
            ->assertDontSee('recorded no money');
    }

    public function test_only_the_current_version_is_shown(): void
    {
        $this->push($this->document())->assertStatus(202);
        $this->push($this->document(sequence: 2, party: 'Someone Else'))->assertStatus(202);

        $this->as($this->person('s@bamenda.test', 'education_secretary'))
            ->get(route('body.institutions.daybook', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertSee('Someone Else')
            ->assertDontSee('Bih NGWA')
            ->assertSee('earlier versions are kept');
    }

    public function test_reading_needs_its_own_permission(): void
    {
        $this->push($this->document())->assertStatus(202);

        $role = Role::create(['name' => 'compare_only', 'display_name' => 'Compare only', 'body_id' => $this->bamenda->id]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['body.view', 'institution.view', 'daybook.compare'])->pluck('id'));
        $user = User::create(['name' => 'C', 'email' => 'c@bamenda.test', 'password' => 'password', 'role' => 'compare_only', 'body_id' => $this->bamenda->id]);
        $user->roles()->attach($role->id);

        $this->as($user);

        $this->get(route('body.institutions.daybook', $this->school))->assertForbidden();
        $this->get(route('body.daybook.compare', ['from' => '2026-10', 'to' => '2026-10']))
            ->assertOk()
            ->assertSee('150 000')
            ->assertDontSee('Bih NGWA');
    }

    public function test_another_diocese_cannot_read_it(): void
    {
        $this->push($this->document())->assertStatus(202);
        $buea = Body::create(['name' => 'Diocese of Buea', 'country' => 'Cameroon', 'currency' => 'XAF', 'fiscal_year_start_month' => 1])->fresh();
        $outsider = User::create(['name' => 'O', 'email' => 'o@buea.test', 'password' => 'password', 'role' => 'education_secretary', 'body_id' => $buea->id]);
        $outsider->roles()->attach(Role::where('name', 'education_secretary')->firstOrFail()->id);

        $this->as($outsider);

        $this->get(route('body.institutions.daybook', $this->school))->assertNotFound();
        $this->get(route('body.daybook.compare', ['from' => '2026-10', 'to' => '2026-10']))->assertOk()->assertDontSee('St Mary')->assertDontSee('150 000');
    }

    // ------------------------------------------------------------ comparing

    public function test_schools_are_compared_and_a_missing_month_is_stated(): void
    {
        $this->push($this->document())->assertStatus(202);

        $this->as($this->person('s@bamenda.test', 'education_secretary'))
            ->get(route('body.daybook.compare', ['from' => '2026-09', 'to' => '2026-10']))
            ->assertOk()
            ->assertSee('1 of 2')
            ->assertSee('not sent')
            ->assertSee('Not every school has sent every month')
            // Fees are all of its income, and every franc with a method was cash.
            ->assertSee('100%')
            ->assertDontSee('Total, every school');
    }

    public function test_salaries_paid_outside_payroll_are_not_read_as_no_staff_pay(): void
    {
        $this->push($this->document())->assertStatus(202);

        $this->as($this->person('s@bamenda.test', 'education_secretary'))
            ->get(route('body.daybook.compare', ['from' => '2026-10', 'to' => '2026-10']))
            ->assertOk()
            ->assertSee('salaries not recorded through payroll')
            ->assertDontSee('>0%<', false);
    }

    public function test_a_school_with_nothing_sent_never_reads_as_zero(): void
    {
        $this->institution($this->bamenda, 'St Paul');
        $this->push($this->document())->assertStatus(202);

        $this->as($this->person('s@bamenda.test', 'education_secretary'))
            ->get(route('body.daybook.compare', ['from' => '2026-10', 'to' => '2026-10']))
            ->assertOk()
            ->assertSee('St Paul')
            ->assertSee('no daybook sent for these months');
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function document(
        int $sequence = 1,
        string $feeAmount = '150000.00',
        string $party = 'Bih NGWA',
        ?string $ref = null,
        string $generatedAt = '2026-11-07T10:00:00Z'
    ): array {
        return Daybook::build($ref ?? $this->school->uuid, '2026-10', $sequence, $generatedAt, 'XAF', [
            ['date' => '2026-10-02', 'ref' => 'R-1', 'description' => $party.' — Tuition', 'party' => $party, 'heading' => 'Tuition', 'kind' => 'fee', 'direction' => 'in', 'amount' => Money::of($feeAmount), 'method' => 'cash', 'source_id' => 'fee:1'],
            ['date' => '2026-10-03', 'ref' => 'E-1', 'description' => 'Chalk', 'heading' => 'Supplies', 'kind' => 'expense', 'direction' => 'out', 'amount' => Money::of('40000.00'), 'source_id' => 'expense:1'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function push(array $document): \Illuminate\Testing\TestResponse
    {
        $json = Canonical::encode($document);
        $timestamp = now()->toIso8601String();

        return $this->call('POST', '/api/v1/daybook', [], [], [], [
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::KEY_HEADER)) => $this->credential->key_id,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_'.str_replace('-', '_', strtoupper(Sig::SIGNATURE_HEADER)) => Signer::signRaw($json, $timestamp, $this->secret),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function as(User $user): self
    {
        $this->actingAs($user);
        BodyContext::flush();

        return $this;
    }

    private function person(string $email, string $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'password', 'role' => $role, 'body_id' => $this->bamenda->id]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail()->id);

        return $user;
    }

    private function institution(Body $body, string $name): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
        ]);
    }
}
