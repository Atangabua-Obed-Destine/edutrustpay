<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\OnboardingStep;

/**
 * The onboarding checklist for an institution (brief §9).
 *
 * Shaped after edutrustshsm's ConfigurationHealthService: an accumulator that
 * returns a plain array and knows nothing about how it will be rendered.
 *
 * THE ORDER IS NOT ARBITRARY. Two steps carry warnings the others do not:
 * a merchant account in the institution's own name takes longest and should be
 * started first, and the chart of accounts being mapped to OHADA groups is what
 * decides whether any of the rest is worth anything. An institution can be
 * pushing perfectly signed reports and still be useless to the body if its
 * accounts were never mapped.
 *
 * Some steps are OBSERVED rather than ticked. Whether credentials were issued
 * and whether a first report arrived are facts the platform already knows, and
 * asking somebody to tick a box the database can answer is how checklists start
 * lying.
 */
class OnboardingChecklistService
{
    /**
     * @return array<string, array{label: string, why: string, manual: bool}>
     */
    public static function steps(): array
    {
        return [
            'server_power' => [
                'label' => __('A computer and power backup'),
                'why' => __('The institution keeps its books on its own computer. Reporting has to survive a power cut.'),
                'manual' => true,
            ],
            'internet' => [
                'label' => __('Some internet access'),
                'why' => __('Only to send. A phone hotspot for a few minutes a day is enough: reports wait on the computer and go when they can.'),
                'manual' => true,
            ],
            'ohada_mapping' => [
                'label' => __('Chart of accounts matched to the OHADA classes'),
                'why' => __('Without this its figures cannot be added up with, or compared against, other institutions.'),
                'manual' => true,
            ],
            'fiscal_periods' => [
                'label' => __('Its year and months opened in its system'),
                'why' => __('Without this no month can be closed, so every report stays “still open”.'),
                'manual' => true,
            ],
            'credentials_issued' => [
                'label' => __('Connection key issued'),
                'why' => __('Ticks itself once a key is issued on this page.'),
                'manual' => false,
            ],
            'first_push' => [
                'label' => __('First report received'),
                'why' => __('Ticks itself. Until then, nothing above has been shown to work.'),
                'manual' => false,
            ],
            'data_owner' => [
                'label' => __('A named person responsible for the figures'),
                'why' => __('Someone to ask when something needs looking into. A question with nobody to ask goes nowhere.'),
                'manual' => true,
            ],
            'bursar_trained' => [
                'label' => __('Bursar trained'),
                'why' => __('They need to know the report is produced by the system, not typed, and that a month sent again shows here.'),
                'manual' => true,
            ],
        ];
    }

    /**
     * The checklist for one institution.
     *
     * @return array{
     *     steps: array<int, object>,
     *     complete: int, total: int, percent: int,
     *     blocking: array<int, string>
     * }
     */
    public function for(Institution $institution): array
    {
        $recorded = OnboardingStep::where('institution_id', $institution->id)->get()->keyBy('step');

        $observed = [
            'credentials_issued' => $institution->credentials()->active()->exists(),
            'first_push' => $institution->last_report_at !== null,
        ];

        $steps = [];
        $complete = 0;
        $blocking = [];

        foreach (self::steps() as $key => $meta) {
            $row = $recorded->get($key);

            $done = $meta['manual']
                ? ($row?->completed_at !== null)
                : ($observed[$key] ?? false);

            if ($done) {
                $complete++;
            }

            $steps[] = (object) [
                'key' => $key,
                'label' => $meta['label'],
                'why' => $meta['why'],
                'manual' => $meta['manual'],
                'done' => $done,
                'completed_at' => $meta['manual'] ? $row?->completed_at : null,
                // A step that ticks itself has nobody to name; "ticks itself" beside it already says so.
                'completed_by' => $meta['manual'] ? $row?->completedBy?->name : null,
                'note' => $row?->note,
            ];

            /*
             * An institution can be reporting happily and still be worthless to
             * the body if its accounts were never mapped, because its figures
             * cannot be compared with anyone else's. That is worth saying out
             * loud rather than leaving as an unticked box among eight others.
             */
            if (! $done && $key === 'ohada_mapping') {
                $blocking[] = $meta['label'];
            }
        }

        $total = count($steps);

        return [
            'steps' => $steps,
            'complete' => $complete,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($complete / $total * 100) : 0,
            'blocking' => $blocking,
        ];
    }

    /**
     * Tick or un-tick a manual step.
     */
    public function set(Institution $institution, string $step, bool $done, ?int $userId, ?string $note = null): void
    {
        $meta = self::steps()[$step] ?? null;

        if (! $meta || ! $meta['manual']) {
            // Observed steps are facts, not opinions — refuse to let anyone tick
            // one by hand, or the checklist stops meaning anything.
            return;
        }

        OnboardingStep::updateOrCreate(
            ['institution_id' => $institution->id, 'step' => $step],
            [
                'completed_at' => $done ? now() : null,
                'completed_by' => $done ? $userId : null,
                'note' => $note,
            ]
        );
    }
}
