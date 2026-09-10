<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Services\ConsolidationService;
use App\Support\BodyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The consolidated statement, in OHADA shape, on screen and as PDF.
 */
class ConsolidationController extends Controller
{
    public function show(Request $request, ConsolidationService $consolidation)
    {
        $data = $this->build($request, $consolidation);

        return view('body.consolidation', $data);
    }

    /**
     * Export in the shape the auditors already use (brief §10).
     *
     * The coverage statement travels INTO the PDF. A statement that leaves the
     * console loses every hyperlink and every tooltip that might have explained
     * a gap, so if coverage were only rendered on screen the exported version
     * would be the most authoritative-looking and least honest artefact the
     * platform produces.
     */
    public function export(Request $request, ConsolidationService $consolidation)
    {
        $data = $this->build($request, $consolidation);

        AuditLog::log('consolidation.exported', null, null, null, [
            'period' => $data['period'],
            'covered' => $data['position']['income']->coveredCount(),
            'total' => $data['position']['income']->total_sources(),
        ]);

        return Pdf::loadView('body.consolidation-pdf', $data)
            ->setPaper('a4')
            ->download(sprintf('consolidated-%s-%s.pdf', $data['body']?->slug ?? 'body', $data['period']));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Request $request, ConsolidationService $consolidation): array
    {
        $period = (string) $request->query('period', '');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = Carbon::now()->subMonth()->format('Y-m');
        }

        $institutions = Institution::query()->active()->with('capabilities')->orderBy('name')->get();

        return [
            'body' => BodyContext::currentBody(),
            'period' => $period,
            'generatedAt' => now(),
            'institutions' => $institutions,
            'position' => $consolidation->position($institutions, $period),
            'income' => $consolidation->byOhadaGroup($institutions, $period, 'income'),
            'expenditure' => $consolidation->byOhadaGroup($institutions, $period, 'expenditure'),
            'notReporting' => $consolidation->notReporting($institutions),
        ];
    }
}
