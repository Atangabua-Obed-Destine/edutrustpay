<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionNote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstitutionNoteController extends Controller
{
    public function store(Request $request, Institution $institution)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(InstitutionNote::KINDS))],
            'noted_on' => ['required', 'date', 'before_or_equal:today'],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        InstitutionNote::create($data + [
            'body_id' => $institution->body_id,
            'institution_id' => $institution->id,
            'user_id' => $request->user()->id,
        ]);

        return redirect()
            ->to(route('body.institutions.show', $institution).'#notes')
            ->with('success', __('Note saved.'));
    }
}
