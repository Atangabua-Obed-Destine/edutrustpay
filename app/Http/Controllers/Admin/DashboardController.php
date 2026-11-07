<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Body;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Console\Commands\Sweep;
use App\Models\SyncEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The operator's view across every body.
 *
 * Superadmin is not exempt from the tenancy scope; it is WIDENED at
 * BodyContext::accessibleIds() to every body. Queries stay filtered, so a bug in
 * the scope cannot silently expose data it was never meant to.
 */
class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('admin.dashboard', [
            'bodies' => Body::query()->withCount('institutions')->orderBy('path')->get(),
            'institutions' => Institution::query()->with('body', 'capabilities')->orderBy('name')->get(),
            'reportCount' => PeriodReport::query()->where('is_current', true)->count(),
            'health' => $this->health(),
            'recentEvents' => SyncEvent::query()->with('institution')->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    /**
     * Whether the background work is happening.
     *
     * Reports are accepted instantly and processed by a queue worker, and silent
     * schools are found by a daily sweep. If either stops, every page still
     * loads and every figure just quietly stops changing, so it is shown here.
     *
     * @return array{queued: int, oldest: ?Carbon, failed: int, lastSweep: ?Carbon}
     */
    private function health(): array
    {
        $oldest = DB::table('jobs')->min('created_at');
        $lastSweep = Cache::get(Sweep::LAST_RUN_KEY);

        return [
            'queued' => DB::table('jobs')->count(),
            'oldest' => $oldest ? Carbon::createFromTimestamp($oldest) : null,
            'failed' => DB::table('failed_jobs')->count(),
            'lastSweep' => $lastSweep ? Carbon::parse($lastSweep) : null,
        ];
    }
}
