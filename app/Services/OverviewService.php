<?php

namespace App\Services;

use App\Models\Institution;
use Illuminate\Support\Collection;

/**
 * Turns the console's figures into what the landing page has to say first: how
 * are my schools, in words, and one row per school.
 *
 * It computes nothing new. Everything comes from the same reports, rules and
 * findings the rest of the console uses, so this page cannot disagree with them.
 * A school that sent nothing is said to have sent nothing, and never reads as fine.
 */
class OverviewService
{
    /**
     * @param  Collection<int, Institution>  $institutions
     * @param  array<string, mixed>  $position  from ConsolidationService::position()
     * @param  array<string, mixed>  $collections  from ConsolidationService::collections()
     * @param  array<string, mixed>|null  $compliance  from ComplianceService::board(), null if not permitted
     * @param  Collection<int, object>  $findingGroups  from Finding::grouped()
     * @param  array<int, object>  $notReporting  from ConsolidationService::notReporting()
     */
    public function build(
        Collection $institutions,
        string $period,
        array $position,
        array $collections,
        ?array $compliance,
        Collection $findingGroups,
        array $notReporting
    ): object {
        $money = collect($collections['rows'])->keyBy(fn ($row) => $row->institution->id);
        $silent = collect($notReporting)->keyBy(fn ($row) => $row->institution->id);
        $boards = collect($compliance['rows'] ?? [])->keyBy(fn ($row) => $row->institution->id);
        $issues = $findingGroups->groupBy(fn ($group) => $group->latest->institution_id);

        $rows = $institutions->map(fn (Institution $institution) => (object) [
            'institution' => $institution,
            'report' => $this->reportState($institution, $period, $position['reports']->get($institution->id), $silent->get($institution->id)),
            'rules' => $compliance === null ? null : $this->ruleState($boards->get($institution->id)),
            'issues' => $this->issueState($issues->get($institution->id, collect())),
            'collected' => $money->get($institution->id)?->collected,
            'owed' => $money->get($institution->id)?->receivables,
        ])->values();

        $areSchools = $institutions->every(fn (Institution $i) => str_starts_with((string) $i->type, 'school_'));
        $one = $areSchools ? __('school') : __('institution');
        $many = $areSchools ? __('schools') : __('institutions');

        return (object) [
            'one' => $one,
            'many' => $many,
            'rows' => $rows,
            'headlines' => array_values(array_filter([
                $this->reportHeadline($rows, $period, $one, $many),
                $compliance === null ? null : $this->ruleHeadline($rows, $compliance, $one, $many),
                $this->issueHeadline($findingGroups),
            ])),
        ];
    }

    private function reportState(Institution $institution, string $period, $report, ?object $silent): object
    {
        if ($institution->live_from?->isFuture()) {
            return $this->state('not_live', 'muted', __('Being migrated, live from :date', [
                'date' => $institution->live_from->toFormattedDateString(),
            ]));
        }

        if ($report) {
            return $this->state('received', 'good', __('Received :date', [
                'date' => $report->received_at->toFormattedDateString(),
            ]));
        }

        if ($silent?->never_reported) {
            return $this->state('missing', 'bad', __('never reported'), $silent->alive_but_silent
                ? __('Connected, but no report has been sent.')
                : null);
        }

        if ($silent) {
            return $this->state('missing', 'bad', __('Not received'), trans_choice(
                '{1}Nothing for 1 day|[2,*]Nothing for :count days',
                $silent->days_silent,
                ['count' => $silent->days_silent]
            ));
        }

        // A month still running has no report to miss: schools file it once it ends.
        if ($period >= now()->format('Y-m')) {
            return $this->state('not_due', 'muted', __('Not due yet'));
        }

        return $this->state('waiting', 'warn', __('Not received yet'));
    }

    private function ruleState(?object $board): object
    {
        $states = collect($board?->cells ?? [])->filter()->map(fn ($cell) => $cell->state)->countBy();
        $broken = $states->get(ComplianceService::BREACH, 0);
        $unknown = $states->get(ComplianceService::UNKNOWN, 0);
        $complies = $states->get(ComplianceService::COMPLIES, 0);
        $waived = $states->get(ComplianceService::WAIVED, 0);

        $parts = array_filter([
            $broken ? trans_choice('{1}1 rule broken|[2,*]:count rules broken', $broken, ['count' => $broken]) : null,
            $unknown ? trans_choice('{1}1 cannot be checked|[2,*]:count cannot be checked', $unknown, ['count' => $unknown]) : null,
            $waived ? trans_choice('{1}1 waived|[2,*]:count waived', $waived, ['count' => $waived]) : null,
        ]);

        if ($parts === [] && $complies === 0) {
            return (object) ['tone' => 'muted', 'text' => __('No rules apply'), 'broken' => 0, 'unknown' => 0, 'complies' => 0];
        }

        return (object) [
            'tone' => $broken ? 'bad' : ($unknown ? 'warn' : 'good'),
            'text' => $parts === [] ? __('All followed') : implode(', ', $parts),
            'broken' => $broken,
            'unknown' => $unknown,
            'complies' => $complies,
        ];
    }

