<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Services\ConsolidationService;

/**
 * The capability matrix (brief §7.4) — institutions down, capabilities across.
 *
 * Two things at once: what the body can currently trust, and the roadmap for
 * edutrustshsm made visible as gaps that close over the months. It is also the
 * page that explains every "not yet reporting" elsewhere in the console.
 */
class CapabilityMatrixController extends Controller
{
    public function __invoke(ConsolidationService $consolidation)
    {
        $institutions = Institution::query()->with('capabilities')->orderBy('name')->get();

        return view('body.capabilities', $consolidation->capabilityMatrix($institutions));
    }
}
