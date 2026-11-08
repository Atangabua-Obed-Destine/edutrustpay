<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DaybookFiling;
use App\Models\DaybookLine;
use App\Models\Institution;
use App\Services\DaybookAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reading a school's daybook, and comparing schools by theirs.
 *
 * The school page has its own permission because its lines name people, and every
 * read is written to the audit log. The comparison shows no names, so it can be
 * given to people who should not read individual lines.
 */
class DaybookController extends Controller
{
    public function show(Request $request, Institution $institution)
    {
        $filings = DaybookFiling::query()
            ->where('institution_id', $institution->id)
            ->where('is_current', true)
            ->orderByDesc('period')
            ->get();

        $period = $this->month($request->query('period')) ?? $filings->first()?->period ?? now()->subMonth()->format('Y-m');
        $filing = $filings->firstWhere('period', $period);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'direction' => in_array($request->query('direction'), ['in', 'out'], true) ? $request->query('direction') : '',
            'kind' => array_key_exists((string) $request->query('kind'), DaybookLine::KINDS) ? (string) $request->query('kind') : '',
            'method' => array_key_exists((string) $request->query('method'), DaybookLine::METHODS) ? (string) $request->query('method') : '',
        ];

        $lines = null;
        $totals = null;

        if ($filing) {
            $query = DaybookLine::query()->where('daybook_filing_id', $filing->id)
                ->when($filters['direction'], fn ($q, $v) => $q->where('direction', $v))
                ->when($filters['kind'], fn ($q, $v) => $q->where('kind', $v))
                ->when($filters['method'], fn ($q, $v) => $q->where('method', $v))
                ->when($filters['q'], function ($q, $v) {
                    $like = '%'.addcslashes($v, '%_\\').'%';
                    $q->where(fn ($inner) => $inner->where('description', 'like', $like)
                        ->orWhere('party', 'like', $like)
                        ->orWhere('ref', 'like', $like)
                        ->orWhere('heading', 'like', $like));
                });

            $totals = (clone $query)
                ->selectRaw("COUNT(*) as entries,
                    COALESCE(SUM(CASE WHEN direction = 'in' THEN amount END), 0) as money_in,
                    COALESCE(SUM(CASE WHEN direction = 'out' THEN amount END), 0) as money_out")
                ->toBase()
                ->first();

            $lines = $query->orderBy('line_date')->orderBy('id')->paginate(50)->withQueryString();
        }

        AuditLog::log('daybook.viewed', Institution::class, $institution->id, null, [
            'period' => $period,
            'search' => $filters['q'] ?: null,
        ]);

        return view('body.daybook.show', compact('institution', 'filings', 'period', 'filing', 'filters', 'lines', 'totals'));
    }

    public function compare(Request $request, DaybookAnalysisService $analysis)
    {
        $latest = now()->subMonth()->format('Y-m');
        $to = $this->month($request->query('to')) ?? $latest;
        $from = $this->month($request->query('from'))
            ?? Carbon::createFromFormat('!Y-m', $to)->subMonths(5)->format('Y-m');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $institutions = Institution::query()->active()->orderBy('name')->get();

        return view('body.daybook.compare', [
            'from' => $from,
            'to' => $to,
            'monthOptions' => collect(range(0, 23))->map(fn (int $i) => now()->startOfMonth()->subMonths($i + 1)->format('Y-m')),
            'comparison' => $analysis->compare($institutions, $from, $to),
        ]);
    }

    private function month(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }
}
