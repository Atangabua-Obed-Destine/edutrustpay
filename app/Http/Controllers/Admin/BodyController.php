<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BodyRequest;
use App\Models\AuditLog;
use App\Models\Body;
use App\Services\BodyProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Bodies: create, nest, brand.
 *
 * A body may contain bodies — the Archdiocese contains deaneries — and the
 * nesting is the whole point: a deanery sees its own institutions, the centre
 * sees everything beneath it.
 */
class BodyController extends Controller
{
    public function __construct(private BodyProvisioningService $bodies)
    {
    }

    public function index()
    {
        return view('admin.bodies.index', [
            'bodies' => Body::query()
                ->withCount(['institutions', 'children'])
                ->orderBy('path')
                ->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.bodies.form', [
            'body' => new Body([
                // No default type: the platform has no business guessing that a
                // new body is a diocese.
                'country' => 'Cameroon',
                'currency' => config('platform.currency'),
                'fiscal_year_start_month' => 1,
                'is_active' => true,
                'parent_body_id' => $request->integer('parent') ?: null,
            ]),
            'parents' => $this->bodies->availableParents(),
        ]);
    }

    public function store(BodyRequest $request)
    {
        $data = $this->withCrest($request, $request->validated());

        $body = $this->bodies->create($data + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()
            ->route('admin.bodies.show', $body)
            ->with('success', __(':name created. Enrol its first institution next.', ['name' => $body->name]));
    }

    public function show(Body $body)
    {
        return view('admin.bodies.show', [
            'body' => $body->load('parent'),
            'children' => $body->children()->withCount('institutions')->orderBy('name')->get(),
            // withoutBodyScope: the operator is outside every body, and the
            // scope would otherwise filter this to their own (nonexistent) one.
            'institutions' => $body->institutions()->withoutBodyScope()
                ->where('body_id', $body->id)->withCount('capabilities')->orderBy('name')->get(),
            'users' => $body->users()->with('roles')->orderBy('name')->get(),
        ]);
    }

    public function edit(Body $body)
    {
        return view('admin.bodies.form', [
            'body' => $body,
            // Excludes itself and its own descendants, so the form cannot offer
            // a choice the validator will only reject afterwards.
            'parents' => $this->bodies->availableParents($body),
        ]);
    }

    public function update(BodyRequest $request, Body $body)
    {
        $data = $this->withCrest($request, $request->validated(), $body);

        $this->bodies->update($body, $data + ['is_active' => $request->boolean('is_active')]);

        return redirect()
            ->route('admin.bodies.show', $body)
            ->with('success', __('Saved.'));
    }

    /**
     * Fold an uploaded crest into the attributes, and honour a removal.
     *
     * Three separate states, and conflating any two of them loses a file or
     * keeps one nobody wants:
     *
     *   a file was uploaded  -> store it, delete whatever it replaced
     *   remove_logo ticked   -> delete it, set the column to null
     *   neither              -> LEAVE THE COLUMN ALONE
     *
     * The third is the one that matters. `logo_path` is not a form field, so a
     * plain `$validated` array never mentions it — and an absent key must mean
     * "unchanged", not "clear". Otherwise correcting a typo in the body's name
     * would silently drop its crest.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCrest(BodyRequest $request, array $data, ?Body $body = null): array
    {
        unset($data['logo']);

        if ($request->hasFile('logo')) {
            $this->deleteCrest($body);

            $data['logo_path'] = $request->file('logo')->store('crests', 'public');

            return $data;
        }

        if ($request->boolean('remove_logo')) {
            $this->deleteCrest($body);

            $data['logo_path'] = null;
        }

        return $data;
    }

    private function deleteCrest(?Body $body): void
    {
        if ($body?->logo_path) {
            Storage::disk('public')->delete($body->logo_path);
        }
    }

    /**
     * Deleting a body is refused whenever it still holds anything.
     *
     * Nothing is deleted where money is concerned, and a body with institutions
     * beneath it has reports, findings and an audit trail hanging off it. The
     * operator deactivates instead — the data stays, the body stops appearing.
     */
    public function destroy(Body $body)
    {
        $check = $this->bodies->deletability($body);

        if (! $check['allowed']) {
            return back()->with('error', __('This body cannot be deleted. :reason', ['reason' => $check['reason']]));
        }

        // Stamped explicitly with the body it describes: the operator belongs to
        // no body, and this entry has to outlive the row it points at.
        AuditLog::log('body.deleted', Body::class, $body->id, ['name' => $body->name], null, $body->id);

        // The audit row is kept deliberately; the image file is not part of the
        // record and would otherwise sit on disk forever with nothing pointing
        // at it.
        $this->deleteCrest($body);

        $body->delete();

        return redirect()->route('admin.bodies.index')->with('success', __('Body deleted.'));
    }
}
