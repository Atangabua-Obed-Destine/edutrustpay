<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('All schools together') }} — {{ period_text($period) }}</title>
    <style>
        /* dompdf: no external stylesheets, no flexbox, no grid. Tables and
           inline styles only. */
        @page { margin: 22mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b;
             margin: 18px 0 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: bold; border-top: 1px solid #94a3b8; border-bottom: none; }
        .coverage { border: 1px solid #f59e0b; background: #fffbeb; padding: 8px 10px; margin: 10px 0 14px; }
        .coverage.complete { border-color: #cbd5e1; background: #f8fafc; }
        .excluded { margin: 4px 0 0; padding-left: 14px; }
        .footnote { margin-top: 22px; font-size: 8px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    </style>
</head>
<body>

<h1>{{ $body?->name ?? platform_name() }}</h1>
<p class="muted">
    {{ __('All schools together') }} · {{ period_text($period) }} ·
    {{ __('by OHADA account class') }} · {{ config('platform.currency') }}
</p>

{{--
    THE COVERAGE STATEMENT TRAVELS INTO THE PDF, and near the top.

    An exported statement loses every hyperlink and tooltip that might have
    explained a gap on screen, and it is the artefact that gets printed, filed
    and quoted in a meeting. If coverage were shown only in the console, the
    export would be the most authoritative-looking and least honest thing the
    platform produces.
--}}
<div class="coverage {{ $position['income']->isUnqualified() ? 'complete' : '' }}">
    <strong>{{ $position['income']->coverageStatement() }}</strong>
    @if ($position['income']->hasStale())
        {{ __('Some figures are stale.') }}
    @endif

    @if (! empty($notReporting))
        <ul class="excluded">
            @foreach ($notReporting as $row)
                <li>
                    {{ $row->institution->name }} —
                    {{ $row->never_reported ? __('has never sent anything') : __('nothing received for :days days', ['days' => $row->days_silent]) }}
                </li>
            @endforeach
        </ul>
    @endif
</div>

<h2>{{ __('Income') }}</h2>
<table>
    <thead>
        <tr>
            <th style="width: 30%">{{ __('Account class') }}</th>
            <th class="num">{{ __('Amount') }}</th>
            <th class="num" style="width: 22%">{{ __('Schools') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($income as $group => $aggregate)
            <tr>
                <td>{{ __('Class :code', ['code' => $group]) }}</td>
                <td class="num">{{ $aggregate->formatted() }}</td>
                <td class="num">{{ $aggregate->coveredCount() }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('None recorded by the schools that sent figures.') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td>{{ __('Total') }}</td>
            <td class="num">{{ $position['income']->formatted() }}</td>
            <td class="num">{{ $position['income']->coveredCount() }} / {{ $position['income']->total_sources() }}</td>
        </tr>
    </tfoot>
</table>

<h2>{{ __('Spending') }}</h2>
<table>
    <thead>
        <tr>
            <th style="width: 30%">{{ __('Account class') }}</th>
            <th class="num">{{ __('Amount') }}</th>
            <th class="num" style="width: 22%">{{ __('Schools') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($expenditure as $group => $aggregate)
            <tr>
                <td>{{ __('Class :code', ['code' => $group]) }}</td>
                <td class="num">{{ $aggregate->formatted() }}</td>
                <td class="num">{{ $aggregate->coveredCount() }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('None recorded by the schools that sent figures.') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td>{{ __('Total') }}</td>
            <td class="num">{{ $position['expenditure']->formatted() }}</td>
            <td class="num">{{ $position['expenditure']->coveredCount() }} / {{ $position['expenditure']->total_sources() }}</td>
        </tr>
    </tfoot>
</table>

<h2>{{ __('The month') }}</h2>
<table>
    <tbody>
        <tr>
            <td>{{ __('Income less spending') }}</td>
            <td class="num">{{ $position['surplus']->formatted() }}</td>
        </tr>
        <tr>
            <td>{{ __('Cash at month end') }}</td>
            <td class="num">{{ $position['cash_closing']->formatted() }}</td>
        </tr>
    </tbody>
</table>

<h2>{{ __('By school') }}</h2>
<table>
    <thead>
        <tr>
            <th>{{ __('School') }}</th>
            <th class="num">{{ __('Income') }}</th>
            <th class="num">{{ __('Spending') }}</th>
            <th class="num">{{ __('Income less spending') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($position['rows'] as $row)
            <tr>
                <td>{{ $row->institution->name }}</td>
                {{-- A figure nobody sent prints as words here too. There is no
                     branch in this file that turns an absence into a 0. --}}
                <td class="num">{{ $row->income->formatted() ?? $row->income->label() }}</td>
                <td class="num">{{ $row->expenditure->formatted() ?? $row->expenditure->label() }}</td>
                <td class="num">{{ $row->surplus->formatted() ?? $row->surplus->label() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="footnote">
    {{ __('Generated by :platform on :at.', ['platform' => platform_name(), 'at' => $generatedAt->toDayDateTimeString()]) }}
    {{ __('Added up by OHADA account class from the monthly reports each school sent, not read from their books. Schools may book the same kind of money under different classes. For what the money was, see each school\'s daybook.') }}
    {{ __('A figure a school does not send is shown as such, never as zero.') }}
</p>

</body>
</html>
