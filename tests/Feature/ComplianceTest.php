<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Models\Regulation;
use App\Models\RegulationMapping;
use App\Models\Role;
use App\Models\User;
use App\Services\ComplianceService;
use App\Support\BodyContext;
use EdutrustPay\Contract\Canonical;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The compliance board.
 *
 * The rule that matters most: a school can never look compliant by saying nothing.
 */
class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Body $bamenda;

    private Body $deanery;

    private Body $buea;

    private Institution $school;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();
        $this->travelTo('2026-11-07 10:00:00');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->bamenda = $this->makeBody('Diocese of Bamenda');
        $this->deanery = $this->makeBody('Central Deanery', $this->bamenda);
        $this->buea = $this->makeBody('Diocese of Buea');
        $this->school = $this->institution($this->bamenda, 'St Mary');
        $this->secretary = $this->person('secretary@bamenda.test', 'education_secretary', $this->bamenda);
    }

    // ---------------------------------------------------------- the checks

    public function test_a_school_that_sent_nothing_cannot_be_checked_rather_than_complying(): void
    {
        $this->assertSame(ComplianceService::UNKNOWN, $this->check($this->schedule())->state);
    }

    public function test_charging_within_the_schedule_complies(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '100000.00', 30]]);

        $this->assertSame(ComplianceService::COMPLIES, $this->check($this->schedule())->state);
    }

    public function test_charging_above_the_schedule_is_a_breach(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '125000.00', 30]]);

        $result = $this->check($this->schedule());

        $this->assertSame(ComplianceService::BREACH, $result->state);
        $this->assertSame('over', $result->lines->first()->state);
        $this->assertSame('25000.00', $result->lines->first()->difference);
    }

    public function test_charging_less_is_fine_under_a_maximum_but_not_under_an_exact_rule(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '80000.00', 30]]);

        $this->assertSame(ComplianceService::COMPLIES, $this->check($this->schedule())->state);
        $this->assertSame(ComplianceService::BREACH, $this->check($this->schedule(['fee_mode' => 'exact']))->state);
    }

    public function test_a_fee_the_schedule_does_not_know_waits_for_a_person(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '100000.00', 30], ['Form 1', 'Mystery Levy', '5000.00', 30]]);

        $result = $this->check($this->schedule());

        $this->assertSame(ComplianceService::UNKNOWN, $result->state, 'Neither fine nor wrong until somebody looks.');
        $this->assertStringContainsString('not matched', $result->summary);
    }

    public function test_a_school_fee_can_be_matched_to_a_differently_named_line(): void
    {
        $this->reportFees([['Form One', 'School Fees', '125000.00', 30]]);
        $rule = $this->schedule();

        $this->assertSame(ComplianceService::UNKNOWN, $this->check($rule)->state);

        RegulationMapping::create([
            'regulation_id' => $rule->id,
            'institution_id' => $this->school->id,
            'school_level' => 'Form One',
            'school_category' => 'School Fees',
            'regulation_fee_item_id' => $rule->feeItems->first()->id,
        ]);

        $this->assertSame(ComplianceService::BREACH, $this->check($rule->fresh('feeItems'))->state);
    }

    public function test_two_amounts_in_one_class_are_flagged(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '90000.00', 2], ['Form 1', 'Tuition', '100000.00', 28]]);

        $this->assertTrue($this->check($this->schedule())->lines->first()->varies);
    }

    public function test_old_fee_figures_cannot_be_relied_on(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '100000.00', 30]], '2026-06');

        $this->assertSame(ComplianceService::UNKNOWN, $this->check($this->schedule())->state);
    }

    public function test_figures_from_before_the_rule_began_do_not_count(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '100000.00', 30]], '2026-09');

        $this->assertSame(ComplianceService::UNKNOWN, $this->check($this->schedule(['effective_from' => '2026-10-01']))->state);
    }

    public function test_a_school_still_being_migrated_is_not_judged(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '999999.00', 30]]);
        $this->school->update(['live_from' => '2027-01-01']);

        $result = $this->check($this->schedule());

        $this->assertSame(ComplianceService::NOT_LIVE, $result->state);
        $this->assertSame(ComplianceService::BREACH, $result->found, 'What it found is kept, not discarded.');
    }

    public function test_a_rule_for_another_type_of_school_does_not_apply(): void
    {
        $rule = $this->schedule(['applies_to' => 'school_higher']);

        $board = app(ComplianceService::class)->board(new EloquentCollection([$this->school->load('body')]));

        $this->assertNull($board['rows'][0]->cells[$rule->id]);
    }

    public function test_a_retired_rule_leaves_the_board(): void
    {
        $this->schedule(['is_active' => false]);

        $this->assertCount(0, app(ComplianceService::class)->board(new EloquentCollection([$this->school->load('body')]))['regulations']);
    }

    public function test_a_diocese_rule_binds_a_school_in_its_deanery_but_not_the_other_way_round(): void
    {
        $inDeanery = $this->institution($this->deanery, 'Deanery School');
        $rule = $this->schedule();

        $this->assertTrue($rule->coversInstitution($inDeanery->load('body')));

        $deaneryRule = $this->schedule(['body_id' => $this->deanery->id]);

        $this->assertFalse($deaneryRule->coversInstitution($this->school->load('body')));
    }

    // ---------------------------------------------------------- the screens

    public function test_the_secretary_can_write_a_rule_and_see_the_result(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '125000.00', 30]]);

        $this->as($this->secretary)->post(route('body.compliance.regulations.store'), [
            'title' => 'Secondary fees',
            'area' => 'fees',
            'check_type' => Regulation::FEE_SCHEDULE,
            'fee_mode' => 'maximum',
            'effective_from' => '2026-01-01',
            'items' => [['level' => 'Form 1', 'category' => 'Tuition', 'amount' => '100 000']],
        ])->assertRedirect(route('body.compliance.index'))->assertSessionHasNoErrors();

        $rule = Regulation::where('title', 'Secondary fees')->firstOrFail();

        $this->assertSame($this->bamenda->id, $rule->body_id);
        $this->assertSame('100000.00', $rule->feeItems->first()->amount);

        $this->get(route('body.compliance.index'))->assertOk()->assertSee('Secondary fees')->assertSee('rule broken');
        $this->get(route('body.compliance.show', [$rule->id, $this->school]))->assertOk()->assertSee('125 000')->assertSee('above schedule');
    }

    public function test_a_fee_schedule_needs_real_lines(): void
    {
        $this->as($this->secretary)->post(route('body.compliance.regulations.store'), [
            'title' => 'Empty',
            'area' => 'fees',
            'check_type' => Regulation::FEE_SCHEDULE,
            'effective_from' => '2026-01-01',
            'items' => [['level' => 'Form 1', 'category' => 'Tuition', 'amount' => 'lots']],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseMissing('regulations', ['title' => 'Empty']);
    }

    public function test_changing_a_price_keeps_a_schools_matching(): void
    {
        $this->reportFees([['Form One', 'School Fees', '100000.00', 30]]);
        $rule = $this->schedule();
        $itemId = $rule->feeItems->first()->id;

        RegulationMapping::create([
            'regulation_id' => $rule->id,
            'institution_id' => $this->school->id,
            'school_level' => 'Form One',
            'school_category' => 'School Fees',
            'regulation_fee_item_id' => $itemId,
        ]);

        $this->as($this->secretary)->put(route('body.compliance.regulations.update', $rule), [
            'title' => $rule->title,
            'area' => 'fees',
            'check_type' => Regulation::FEE_SCHEDULE,
            'effective_from' => '2026-01-01',
            'items' => [['level' => 'Form 1', 'category' => 'Tuition', 'amount' => '90000']],
        ])->assertSessionHasNoErrors();

        $this->assertSame($itemId, $rule->feeItems()->first()->id);
        $this->assertSame(ComplianceService::BREACH, $this->check($rule->fresh('feeItems'))->state);
    }

    public function test_matching_saves_through_the_screen(): void
    {
        $this->reportFees([['Form One', 'School Fees', '125000.00', 30], ['Form One', 'Club', '500.00', 3]]);
        $rule = $this->schedule();

        $this->as($this->secretary)->post(route('body.compliance.mappings', [$rule->id, $this->school]), [
            'mappings' => [
                ['level' => 'Form One', 'category' => 'School Fees', 'target' => (string) $rule->feeItems->first()->id],
                ['level' => 'Form One', 'category' => 'Club', 'target' => 'ignore'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(ComplianceService::BREACH, $this->check($rule->fresh('feeItems'))->state);
    }

    public function test_a_line_from_another_rule_cannot_be_used_for_matching(): void
    {
        $rule = $this->schedule();
        $other = $this->schedule(['title' => 'Other']);

        $this->as($this->secretary)->post(route('body.compliance.mappings', [$rule->id, $this->school]), [
            'mappings' => [['level' => 'A', 'category' => 'B', 'target' => (string) $other->feeItems->first()->id]],
        ])->assertSessionHasErrors();
    }

    public function test_a_waiver_needs_an_end_date_and_ends_by_itself(): void
    {
        $this->reportFees([['Form 1', 'Tuition', '125000.00', 30]]);
        $rule = $this->schedule();

        $this->as($this->secretary);

        $this->post(route('body.compliance.waiver', [$rule->id, $this->school]), ['reason' => 'Bishop approved'])
            ->assertSessionHasErrors('expires_on');

        $this->post(route('body.compliance.waiver', [$rule->id, $this->school]), [
            'reason' => 'Bishop approved the increase',
            'expires_on' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $result = $this->check($rule);

        $this->assertSame(ComplianceService::WAIVED, $result->state);
        $this->assertSame(ComplianceService::BREACH, $result->found, 'A waiver never hides what was found.');

        $this->travelTo('2027-01-01');
        $this->reportFees([['Form 1', 'Tuition', '125000.00', 30]], '2026-12');

        $this->assertSame(ComplianceService::BREACH, $this->check($rule)->state);
    }

    public function test_a_rule_confirmed_by_a_person_says_so_and_expires(): void
    {
        $rule = $this->attestationRule();

        $this->assertSame(ComplianceService::UNKNOWN, $this->check($rule)->state);

        $this->as($this->secretary)->post(route('body.compliance.attest', [$rule->id, $this->school]), [
            'outcome' => 'complies',
            'note' => 'Visited and saw the minutes',
            'valid_until' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $result = $this->check($rule);

        $this->assertSame(ComplianceService::COMPLIES, $result->state);
        $this->assertStringContainsString('not checked by the system', $result->summary);

        $this->travelTo('2027-01-05');

        $this->assertSame(ComplianceService::UNKNOWN, $this->check($rule)->state);
    }

    public function test_a_follow_up_can_be_opened_assigned_and_closed(): void
    {
        $rule = $this->schedule();
        $colleague = $this->person('colleague@bamenda.test', 'auditor', $this->bamenda);

        $this->as($this->secretary)->post(route('body.compliance.cases.open', [$rule->id, $this->school]), [
            'note' => 'Ask the principal about the increase',
            'assigned_to' => $colleague->id,
            'due_on' => '2026-11-20',
        ])->assertSessionHasNoErrors();

        $this->get(route('body.compliance.index'))->assertSee('Open follow-ups')->assertSee($colleague->name);

        $case = \App\Models\ComplianceCase::firstOrFail();

        $this->travelTo('2026-11-25');
        $this->assertTrue($case->isOverdue());

        $this->post(route('body.compliance.cases.close', [$rule->id, $this->school, $case]), ['outcome' => 'Refunded the difference'])
            ->assertSessionHasNoErrors();

        $this->assertSame('closed', $case->fresh()->status);
    }

    public function test_a_follow_up_cannot_be_given_to_somebody_in_another_diocese(): void
    {
        $rule = $this->schedule();
        $outsider = $this->person('x@buea.test', 'auditor', $this->buea);

        $this->as($this->secretary)->post(route('body.compliance.cases.open', [$rule->id, $this->school]), [
            'note' => 'Should not work',
            'assigned_to' => $outsider->id,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------- tenancy

    public function test_another_diocese_cannot_see_or_touch_these(): void
    {
        $rule = $this->schedule();
        $outsider = $this->person('sec@buea.test', 'education_secretary', $this->buea);

        $this->as($outsider);

        $this->get(route('body.compliance.index'))->assertOk()->assertDontSee('St Mary')->assertDontSee($rule->title);
        $this->get(route('body.compliance.show', [$rule->id, $this->school]))->assertNotFound();
        $this->get(route('body.compliance.regulations.edit', $rule))->assertNotFound();
        $this->post(route('body.compliance.regulations.toggle', $rule))->assertNotFound();
        $this->post(route('body.compliance.waiver', [$rule->id, $this->school]), ['reason' => 'mine now', 'expires_on' => '2027-01-01'])->assertNotFound();

        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_one_dioceses_rule_cannot_be_applied_to_another_dioceses_school(): void
    {
        $theirRule = $this->schedule(['body_id' => $this->buea->id]);

        $this->as($this->secretary)->get(route('body.compliance.show', [$theirRule->id, $this->school]))->assertNotFound();
    }

    public function test_a_deanery_sees_its_dioceses_rule_but_cannot_change_it(): void
    {
        $school = $this->institution($this->deanery, 'Deanery School');
        $rule = $this->schedule();
        $dean = $this->person('dean@bamenda.test', 'education_secretary', $this->deanery);

        $this->as($dean);

        $this->get(route('body.compliance.index'))->assertOk()->assertSee($rule->title);
        $this->get(route('body.compliance.show', [$rule->id, $school]))->assertOk();
        $this->get(route('body.compliance.regulations.edit', $rule))->assertNotFound();
    }

    public function test_an_auditor_can_see_the_board_but_change_nothing(): void
    {
        $rule = $this->schedule();
        $auditor = $this->person('auditor@bamenda.test', 'auditor', $this->bamenda);

        $this->as($auditor);

        $this->get(route('body.compliance.index'))->assertOk()->assertDontSee('New rule');
        $this->get(route('body.compliance.regulations.create'))->assertForbidden();
        $this->post(route('body.compliance.waiver', [$rule->id, $this->school]), ['reason' => 'nope nope', 'expires_on' => '2027-01-01'])->assertForbidden();
        $this->post(route('body.compliance.cases.open', [$rule->id, $this->school]), ['note' => 'nope nope'])->assertForbidden();
    }

    public function test_view_as_can_read_the_board_but_change_nothing(): void
    {
        $rule = $this->schedule();
        $operator = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'password', 'role' => 'super_admin']);

        $this->as($operator)->post(route('admin.bodies.impersonate', $this->bamenda));
        BodyContext::flush();

        $this->get(route('body.compliance.index'))->assertOk();
        $this->post(route('body.compliance.regulations.toggle', $rule))->assertForbidden();
    }

    // ------------------------------------------------------------- helpers

    private function check(Regulation $rule): object
    {
        return app(ComplianceService::class)->check($rule->loadMissing('feeItems'), $this->school->fresh(['body']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function schedule(array $overrides = []): Regulation
    {
        $rule = Regulation::withoutBodyScope()->create($overrides + [
            'body_id' => $this->bamenda->id,
            'area' => 'fees',
            'check_type' => Regulation::FEE_SCHEDULE,
            'title' => 'Fees 2026',
            'fee_mode' => 'maximum',
            'effective_from' => '2026-01-01',
        ]);

        $rule->feeItems()->create(['level' => 'Form 1', 'category' => 'Tuition', 'amount' => '100000.00']);

        return $rule->load('feeItems');
    }

    private function attestationRule(): Regulation
    {
        return Regulation::withoutBodyScope()->create([
            'body_id' => $this->bamenda->id,
            'area' => 'other',
            'check_type' => Regulation::ATTESTATION,
            'title' => 'PTA meeting each term',
            'effective_from' => '2026-01-01',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: int}>  $rows
     */
    private function reportFees(array $rows, string $period = '2026-10'): PeriodReport
    {
        $payload = Canonical::encode([
            'contract_version' => '1.1',
            'capabilities' => ['fees_charged'],
            'period' => $period,
            'operational' => ['fees_charged' => [
                'session' => '2026/2027',
                'rows' => array_map(fn ($r) => ['level' => $r[0], 'category' => $r[1], 'amount' => $r[2], 'students' => $r[3]], $rows),
            ]],
        ]);

        PeriodReport::withoutBodyScope()->where('institution_id', $this->school->id)->where('period', $period)->delete();

        return PeriodReport::withoutBodyScope()->create([
            'institution_id' => $this->school->id,
            'body_id' => $this->school->body_id,
            'period' => $period,
            'sequence' => 1,
            'status' => 'provisional',
            'is_current' => true,
            'contract_version' => '1.1',
            'currency' => 'XAF',
            'capabilities' => ['fees_charged'],
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'signature' => str_repeat('a', 64),
            'key_id' => 'etp_test',
            'generated_at' => now(),
            'received_at' => now(),
        ]);
    }

    private function as(User $user): self
    {
        $this->actingAs($user);
        BodyContext::flush();

        return $this;
    }

    private function makeBody(string $name, ?Body $parent = null): Body
    {
        return Body::create([
            'name' => $name,
            'parent_body_id' => $parent?->id,
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();
    }

    private function person(string $email, string $roleName, Body $body): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'password', 'role' => $roleName, 'body_id' => $body->id]);
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id);

        return $user;
    }

    private function institution(Body $body, string $name): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => 'school_secondary',
            'system' => 'edutrustshsm',
        ]);
    }
}
