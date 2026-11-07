<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\InstitutionRequest;
use App\Models\Body;
use App\Models\Institution;
use App\Models\InstitutionCapability;
use App\Services\OnboardingChecklistService;
use App\Support\BodyContext;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\ContractVersion;
use Illuminate\Http\Request;

/**
 * Enrolling and configuring institutions.
 *
 * Used from both sides: by the operator across every body, and by a body's own
 * administrators under /body/manage. Route binding goes through the tenancy
 * scope, so an institution in another body is a 404 here. Only index(), which is
 * registered for the operator alone, bypasses it.
 */
class InstitutionController extends Controller
{
    public function __construct(private OnboardingChecklistService $checklist)
    {
    }

    /**
     * Where an institution may be placed: everywhere for the operator, this body
     * and those nested inside it for a body's own administrator.
     */
    private function availableBodies()
    {
        return Body::whereIn('id', BodyContext::accessibleIds())->orderBy('path')->get();
    }

    public function index(Request $request)
    {
        $institutions = Institution::withoutBodyScope()
            ->with('body', 'capabilities')
            ->withCount('capabilities')
            ->when($request->filled('body'), fn ($q) => $q->where('body_id', $request->integer('body')))
            ->when($request->filled('system'), fn ($q) => $q->where('system', $request->string('system')))
            ->orderBy('name')
            ->get();

        return view('admin.institutions.index', [
            'institutions' => $institutions,
            'bodies' => Body::orderBy('path')->get(),
            'filters' => ['body' => $request->query('body'), 'system' => $request->query('system')],
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.institutions.form', [
            'institution' => new Institution([
                'body_id' => $request->integer('body') ?: (BodyContext::isSuperAdmin() ? null : BodyContext::current()),
                'type' => 'school_basic',
                'system' => 'edutrustshsm',
                'connectivity_mode' => 'push',
                'contract_version' => ContractVersion::CURRENT,
                'is_active' => true,
            ]),
            'bodies' => $this->availableBodies(),
        ]);
    }

    public function store(InstitutionRequest $request)
    {
        $institution = Institution::withoutBodyScope()->create(
            $request->validated() + ['is_active' => $request->boolean('is_active', true)]
        );

        return redirect()
            ->to(manage_route('institutions.show', $institution))
            ->with('success', __('
                :name enrolled. Issue credentials and declare what it can report — until you declare a
                capability, the console will show it as "not yet reporting" rather than as zero, which is
                usually what you want.
            ', ['name' => $institution->name]));
    }

    public function show(Institution $institution)
    {
        $institution->loadMissing('body', 'capabilities', 'credentials.creator');

        return view('admin.institutions.show', [
            'institution' => $institution,
            'checklist' => $this->checklist->for($institution),
            'capabilities' => Capability::all(),
            'applicable' => $institution->applicableCapabilities(),
            'declared' => $institution->capabilities->keyBy('capability'),
            'credentials' => $institution->credentials()->orderByDesc('id')->get(),
            'recentReports' => $institution->periodReports()->withoutBodyScope()
                ->where('institution_id', $institution->id)
                ->where('is_current', true)->orderByDesc('period')->limit(6)->get(),
            'events' => $institution->syncEvents()->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    public function edit(Institution $institution)
    {
        return view('admin.institutions.form', [
            'institution' => $institution,
            'bodies' => $this->availableBodies(),
        ]);
    }

    public function update(InstitutionRequest $request, Institution $institution)
    {
        $institution->update($request->validated() + ['is_active' => $request->boolean('is_active')]);

        return redirect()
            ->to(manage_route('institutions.show', $institution))
            ->with('success', __('Saved.'));
    }

    /**
     * Declare what this institution can report.
     *
     * The most consequential screen in the operator area, and the least obvious.
     * A capability declared here that the institution cannot actually produce
     * means its reports will be rejected; one NOT declared that it can produce
     * means the console shows "not yet reporting" for a figure that exists.
     *
     * Capabilities that were observed arriving are not removable by hand — the
     * institution is sending them, and pretending otherwise would make the
     * capability matrix disagree with the reports underneath it.
     */
    public function capabilities(Request $request, Institution $institution)
    {
        $applicable = $institution->applicableCapabilities();

        $chosen = collect($request->input('capabilities', []))
            ->filter(fn ($c) => in_array($c, $applicable, true))
            ->values();

        $observed = $institution->capabilities()->whereNotNull('first_seen_at')->pluck('capability');
        $keep = $chosen->merge($observed)->unique();

        foreach ($keep as $capability) {
            InstitutionCapability::updateOrCreate(
                ['institution_id' => $institution->id, 'capability' => $capability],
                ['source' => $observed->contains($capability) ? 'observed' : 'declared', 'declared_at' => now()]
            );
        }

        $institution->capabilities()->whereNotIn('capability', $keep)->delete();

        $ignored = $observed->diff($chosen);

        return redirect()
            ->to(manage_route('institutions.show', $institution))
            ->with('success', trim(__('Capabilities updated.').' '.($ignored->isNotEmpty()
                ? __('Kept :list — reports carrying them have already arrived, so removing them would make this page disagree with the figures.', ['list' => $ignored->implode(', ')])
                : '')));
    }

    /**
     * Tick or un-tick an onboarding step.
     */
    public function onboarding(Request $request, Institution $institution)
    {
        $data = $request->validate([
            'step' => ['required', 'string'],
            'done' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $this->checklist->set($institution, $data['step'], (bool) $data['done'], $request->user()->id, $data['note'] ?? null);

        return back()->with('success', __('Checklist updated.'));
    }
}
