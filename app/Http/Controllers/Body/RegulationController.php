<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Regulation;
use App\Services\ComplianceService;
use App\Support\BodyContext;
use App\Support\InstitutionType;
use EdutrustPay\Contract\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The rules themselves.
 *
 * A body writes its own rules; there are none built in, because they differ from
 * one body to the next. Route binding is tenancy scoped, so a deanery can read its
 * diocese's rules on the board but only change its own here.
 */
class RegulationController extends Controller
{
    public function index()
    {
        return view('body.compliance.regulations', [
            'regulations' => Regulation::query()->with('feeItems')->withCount('feeItems')
                ->orderByDesc('is_active')->orderBy('area')->orderBy('title')->get(),
        ]);
    }

    public function create(Request $request, ComplianceService $compliance)
    {
        $institutions = Institution::query()->orderBy('name')->get();
        $copyFrom = $request->filled('copy_from') ? $institutions->firstWhere('uuid', $request->query('copy_from')) : null;

        return view('body.compliance.regulation-form', [
            'regulation' => new Regulation([
                'area' => $request->query('type') === Regulation::ATTESTATION ? 'other' : 'fees',
                'check_type' => $request->query('type') === Regulation::ATTESTATION ? Regulation::ATTESTATION : Regulation::FEE_SCHEDULE,
                'fee_mode' => 'maximum',
                'effective_from' => today(),
                'applies_to' => $copyFrom?->type,
            ]),
            'items' => $copyFrom ? $this->itemsFrom($compliance, $copyFrom) : [],
            'institutions' => $institutions,
            'copyFrom' => $copyFrom,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $regulation = Regulation::create($data['fields'] + [
            'body_id' => BodyContext::current(),
            'created_by' => $request->user()->id,
        ]);

        $this->syncItems($regulation, $data['items']);

        return redirect()->route('body.compliance.index')
            ->with('success', __(':title is now in force.', ['title' => $regulation->title]));
    }

    public function edit(Regulation $regulation)
    {
        return view('body.compliance.regulation-form', [
            'regulation' => $regulation,
            'items' => $regulation->feeItems->map(fn ($i) => ['level' => $i->level, 'category' => $i->category, 'amount' => $i->amount])->all(),
            'institutions' => collect(),
            'copyFrom' => null,
        ]);
    }

    public function update(Request $request, Regulation $regulation)
    {
        $data = $this->validated($request, $regulation);

        $regulation->update($data['fields']);

        $this->syncItems($regulation, $data['items']);

        return redirect()->route('body.compliance.regulations.index')->with('success', __('Saved.'));
    }

    /**
     * Retire or restore. Never deleted: a follow-up or waiver may point at it.
     */
    public function toggle(Regulation $regulation)
    {
        $regulation->update(['is_active' => ! $regulation->is_active]);

        return back()->with('success', $regulation->is_active
            ? __(':title is back in force.', ['title' => $regulation->title])
            : __(':title has been retired.', ['title' => $regulation->title]));
    }

    /**
     * @return array{fields: array<string, mixed>, items: array<int, array<string, string>>}
     */
    private function validated(Request $request, ?Regulation $existing = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'area' => ['required', Rule::in(array_keys(Regulation::AREAS))],
            'check_type' => ['required', Rule::in([Regulation::FEE_SCHEDULE, Regulation::ATTESTATION])],
            'applies_to' => ['nullable', Rule::in(InstitutionType::all())],
            'fee_mode' => ['nullable', Rule::in(['maximum', 'exact'])],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'items' => ['array'],
            'items.*.level' => ['nullable', 'string', 'max:191'],
            'items.*.category' => ['nullable', 'string', 'max:191'],
            'items.*.amount' => ['nullable', 'string', 'max:30'],
        ], [], ['effective_from' => __('start date'), 'effective_to' => __('end date')]);

        // A rule's kind decides how it is judged, so it cannot change afterwards.
        $checkType = $existing?->check_type ?? $validated['check_type'];

        $items = collect($validated['items'] ?? [])
            ->filter(fn ($row) => filled($row['level'] ?? null) || filled($row['category'] ?? null) || filled($row['amount'] ?? null))
            ->values();

        if ($checkType === Regulation::FEE_SCHEDULE) {
            $this->checkItems($items->all());
        }

        return [
            'fields' => [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'area' => $validated['area'],
                'check_type' => $checkType,
                'applies_to' => $validated['applies_to'] ?? null,
                'fee_mode' => $validated['fee_mode'] ?? 'maximum',
                'effective_from' => $validated['effective_from'],
                'effective_to' => $validated['effective_to'] ?? null,
            ],
            'items' => $checkType === Regulation::FEE_SCHEDULE ? $items->all() : [],
        ];
    }

    /**
     * @param  array<int, array<string, ?string>>  $items
     */
    private function checkItems(array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('A fee schedule needs at least one fee.')]);
        }

        $seen = [];

        foreach ($items as $row) {
            $label = trim(($row['level'] ?? '').' '.($row['category'] ?? ''));

            if (blank($row['level'] ?? null) || blank($row['category'] ?? null)) {
                throw ValidationException::withMessages(['items' => __('Every fee needs a class and a fee name.')]);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', str_replace([' ', ','], '', (string) ($row['amount'] ?? '')))) {
                throw ValidationException::withMessages(['items' => __('The amount for :fee is not a number.', ['fee' => $label])]);
            }

            $key = mb_strtolower($label);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items' => __(':fee is listed twice.', ['fee' => $label])]);
            }

            $seen[$key] = true;
        }
    }

    /**
     * Keep existing lines by name, so a school's matching survives a price change.
     *
     * @param  array<int, array<string, string>>  $items
     */
    private function syncItems(Regulation $regulation, array $items): void
    {
        $kept = [];

        foreach ($items as $row) {
            $item = $regulation->feeItems()->updateOrCreate(
                ['level' => trim($row['level']), 'category' => trim($row['category'])],
                ['amount' => Money::of(number_format((float) str_replace([' ', ','], '', $row['amount']), 2, '.', ''))->value()]
            );

            $kept[] = $item->id;
        }

        $regulation->feeItems()->whereNotIn('id', $kept)->delete();
    }

    /**
     * Start a schedule from what a school already charges, to type less.
     *
     * @return array<int, array<string, string>>
     */
    private function itemsFrom(ComplianceService $compliance, Institution $institution): array
    {
        $report = $compliance->latestFeeReport($institution);
        $rows = $report?->decodedPayload()['operational']['fees_charged']['rows'] ?? [];

        return collect($rows)
            ->groupBy(fn ($r) => $r['level'].'|'.$r['category'])
            ->map(fn ($group) => [
                'level' => $group->first()['level'],
                'category' => $group->first()['category'],
                'amount' => number_format($group->min(fn ($r) => (float) $r['amount']), 2, '.', ''),
            ])
            ->values()->all();
    }
}
