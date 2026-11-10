<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who did what in this console, newest first: sign-ins, pages opened (the
 * daybook and the staff list above all, since they name people), downloads,
 * answers and changes. The console has always kept this record; this is the
 * page that lets an administrator or auditor read it.
 */
class ActivityController extends Controller
{
    /** The kinds of activity a reader filters by, each a set of recorded actions. */
    private const KINDS = [
        'signins' => ['auth.login'],
        'opened' => ['viewed', 'daybook.viewed', 'staff.viewed'],
        'downloads' => ['consolidation.exported', 'daybook.exported', 'staff.exported', 'budget.exported'],
        'keys' => ['credential.issued', 'credential.rotated', 'credential.revoked'],
        'people' => ['user.invited', 'user.invite_accepted', 'role.permissions_changed'],
    ];

    public function index(Request $request)
    {
        $filters = [
            'who' => (string) $request->query('who', ''),
            'kind' => array_key_exists((string) $request->query('kind'), self::KINDS) || $request->query('kind') === 'changes' ? (string) $request->query('kind') : '',
            'school' => (string) $request->query('school', ''),
            'system' => $request->boolean('system'),
        ];

        $institutions = Institution::query()->orderBy('name')->get(['id', 'uuid', 'name']);
        $school = $institutions->firstWhere('uuid', $filters['school']);

        $entries = AuditLog::query()
            ->with('user')
            ->when(! $filters['system'], fn ($q) => $q->whereNotNull('user_id'))
            ->when($filters['who'] !== '', fn ($q) => $q->where('user_id', (int) $filters['who']))
            ->when($filters['kind'] === 'changes', fn ($q) => $q->whereIn('action', ['created', 'updated', 'deleted']))
            ->when(isset(self::KINDS[$filters['kind']]), fn ($q) => $q->whereIn('action', self::KINDS[$filters['kind']]))
            ->when($school, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($m) => $m->where('model_type', Institution::class)->where('model_id', $school->id))
                ->orWhere('new_values->institution_id', $school->id)))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('body.activity.index', [
            'entries' => $entries,
            'filters' => $filters,
            'people' => User::query()->accessible()->orderBy('name')->get(['id', 'name']),
            'institutions' => $institutions,
            'schoolNames' => $institutions->pluck('name', 'id'),
        ]);
    }
}
