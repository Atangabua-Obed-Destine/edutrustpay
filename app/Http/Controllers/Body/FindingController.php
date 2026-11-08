<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Finding;
use Illuminate\Http\Request;

/**
 * Findings — the controls that fired, and what was done about them.
 */
class FindingController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', Finding::OPEN);

        $findings = Finding::query()
            ->with('institution', 'resolver')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->bySeverity()
            ->paginate(200)
            ->withQueryString();

        return view('body.findings.index', [
            'findings' => $findings,
            'groups' => Finding::grouped($findings->getCollection()),
            'status' => $status,
            'openFindingCount' => Finding::grouped(Finding::query()->open()->with('institution')->get())->count(),
            'counts' => collect([Finding::OPEN, Finding::EXPLAINED, Finding::ACCEPTED])->mapWithKeys(fn (string $s) => [
                $s => Finding::grouped(Finding::query()->where('status', $s)->with('institution')->get())->count(),
            ])->all(),
        ]);
    }

    public function show(Finding $finding)
    {
        AuditLog::viewed(Finding::class, $finding->id, ['rule' => $finding->rule]);

        return view('body.findings.show', [
            'finding' => $finding->load('institution', 'periodReport', 'resolver'),
        ]);
    }

    /**
     * Answer a finding.
     *
     * A note is REQUIRED, not optional. An "accepted" with nothing written
     * against it is indistinguishable from someone clearing their inbox, and the
     * whole value of a finding is that a named person decided something.
     */
    public function resolve(Request $request, Finding $finding)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.Finding::EXPLAINED.','.Finding::ACCEPTED],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ], [], [
            'note' => __('explanation'),
        ]);

        $finding->resolve($data['status'], $request->user(), $data['note']);

        return redirect()
            ->route('body.findings.index')
            ->with('success', __('Finding recorded as :status.', ['status' => __($data['status'])]));
    }
}
