<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Finding;
use App\Models\Institution;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenancy.
 *
 * Brief §15 requires that a body user provably cannot read another body's data,
 * and brief §12 requires that this be enforced by a global scope rather than by
 * remembering to filter. This is the failure that would be least visible and
 * most damaging: nothing breaks, no error appears, and one diocese quietly sees
 * another's money.
 *
 * The tree used throughout:
 *
 *   Archdiocese of Bamenda          (root)
 *   ├── Bamenda Central Deanery
 *   │   └── St Joseph Parish
 *   └── Kumbo Deanery
 *   Diocese of Buea                 (a separate root — must never be visible)
 */
class BodyTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Body $archdiocese;

    private Body $centralDeanery;

    private Body $stJoseph;

    private Body $kumbo;

    private Body $buea;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * BodyContext memoizes per request, and in production ResolveBodyContext
         * clears it at the start of every web request. A test that calls
         * actingAs() never goes through that middleware, so without this flush
         * the memo survives from the previous test and one test's accessible
         * bodies silently decide the next one's results. That is the same class
         * of bug as leaking between requests under Octane — see
         * test_the_context_memo_does_not_leak_between_users.
         */
        BodyContext::flush();

        $this->archdiocese = $this->makeBody('Archdiocese of Bamenda');
        $this->centralDeanery = $this->makeBody('Bamenda Central Deanery', $this->archdiocese);
        $this->stJoseph = $this->makeBody('St Joseph Parish', $this->centralDeanery);
        $this->kumbo = $this->makeBody('Kumbo Deanery', $this->archdiocese);
        $this->buea = $this->makeBody('Diocese of Buea');
    }

    // ---------------------------------------------------------------
    // The materialised path
    // ---------------------------------------------------------------

    public function test_the_path_records_ancestry_and_depth(): void
    {
        $this->assertSame('/'.$this->archdiocese->id.'/', $this->archdiocese->path);
        $this->assertSame(0, $this->archdiocese->depth);

        $this->assertSame(
            '/'.$this->archdiocese->id.'/'.$this->centralDeanery->id.'/',
            $this->centralDeanery->fresh()->path
        );
        $this->assertSame(1, $this->centralDeanery->fresh()->depth);
        $this->assertSame(2, $this->stJoseph->fresh()->depth);
    }

    public function test_a_subtree_is_the_body_and_everything_beneath_it(): void
    {
        $ids = $this->archdiocese->fresh()->subtreeIds();

        $this->assertEqualsCanonicalizing(
            [$this->archdiocese->id, $this->centralDeanery->id, $this->stJoseph->id, $this->kumbo->id],
            $ids
        );

        $this->assertNotContains($this->buea->id, $ids);
    }

    public function test_a_deanery_subtree_does_not_reach_sideways_or_upwards(): void
    {
        $ids = $this->centralDeanery->fresh()->subtreeIds();

        $this->assertEqualsCanonicalizing([$this->centralDeanery->id, $this->stJoseph->id], $ids);
        $this->assertNotContains($this->archdiocese->id, $ids, 'A deanery must not see its parent.');
        $this->assertNotContains($this->kumbo->id, $ids, 'A deanery must not see a sibling.');
    }

    public function test_the_trailing_slash_stops_a_body_matching_a_longer_id(): void
    {
        /*
         * Without the trailing slash, a subtree query for body 1 would be
         * `path LIKE '/1%'`, which also matches body 11, 12, 100... — one
         * diocese silently absorbing another purely because of how its id was
         * numbered. Create enough roots that a genuine prefix pair exists.
         */
        $roots = [$this->archdiocese, $this->buea];

        while (count($roots) < 12) {
            $roots[] = $this->makeBody('Root '.count($roots));
        }

        $shortId = $roots[0]->fresh();
        $prefixed = Body::where('id', 'like', $shortId->id.'_')->first();

        $this->assertNotNull($prefixed, 'Expected a body whose id is prefixed by another.');
        $this->assertStringStartsWith((string) $shortId->id, (string) $prefixed->id);

        $this->assertNotContains(
            $prefixed->id,
            $shortId->subtreeIds(),
            "Body {$shortId->id} must not swallow body {$prefixed->id}."
        );
    }

    // ---------------------------------------------------------------
    // The global scope
    // ---------------------------------------------------------------

    public function test_a_body_user_cannot_read_another_bodys_institution_by_id(): void
    {
        $ours = $this->makeInstitution($this->centralDeanery, 'St Bede College');
        $theirs = $this->makeInstitution($this->buea, 'Buea Clinic');

        $this->actingAsBodyUser($this->makeUser($this->centralDeanery));

        // The direct, deliberate attempt: ask for it by primary key.
        $this->assertNull(
            Institution::find($theirs->id),
            'A body user fetched another body\'s institution by id.'
        );

        $this->assertNotNull(Institution::find($ours->id));
    }

    public function test_a_body_user_cannot_read_another_bodys_findings(): void
    {
        $theirs = $this->makeFinding($this->buea, $this->makeInstitution($this->buea, 'Buea Clinic'));

        $this->actingAsBodyUser($this->makeUser($this->centralDeanery));

        $this->assertNull(Finding::find($theirs->id));
        $this->assertSame(0, Finding::count());
    }

    public function test_a_parent_body_user_sees_the_whole_subtree(): void
    {
        $this->makeInstitution($this->archdiocese, 'Archdiocesan Office');
        $this->makeInstitution($this->centralDeanery, 'St Bede College');
        $this->makeInstitution($this->stJoseph, 'St Joseph Primary');
        $this->makeInstitution($this->kumbo, 'Kumbo Hospital');
        $this->makeInstitution($this->buea, 'Buea Clinic');

        $this->actingAsBodyUser($this->makeUser($this->archdiocese));

        // Four, not five: everything under the Archdiocese, and nothing in Buea.
        $this->assertSame(4, Institution::count());
        $this->assertNotContains('Buea Clinic', Institution::pluck('name')->all());
    }

    public function test_a_deanery_user_sees_their_own_deanery_and_below_only(): void
    {
        $this->makeInstitution($this->archdiocese, 'Archdiocesan Office');
        $this->makeInstitution($this->centralDeanery, 'St Bede College');
        $this->makeInstitution($this->stJoseph, 'St Joseph Primary');
        $this->makeInstitution($this->kumbo, 'Kumbo Hospital');

        $this->actingAsBodyUser($this->makeUser($this->centralDeanery));

        $this->assertEqualsCanonicalizing(
            ['St Bede College', 'St Joseph Primary'],
            Institution::pluck('name')->all()
        );
    }

    public function test_the_operator_sees_every_body(): void
    {
        $this->makeInstitution($this->archdiocese, 'Archdiocesan Office');
        $this->makeInstitution($this->buea, 'Buea Clinic');

        $this->actingAsBodyUser(User::create([
            'name' => 'Operator',
            'email' => 'op@example.test',
            'password' => 'secret',
            'role' => 'super_admin',
        ]));

        $this->assertSame(2, Institution::count());
    }

    public function test_a_user_with_no_body_sees_nothing_rather_than_everything(): void
    {
        $this->makeInstitution($this->archdiocese, 'Archdiocesan Office');

        // The classic tenancy bug is an empty filter list becoming "no WHERE
        // clause at all", which silently hands a misconfigured account the run
        // of the platform.
        $this->actingAsBodyUser(User::create([
            'name' => 'Orphan',
            'email' => 'orphan@example.test',
            'password' => 'secret',
            'role' => 'viewer',
        ]));

        $this->assertSame(0, Institution::count());
    }

    public function test_the_scope_is_a_no_op_outside_a_request_so_the_ingest_queue_works(): void
    {
        $this->makeInstitution($this->archdiocese, 'Archdiocesan Office');
        $this->makeInstitution($this->buea, 'Buea Clinic');

        // Nobody authenticated: a queued ingest job must be able to write a
        // report for any institution.
        $this->assertFalse(BodyContext::isActive());
        $this->assertSame(2, Institution::count());
    }

    public function test_the_scope_can_be_bypassed_deliberately(): void
    {
        $this->makeInstitution($this->buea, 'Buea Clinic');

        $this->actingAsBodyUser($this->makeUser($this->centralDeanery));

        $this->assertSame(0, Institution::count());
        $this->assertSame(1, Institution::withoutBodyScope()->count());
    }

    public function test_body_id_is_stamped_automatically_on_create(): void
    {
        $this->actingAsBodyUser($this->makeUser($this->kumbo));

        $institution = Institution::create([
            'name' => 'Kumbo Pharmacy',
            'type' => 'business',
            'system' => 'posinnova',
        ]);

        $this->assertSame($this->kumbo->id, $institution->body_id);
    }

    public function test_the_context_memo_does_not_leak_between_users(): void
    {
        $this->makeInstitution($this->buea, 'Buea Clinic');
        $this->makeInstitution($this->kumbo, 'Kumbo Hospital');

        $this->actingAsBodyUser($this->makeUser($this->kumbo));
        $this->assertSame(['Kumbo Hospital'], Institution::pluck('name')->all());

        // Switching user without flushing would leave the previous user's
        // accessible ids in the static memo — one body reading another's data
        // through nothing more than request order.
        $this->actingAsBodyUser($this->makeUser($this->buea));

        $this->assertSame(['Buea Clinic'], Institution::pluck('name')->all());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Authenticate, clearing the body-context memo exactly as
     * ResolveBodyContext does at the start of every real request.
     */
    private function actingAsBodyUser(User $user): User
    {
        $this->actingAs($user);
        BodyContext::flush();

        return $user;
    }

    private function makeBody(string $name, ?Body $parent = null): Body
    {
        return Body::create([
            'name' => $name,
            'parent_body_id' => $parent?->id,
            'type' => $parent ? 'deanery' : 'archdiocese',
        ])->fresh();
    }

    private function makeInstitution(Body $body, string $name): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $body->id,
            'name' => $name,
            'type' => 'school_basic',
            'system' => 'edutrustshsm',
        ]);
    }

    private function makeUser(Body $body): User
    {
        return User::create([
            'body_id' => $body->id,
            'name' => 'User of '.$body->name,
            'email' => 'user'.$body->id.'@example.test',
            'password' => 'secret',
            'role' => 'bursary_head',
        ]);
    }

    private function makeFinding(Body $body, Institution $institution): Finding
    {
        return Finding::withoutBodyScope()->create([
            'body_id' => $body->id,
            'institution_id' => $institution->id,
            'rule' => 'not_reporting',
            'severity' => 'high',
            'title' => 'Not reporting',
            'explanation' => 'Silent for too long.',
            'first_detected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
