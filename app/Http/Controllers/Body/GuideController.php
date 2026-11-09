<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\BodyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "How this console works": the page someone new reads once, and comes back to
 * when a word on another page is unclear. It shows only the pages this person
 * can open, so it never points at something they would be refused.
 */
class GuideController extends Controller
{
    public function show(Request $request): View
    {
        return view('body.guide', [
            'body' => BodyContext::chromeBody(),
            'schoolCount' => Institution::query()->active()->count(),
        ]);
    }

    public function hide(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['guide_hidden_at' => now()])->save();

        return redirect()->route('body.dashboard')
            ->with('success', __('The guide is hidden. "How this console works" in the menu has it whenever you need it.'));
    }

    public function restore(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['guide_hidden_at' => null])->save();

        return redirect()->route('body.dashboard');
    }
}
