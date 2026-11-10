<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\PeriodReport;
use EdutrustPay\Contract\Capability;
use Illuminate\Http\Request;

/**
 * One institution's staff list for a month: number, name, department and post.
 *
 * The one place the console shows people by name outside the daybook, approved
 * so a body can check postings person by person. It sits behind its own
 * permission, staff.view, and every opening is recorded, as the daybook's is.
 */
class StaffController extends Controller
{
    public function show(Request $request, Institution $institution)
    {
        // The months whose report carries a staff list, newest first.
        $reports = PeriodReport::query()
            ->where('institution_id', $institution->id)
            ->where('is_current', true)
            ->orderByDesc('period')
            ->get(['id', 'institution_id', 'period', 'capabilities', 'status'])
            ->filter(fn (PeriodReport $report) => $report->declares(Capability::STAFF))
            ->values();

        $requested = (string) $request->query('period', '');
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) ? $requested : ($reports->first()?->period ?? now()->subMonth()->format('Y-m'));

        $chosen = $reports->firstWhere('period', $period);
        $staff = $chosen ? (PeriodReport::find($chosen->id)?->decodedPayload()['operational']['staff'] ?? null) : null;

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'department' => (string) $request->query('department', ''),
            'designation' => (string) $request->query('designation', ''),
        ];

        $people = collect($staff['people'] ?? []);
        $shown = $people
            ->when($filters['department'] !== '', fn ($c) => $c->filter(fn ($p) => ($p['department'] ?? '') === $filters['department']))
            ->when($filters['designation'] !== '', fn ($c) => $c->filter(fn ($p) => ($p['designation'] ?? '') === $filters['designation']))
            ->when($filters['q'] !== '', fn ($c) => $c->filter(fn ($p) => str_contains(mb_strtolower(implode(' ', array_filter([$p['name'] ?? '', $p['number'] ?? '']))), mb_strtolower($filters['q']))))
            ->values();

        AuditLog::log('staff.viewed', Institution::class, $institution->id, null, [
            'period' => $period,
            'search' => $filters['q'] ?: null,
        ]);

        return view('body.institutions.staff', [
            'institution' => $institution,
            'period' => $period,
            'months' => $reports->pluck('period'),
            'staff' => $staff,
            'people' => $shown,
            'departments' => $people->pluck('department')->unique()->sort(SORT_NATURAL)->values(),
            'designations' => $people->pluck('designation')->unique()->sort(SORT_NATURAL)->values(),
            'filters' => $filters,
        ]);
    }
}
