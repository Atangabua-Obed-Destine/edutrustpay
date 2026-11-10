<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BudgetSheet;
use App\Models\DaybookFiling;
use App\Models\DaybookLine;
use App\Models\Institution;
use App\Models\PeriodReport;
use EdutrustPay\Contract\Capability;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A school's daybook, staff list or budget sheet for one month, as a file the
 * finance office opens in Excel. The same rows the page shows, with the same
 * permissions, and every download recorded as an opening is.
 */
class ExportController extends Controller
{
    public function daybook(Request $request, Institution $institution): StreamedResponse
    {
        $period = $this->month($request);
        $filing = DaybookFiling::query()->where('institution_id', $institution->id)->where('period', $period)->where('is_current', true)->firstOrFail();

        $this->record('daybook.exported', $institution, $period);

        return $this->csv($this->filename($institution, 'daybook', $period),
            [__('Date'), __('Reference'), __('Description'), __('Party'), __('Budget line'), __('Type'), __('In or out'), __('Amount'), __('Paid by')],
            DaybookLine::query()->where('daybook_filing_id', $filing->id)->orderBy('line_date')->orderBy('id')->cursor()
                ->map(fn (DaybookLine $line) => [
                    $line->line_date?->toDateString() ?? (string) $line->line_date,
                    $line->ref, $line->description, $line->party, $line->heading,
                    __(DaybookLine::KINDS[$line->kind] ?? $line->kind),
                    $line->direction === 'in' ? __('in') : __('out'),
                    $line->amount,
                    $line->method ? __(DaybookLine::METHODS[$line->method] ?? $line->method) : '',
                ]));
    }

    public function staff(Request $request, Institution $institution): StreamedResponse
    {
        $period = $this->month($request);
        $report = PeriodReport::query()->where('institution_id', $institution->id)->where('period', $period)->where('is_current', true)->firstOrFail();
        abort_unless($report->declares(Capability::STAFF), 404);

        $this->record('staff.exported', $institution, $period);

        return $this->csv($this->filename($institution, 'staff', $period),
            [__('Staff number'), __('Name'), __('Department'), __('Post')],
            collect($report->decodedPayload()['operational']['staff']['people'] ?? [])
                ->map(fn (array $p) => [$p['number'] ?? '', $p['name'], $p['department'] ?? '', $p['designation'] ?? '']));
    }

    public function budget(Request $request, Institution $institution): StreamedResponse
    {
        $period = $this->month($request);
        $sheet = BudgetSheet::query()->where('institution_id', $institution->id)->where('period', $period)->where('is_current', true)->firstOrFail();

        $this->record('budget.exported', $institution, $period);

        $sections = ['income' => __('Income'), 'expenditure' => __('Expenditure'), 'capital' => __('Capital')];

        return $this->csv($this->filename($institution, 'budget', $period),
            [__('Part'), __('Code'), __('Line'), __('Heading'), __('Budget'), __('This month'), __('So far'), __('Left')],
            collect($sheet->document()['lines'] ?? [])
                ->map(fn (array $l) => [
                    $sections[$l['section']] ?? $l['section'], $l['code'] ?? '', $l['name'], $l['heading'] ? __('yes') : '',
                    $l['budget'], $l['month'], $l['to_date'],
                    number_format((float) $l['budget'] - (float) $l['to_date'], 2, '.', ''),
                ]));
    }

    private function month(Request $request): string
    {
        $period = (string) $request->query('period', '');
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1, 404);

        return $period;
    }

    private function record(string $action, Institution $institution, string $period): void
    {
        AuditLog::log($action, Institution::class, $institution->id, null, ['period' => $period]);
    }

    private function filename(Institution $institution, string $what, string $period): string
    {
        return \Illuminate\Support\Str::slug($institution->name).'-'.$what.'-'.$period.'.csv';
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            // The byte order mark lets Excel read accented names (Scolarités) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => (string) $v, $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
