<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;

/**
 * Figures were added to a month after that month had ended.
 *
 * A NOTE ON WHAT THIS DOES NOT MEAN, because the obvious reading is wrong and it
 * was proved wrong on real data. Defining "backdated" as entry_date earlier than
 * created_at flags 784 of the 794 entries in the paxhitestbed ledger — 780 of
 * them were entered in one batch carrying historical dates. Every real bursary
 * does the same in miniature: last month's transactions are entered this month,
 * legitimately, every month.
 *
 * So the client is asked a narrower question: how much of THIS month's reported
 * figure was entered after the month closed. Reversals are excluded, because
 * paxhitestbed deliberately dates a reversal to the original entry while its
 * period is still open.
 *
 * A count above zero is not itself wrong — a late invoice is normal. It matters
 * because paired with `final_restated` it says the figures for a closed period
 * are still moving.
 */
class BackdatedEntriesRule implements Rule
{
    public function code(): string
    {
        return 'backdated_entries';
    }

    public function stage(): string
    {
        return self::STAGE_REPORT;
    }

    private function periodEnd(string $period): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth();
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $report = $context->report;

        if (! $report || $report->backdated_entries === null) {
            return null;
        }

        $threshold = (int) $context->setting('threshold', 1);

        if ($report->backdated_entries < $threshold) {
            return null;
        }

        /*
         * BACKFILL IS NOT A CONTROL EVENT.
         *
         * When an institution is first connected it pushes its history, and
         * every one of those months was by definition assembled after it closed.
         * Running this rule over them raises one finding per historical month
         * for an institution that has done nothing wrong — eleven of them for
         * the paxhitestbed testbed on day one — which is exactly how a findings
         * list becomes wallpaper.
         *
         * So a period that ended before the institution was enrolled here is
         * treated as history being loaded, not as figures being changed. Every
         * other rule still runs on those months: an unbalanced historical ledger
         * is worth knowing about whenever it happened.
         */
        $enrolledAt = $context->institution->created_at;

        if ($enrolledAt && $this->periodEnd($report->period)->lessThan($enrolledAt->startOfMonth())) {
            return null;
        }

        $total = $report->journal_entry_count ?: null;
        $share = $total ? round($report->backdated_entries / $total * 100) : null;

        return new FindingDraft(
            rule: $this->code(),
            // Most of a month arriving late is a different matter from a couple
            // of straggling invoices.
            severity: $share !== null && $share > 50 ? 'high' : 'medium',
            title: trans_choice('{1}1 entry was added after the month ended|[2,*]:count entries were added after the month ended', (int) $report->backdated_entries, ['count' => $report->backdated_entries]),
            explanation: __(
                ':count of the entries behind :name\'s :period figures were created after :period had '
                .'ended:share. Some of this is ordinary — invoices arrive late. It matters when a period '
                .'that has already been reported keeps changing, so read it alongside any restatement of '
                .'the same month.',
                [
                    'count' => $report->backdated_entries,
                    'name' => $context->institution->name,
                    'period' => $report->period,
                    'share' => $share !== null ? __(', about :share% of the month', ['share' => $share]) : '',
                ]
            ),
            figures: array_filter([
                'backdated_entries' => $report->backdated_entries,
                'journal_entry_count' => $report->journal_entry_count,
                'share_percent' => $share,
                'reversals' => $report->reversals,
            ], fn ($v) => $v !== null),
            period: $report->period,
        );
    }
}
