<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\BudgetSheet;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every school's budget for the year, compared on its totals: budgeted against
 * so far, beside how much of each school's own year has gone.
 */
class BudgetCompareTest extends TestCase
{
    use RefreshDatabase;

    private Body $body;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);
    }

    private function school(string $name): Institution
    {
        return Institution::withoutBodyScope()->create(['body_id' => $this->body->id, 'name' => $name, 'type' => 'school_secondary', 'system' => 'edutrustshsm']);
    }

    /**
     * @param  array{0: float, 1: float}  $income  budget, to date
     * @param  array{0: float, 1: float}  $spending
     * @param  array{0: float, 1: float}  $capital
     */
    private function sheet(Institution $school, array $income, array $spending, array $capital = [0, 0], string $period = '2026-10'): void
    {
        $totals = [
            'income' => ['budget' => number_format($income[0], 2, '.', ''), 'month' => '0.00', 'to_date' => number_format($income[1], 2, '.', '')],
            'expenditure' => ['budget' => number_format($spending[0], 2, '.', ''), 'month' => '0.00', 'to_date' => number_format($spending[1], 2, '.', '')],
            'capital' => ['budget' => number_format($capital[0], 2, '.', ''), 'month' => '0.00', 'to_date' => number_format($capital[1], 2, '.', '')],
        ];

        BudgetSheet::withoutGlobalScopes()->create([
            'body_id' => $this->body->id,
            'institution_id' => $school->id,
            'period' => $period,
            'sequence' => 1,
            'is_current' => true,
            'currency' => 'XAF',
            'budget_title' => '2026/2027',
            'budget_starts' => '2026-09-01',
            'budget_ends' => '2027-08-31',
            'income_budget' => $income[0],
            'income_to_date' => $income[1],
            'expenditure_budget' => $spending[0],
            'expenditure_to_date' => $spending[1],
            'payload' => json_encode(['totals' => $totals, 'lines' => []]),
            'payload_hash' => hash('sha256', $school->name.$period),
            'generated_at' => now(),
            'received_at' => now(),
        ]);
    }

    private function as(string $role): self
    {
        $user = User::create(['body_id' => $this->body->id, 'name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'password', 'role' => $role]);
        $user->roles()->sync([Role::where('name', $role)->value('id')]);
        $this->actingAs($user);
        BodyContext::flush();

        return $this;
    }

    public function test_each_school_is_shown_on_its_totals_against_how_much_of_its_year_has_gone(): void
    {
        $this->sheet($this->school('PAXHI'), [60_000_000, 6_000_000], [50_000_000, 4_000_000], [1_000_000, 0]);
        // A quarter of the spending gone, with only 17% of the year: ahead of the year.
        $this->sheet($this->school('St Jerome'), [20_000_000, 4_000_000], [18_000_000, 6_300_000]);

        $this->as('education_secretary')
            ->get(route('body.budgets.compare', ['period' => '2026-10']))
            ->assertOk()
            ->assertSeeInOrder(['PAXHI', '2026/2027 budget', '17% of the year gone', 'Income received', '10%', '6 000 000', '60 000 000', 'Spending used', '8%'])
            ->assertSeeInOrder(['St Jerome', 'Spending used', '35%', 'ahead of the year'])
            ->assertSeeInOrder(['Planned for the year', 'surplus 9 000 000'])
            ->assertSeeInOrder(['All 2 schools', '80 000 000', '10 000 000']);
    }

    public function test_a_school_with_no_budget_sheet_says_so_and_the_total_says_whose_it_holds(): void
    {
        $this->sheet($this->school('PAXHI'), [60_000_000, 6_000_000], [50_000_000, 4_000_000]);
        $this->school('St Jerome');

        $this->as('bursary_head')
            ->get(route('body.budgets.compare', ['period' => '2026-10']))
            ->assertOk()
            ->assertSee('No budget sheet for October 2026')
            ->assertSee('Total for 1 of 2 schools')
            ->assertSee('The total leaves out the schools with no budget sheet for this month.');
    }

    public function test_nothing_budgeted_reads_as_words_not_as_zero_percent(): void
    {
        $this->sheet($this->school('PAXHI'), [0, 2_201_066], [0, 950]);

        $this->as('education_secretary')
            ->get(route('body.budgets.compare', ['period' => '2026-10']))
            ->assertOk()
            ->assertSee('nothing budgeted')
            ->assertDontSee('ahead of the year');
    }

    public function test_the_page_needs_the_permission_to_see_schools_added_up(): void
    {
        $this->as('institution_liaison')->get(route('body.budgets.compare'))->assertForbidden();
        $this->as('auditor')->get(route('body.budgets.compare'))->assertOk();
    }
}
