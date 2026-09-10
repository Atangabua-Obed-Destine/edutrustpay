<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\Institution;
use App\Services\DrillThroughService;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Links out to an institution's own system.
 *
 * The console holds no transactions and never will, so the drill-down chain
 * ends by handing over. What has to hold is that it hands over HONESTLY: never
 * an invented URL, never a link for a system that cannot answer it, and nothing
 * at all when the institution has no recorded address. A dead link from an
 * oversight platform teaches people the platform is unreliable, which costs far
 * more than the link was worth.
 */
class DrillThroughTest extends TestCase
{
    use RefreshDatabase;

    private DrillThroughService $drill;

    private Body $body;

    protected function setUp(): void
    {
        parent::setUp();
        BodyContext::flush();

        $this->drill = app(DrillThroughService::class);

        $this->body = Body::create([
            'name' => 'Diocese of Bamenda',
            'country' => 'Cameroon',
            'currency' => 'XAF',
            'fiscal_year_start_month' => 1,
        ])->fresh();
    }

    public function test_it_builds_month_and_range_links_for_paxhitestbed(): void
    {
        $links = collect($this->drill->for($this->institution(), '2026-08'))->keyBy('key');

        // The daybook and budget sheet are addressed by month, because their
        // own URLs take database ids that an outside system must never guess.
        $this->assertSame(
            'http://localhost/paxhitestbed/public/admin/daybook/month/2026-08',
            $links['daybook']->url
        );
        $this->assertSame(
            'http://localhost/paxhitestbed/public/admin/budget-sheet/month/2026-08',
            $links['budget_sheet']->url
        );

        // The ledger reports already accept real dates, so they needed nothing
        // adding at the far end.
        $this->assertSame(
            'http://localhost/paxhitestbed/public/admin/general-ledger/trial-balance?start_date=2026-08-01&end_date=2026-08-31',
            $links['trial_balance']->url
        );
    }

    /**
     * Ageing is a position at a moment, not a movement across a window. Asking
     * for it "between two dates" would return something, and that something
     * would not be an ageing report.
     */
    public function test_ageing_is_addressed_as_at_a_date_not_a_range(): void
    {
        $links = collect($this->drill->for($this->institution(), '2026-08'))->keyBy('key');

        $this->assertStringContainsString('as_of_date=2026-08-31', $links['receivables_aging']->url);
        $this->assertStringNotContainsString('start_date', $links['receivables_aging']->url);
    }

    /**
     * The one that matters most: no address, no links. Not a broken link, not a
     * guess at where the institution might live.
     */
    public function test_an_institution_with_no_address_gets_no_links(): void
    {
        $institution = $this->institution();
        $institution->forceFill(['endpoint' => null])->save();

        $this->assertSame([], $this->drill->for($institution->refresh(), '2026-08'));
        $this->assertFalse($this->drill->available($institution));
    }

    /**
     * posinnova has no adapter yet, so there is nothing behind a link. An empty
     * list is the correct answer rather than an omission.
     */
    public function test_a_system_with_no_adapter_offers_nothing(): void
    {
        $institution = $this->institution('posinnova', 'business');

        $this->assertSame([], $this->drill->for($institution, '2026-08'));
        $this->assertFalse($this->drill->available($institution));
    }

    public function test_a_trailing_slash_on_the_address_does_not_double_up(): void
    {
        $institution = $this->institution();
        $institution->forceFill(['endpoint' => 'http://localhost/paxhitestbed/public/'])->save();

        $links = collect($this->drill->for($institution->refresh(), '2026-08'))->keyBy('key');

        $this->assertStringNotContainsString('public//admin', $links['daybook']->url);
    }

    public function test_a_malformed_period_produces_no_links_rather_than_a_broken_one(): void
    {
        $this->assertSame([], $this->drill->for($this->institution(), 'last-month'));
    }

    private function institution(string $system = 'paxhitestbed', string $type = 'school_higher'): Institution
    {
        return Institution::withoutBodyScope()->create([
            'body_id' => $this->body->id,
            'name' => 'PAXHI',
            'type' => $type,
            'system' => $system,
            'endpoint' => 'http://localhost/paxhitestbed/public',
        ]);
    }
}
