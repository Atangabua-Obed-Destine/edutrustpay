<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\ComplianceCase;
use App\Models\Institution;
use App\Models\Regulation;
use App\Models\RegulationAttestation;
use App\Models\RegulationMapping;
use App\Models\RegulationWaiver;
use App\Models\User;
use App\Services\ComplianceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The compliance board, and acting on what it shows.
 *
 * Institutions come through the tenancy scope, so another diocese's school is a
 * 404. A regulation may belong to a body above this one, so it is looked up
 * unscoped and then required to bind the institution in the URL.
 */
class ComplianceController extends Controller
{
    public function __construct(private ComplianceService $compliance)
    {
    }

    public function index()
    {
        $institutions = Institution::query()->with('body')->where('is_active', true)->orderBy('name')->get();

        return view('body.compliance.index', $this->compliance->board($institutions) + [
            'cases' => $this->compliance->openCases($institutions),
        ]);
    }

    public function show(int $regulation, Institution $institution)
    {
        $rule = $this->rule($regulation, $institution);

        return view('body.compliance.show', [
            'regulation' => $rule,
            'institution' => $institution,
            'result' => $this->compliance->check($rule, $institution),
            'cases' => ComplianceCase::query()->where('regulation_id', $rule->id)->where('institution_id', $institution->id)
                ->with('assignee', 'opener')->latest('id')->get(),
            'people' => User::query()->accessible()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Say which line of the schedule each of the school's own fees counts as.
     */
    public function saveMappings(Request $request, int $regulation, Institution $institution)
    {
        $rule = $this->rule($regulation, $institution);
        $itemIds = $rule->feeItems()->pluck('id')->map(fn ($id) => (string) $id)->all();

        $data = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.level' => ['required', 'string', 'max:191'],
            'mappings.*.category' => ['required', 'string', 'max:191'],
            'mappings.*.target' => ['nullable', Rule::in(array_merge($itemIds, ['ignore', '']))],
        ]);

        foreach ($data['mappings'] as $row) {
            $target = $row['target'] ?? '';
            $where = [
                'regulation_id' => $rule->id,
                'institution_id' => $institution->id,
                'school_level' => $row['level'],
                'school_category' => $row['category'],
            ];

            if ($target === '') {
                RegulationMapping::where($where)->delete();

                continue;
            }

            RegulationMapping::updateOrCreate($where, [
                'regulation_fee_item_id' => $target === 'ignore' ? null : (int) $target,
                'ignored' => $target === 'ignore',
            ]);
        }

        return back()->with('success', __('Saved. The comparison has been updated.'));
    }

    public function waive(Request $request, int $regulation, Institution $institution)
    {
        $rule = $this->rule($regulation, $institution);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:191'],
            'expires_on' => ['required', 'date', 'after_or_equal:today'],
        ], [], ['expires_on' => __('end date')]);

        RegulationWaiver::create($data + [
            'regulation_id' => $rule->id,
            'institution_id' => $institution->id,
            'granted_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Waiver recorded.'));
    }

    public function revokeWaiver(Request $request, int $regulation, Institution $institution, RegulationWaiver $waiver)
    {
        $rule = $this->rule($regulation, $institution);

        abort_unless($waiver->regulation_id === $rule->id && $waiver->institution_id === $institution->id, 404);

        $waiver->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);

        return back()->with('success', __('Waiver ended. The rule applies again.'));
    }

    public function attest(Request $request, int $regulation, Institution $institution)
    {
        $rule = $this->rule($regulation, $institution);

        abort_unless($rule->check_type === Regulation::ATTESTATION, 404);

        $data = $request->validate([
            'outcome' => ['required', Rule::in([ComplianceService::COMPLIES, ComplianceService::BREACH])],
            'note' => ['required', 'string', 'min:5', 'max:191'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
        ], [], ['valid_until' => __('valid until')]);

        RegulationAttestation::create($data + [
            'regulation_id' => $rule->id,
            'institution_id' => $institution->id,
            'attested_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Recorded.'));
    }

    public function openCase(Request $request, int $regulation, Institution $institution)
    {
        $rule = $this->rule($regulation, $institution);

        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'assigned_to' => ['nullable', 'integer'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ], [], ['note' => __('what needs doing'), 'due_on' => __('due date')]);

        ComplianceCase::create($this->withAssignee($data) + [
            'regulation_id' => $rule->id,
            'institution_id' => $institution->id,
            'status' => ComplianceCase::OPEN,
            'opened_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Follow-up opened.'));
    }

    public function closeCase(Request $request, int $regulation, Institution $institution, ComplianceCase $case)
    {
        $rule = $this->rule($regulation, $institution);

        abort_unless($case->regulation_id === $rule->id && $case->institution_id === $institution->id, 404);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'min:5', 'max:2000'],
        ], [], ['outcome' => __('how it ended')]);

        $case->update($data + [
            'status' => ComplianceCase::CLOSED,
            'closed_by' => $request->user()->id,
            'closed_at' => now(),
        ]);

        return back()->with('success', __('Follow-up closed.'));
    }

    /**
     * Only somebody within reach can be given the work.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withAssignee(array $data): array
    {
        if (! empty($data['assigned_to']) && ! User::query()->accessible()->whereKey($data['assigned_to'])->exists()) {
            abort(422, 'That person is not in this body.');
        }

        return $data;
    }

    private function rule(int $regulation, Institution $institution): Regulation
    {
        $institution->loadMissing('body');

        $rule = Regulation::withoutBodyScope()->with('feeItems')->findOrFail($regulation);

        abort_unless($rule->coversInstitution($institution), 404);

        return $rule;
    }
}
