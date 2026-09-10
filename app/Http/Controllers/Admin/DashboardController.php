<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Body;
use App\Models\Institution;
use App\Models\PeriodReport;
use App\Models\SyncEvent;

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
            'recentEvents' => SyncEvent::query()->with('institution')->orderByDesc('id')->limit(15)->get(),
        ]);
    }
}