    private function issueState(Collection $groups): object
    {
        return (object) [
            'count' => $groups->count(),
            'urgent' => $groups->filter(fn ($group) => in_array($group->severity, ['critical', 'high'], true))->count(),
        ];
    }

    private function reportHeadline(Collection $rows, string $period, string $one, string $many): ?object
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $month = period_text($period);
        $expected = $rows->filter(fn ($row) => ! in_array($row->report->key, ['not_live', 'not_due'], true));
        $missing = $expected->filter(fn ($row) => $row->report->key !== 'received');
        $names = $missing->map(fn ($row) => $row->institution->name)->implode(', ');
        $total = $expected->count();

        if ($total === 0) {
            return $this->headline('muted', $rows->contains(fn ($row) => $row->report->key === 'not_due')
                ? __(':month has not finished, so no report is due yet.', ['month' => $month])
                : __('All your :many are still being migrated, so none is judged yet.', ['many' => $many]));
        }

        $overdue = $missing->contains(fn ($row) => $row->report->tone === 'bad');

        if ($missing->isEmpty()) {
            return $this->headline('good', match ($total) {
                1 => __(':name sent its report for :month.', ['name' => $expected->first()->institution->name, 'month' => $month]),
                2 => __('Both :many sent their report for :month.', ['many' => $many, 'month' => $month]),
                default => __('All :count :many sent their report for :month.', ['count' => $total, 'many' => $many, 'month' => $month]),
            });
        }

        if ($missing->count() === $total) {
            return $this->headline($overdue ? 'bad' : 'warn', __('No report has arrived for :month yet. Still missing: :names.', [
                'month' => $month,
                'names' => $names,
            ]));
        }

        return $this->headline($overdue ? 'bad' : 'warn', __(':received of :total :many sent their report for :month. Still missing: :names.', [
            'received' => $total - $missing->count(),
            'total' => $total,
            'many' => $many,
            'month' => $month,
            'names' => $names,
        ]));
    }

    private function ruleHeadline(Collection $rows, array $compliance, string $one, string $many): object
    {
        $link = route('body.compliance.index');

        if ($compliance['regulations']->isEmpty()) {
            return $this->headline('muted', __('You have not set any rules yet, so nothing is being compared.'), $link);
        }

        $breaking = $rows->filter(fn ($row) => $row->rules->broken > 0)->count();
        $unknown = $rows->filter(fn ($row) => $row->rules->broken === 0 && $row->rules->unknown > 0)->count();

        if ($breaking > 0) {
            $text = $breaking === 1
                ? __('1 :one is breaking one of your rules.', ['one' => $one])
                : __(':count :many are breaking your rules.', ['count' => $breaking, 'many' => $many]);

            if ($unknown > 0) {
                $text .= ' '.__(':count more cannot be checked yet.', ['count' => $unknown]);
            }

            return $this->headline('bad', $text, $link);
        }

        if ($unknown > 0) {
            return $this->headline('warn', $unknown === 1
                ? __('1 :one cannot be checked against your rules yet.', ['one' => $one])
                : __(':count :many cannot be checked against your rules yet.', ['count' => $unknown, 'many' => $many]), $link);
        }

        return $this->headline('good', __('Your rules are being followed, going by what is recorded in each system.'), $link);
    }

    private function issueHeadline(Collection $findingGroups): object
    {
        if ($findingGroups->isEmpty()) {
            return $this->headline('good', __('Nothing needs looking into.'));
        }

        $count = $findingGroups->count();
        $urgent = $findingGroups->filter(fn ($group) => in_array($group->severity, ['critical', 'high'], true))->count();

        $text = $count === 1 ? __('1 thing to look into') : __(':count things to look into', ['count' => $count]);

        if ($urgent > 0) {
            $text .= ', '.__(':count needing action', ['count' => $urgent]);
        }

        return $this->headline($urgent > 0 ? 'warn' : 'muted', $text.'.', '#look-into');
    }

    private function state(string $key, string $tone, string $text, ?string $note = null): object
    {
        return (object) ['key' => $key, 'tone' => $tone, 'text' => $text, 'note' => $note];
    }

    private function headline(string $tone, string $text, ?string $href = null): object
    {
        return (object) ['tone' => $tone, 'text' => $text, 'href' => $href];
    }
}
