<?php

use App\Support\EventText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewords the event lines already stored into the plain words the API now
 * writes. Only lines that match the old wording exactly are touched, and a
 * check-in note our school systems did not write stays as it was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $patterns = [
            '/^Report received for (\d{4}-\d{2}) \(sequence (\d+), (provisional|final)\)\.$/'
                => fn ($m) => EventText::report($m[1], (int) $m[2], $m[3]),
            '/^Period (\d{4}-\d{2}) restated at sequence (\d+), superseding sequence (\d+) \(previously (provisional|final)\)\.$/'
                => fn ($m) => EventText::restated($m[1], (int) $m[2], (int) $m[3], $m[4]),
            '/^Different figures resent for (\d{4}-\d{2}) at the same sequence \((\d+)\)\. The original was kept\.$/'
                => fn ($m) => EventText::sameVersionConflict($m[1], (int) $m[2]),
            '/^Daybook received for (\d{4}-\d{2}) \(sequence (\d+), (\d+) lines\)\.$/'
                => fn ($m) => EventText::daybook($m[1], (int) $m[2], (int) $m[3]),
            '/^Budget sheet received for (\d{4}-\d{2}) \(sequence (\d+)\)\.$/'
                => fn ($m) => EventText::budgetSheet($m[1], (int) $m[2]),
            '/^Report rejected: (\w+)$/' => fn ($m) => EventText::refused('report', $m[1]),
            '/^Daybook rejected: (\w+)$/' => fn ($m) => EventText::refused('daybook', $m[1]),
            '/^Budget sheet rejected: (\w+)$/' => fn ($m) => EventText::refused('budget sheet', $m[1]),
            '/^Signature verification failed\.$/' => fn () => EventText::badSignature(),
            '/^Credential rotated by the institution\.$/' => fn () => EventText::keyReplaced(),
        ];

        DB::table('sync_events')->whereNotNull('summary')->orderBy('id')
            ->chunkById(500, function ($events) use ($patterns) {
                foreach ($events as $event) {
                    $new = $this->reword($event, $patterns);

                    if ($new !== null && $new !== $event->summary) {
                        DB::table('sync_events')->where('id', $event->id)->update(['summary' => $new]);
                    }
                }
            });
    }

    /**
     * @param  array<string, callable>  $patterns
     */
    private function reword(object $event, array $patterns): ?string
    {
        if ($event->type === 'heartbeat') {
            return EventText::heartbeat($event->summary);
        }

        if ($event->summary === 'Report was accepted but could not be processed.') {
            return $event->period ? EventText::couldNotProcess($event->period) : null;
        }

        foreach ($patterns as $pattern => $build) {
            if (preg_match($pattern, $event->summary, $m)) {
                return $build($m);
            }
        }

        return null;
    }

    public function down(): void
    {
        // The old wording is not kept; the figures and details behind each line are unchanged.
    }
};
