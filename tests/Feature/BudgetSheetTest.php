<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyInstitutionSignature as Sig;
use App\Models\Body;
use App\Models\BudgetSheet as Sheet;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use EdutrustPay\Contract\BudgetSheet;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Money;
use EdutrustPay\Contract\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Budget sheets: how they arrive and how the body reads them.
 */
class BudgetSheetTest extends TestCase
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
        $this->school = Institution::withoutBodyScope()->create(['body_id' => $this->bamenda->id, 'name' => 'St Mary', 'type' => 'school_secondary', 'system' => 'edutrustshsm']);

        $issued = InstitutionCredential::issue($this->school, 'Test');
        $this->credential = $issued['credential'];
        $this->secret = $issued['secret'];
    }

    public function test_a_signed_sheet_is_stored_with_its_totals(): void
    {
        $this->push($this->sheet())->assertStatus(202);

        $sheet = Sheet::withoutBodyScope()->sole();

        $this->assertSame('1000000.00', $sheet->expenditure_budget);
        $this->assertSame('450000.00', $sheet->expenditure_to_date);
        $this->assertSame(45, $sheet->spentShare());
    }

    public function test_a_retry_changes_nothing_and_a_correction_keeps_the_old_version(): void
    {
        $this->push($this->sheet())->assertStatus(202);
        $this->push($this->sheet(generatedAt: '2026-11-08T00:00:00Z'))->assertStatus(200);
        $this->push($this->sheet(sequence: 2, fuelSoFar: '700000.00'))->assertStatus(202);

        $this->assertSame(2, Sheet::withoutBodyScope()->count());
        $this->assertSame(2, Sheet::withoutBodyScope()->where('is_current', true)->sole()->sequence);
    }

    public function test_the_sheet_page_shows_lines_and_flags_overspending(): void
    {
        $this->push($this->sheet(fuelSoFar: '700000.00'))->assertStatus(202);

        $this->as($this->person())
            ->get(route('body.institutions.budget', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertOk()
            ->assertSee('Fuel')
            ->assertSee('over budget')
            ->assertSee('Year gone');
    }

    public function test_a_month_not_sent_says_so(): void
    {
        $this->as($this->person())
            ->get(route('body.institutions.budget', ['institution' => $this->school, 'period' => '2026-10']))
            ->assertOk()
            ->assertSee('has not sent a budget sheet');
    }

    public function test_the_overview_shows_how_much_of_the_budget_is_used(): void
    {
        $this->push($this->sheet())->assertStatus(202);

        $this->as($this->person())
            ->get(route('body.dashboard', ['period' => '2026-10']))
            ->assertOk()
            ->assertSee('Budget used')
            ->assertSee('45%');
    }

    public function test_another_diocese_cannot_read_it(): void
    {
        $this->push($this->sheet())->assertStatus(202);
        $buea = Body::create(['name' => 'Diocese of Buea', 'country' => 'Cameroon', 'currency' => 'XAF', 'fiscal_year_start_month' => 1])->fresh();
        $outsider = User::create(['name' => 'O', 'email' => 'o@buea.test', 'password' => 'password', 'role' => 'education_secretary', 'body_id' => $buea->id]);
        $outsider->roles()->attach(Role::where('name', 'education_secretary')->firstOrFail()->id);

        $this->as($outsider)->get(route('body.institutions.budget', $this->school))->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(int $sequence = 1, string $fuelSoFar = '300000.00', string $generatedAt = '2026-11-07T10:00:00Z'): array
    {
        $m = fn (string $v) => Money::of($v);

        return BudgetSheet::build($this->school->uuid, '2026-10', $sequence, $generatedAt, 'XAF',
            ['title' => '2026/2027', 'starts' => '2026-09-01', 'ends' => '2027-08-31'],
            [
                ['code' => '6.1', 'name' => 'Fuel', 'section' => 'expenditure', 'heading' => false, 'budget' => $m('600000.00'), 'month' => $m('50000.00'), 'to_date' => $m($fuelSoFar)],
                ['code' => '6.2', 'name' => 'Stationery', 'section' => 'expenditure', 'heading' => false, 'budget' => $m('400000.00'), 'month' => $m('0.00'), 'to_date' => $m('150000.00')],
                ['code' => '7.1', 'name' => 'Tuition', 'section' => 'income', 'heading' => false, 'budget' => $m('5000000.00'), 'month' => $m('900000.00'), 'to_date' => $m('2000000.00')],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function push(array $document): \Illuminate\Testing\TestResponse
    {
        $json = Canonical::encode($document);
        $timestamp = now()->toIso8601String();

        return $this->call('POST', '/api/v1/budget-sheet', [], [], [], [
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

    private function person(): User
    {
        $user = User::create(['name' => 'S', 'email' => 's@bamenda.test', 'password' => 'password', 'role' => 'education_secretary', 'body_id' => $this->bamenda->id]);
        $user->roles()->attach(Role::where('name', 'education_secretary')->firstOrFail()->id);

        return $user;
    }
}
