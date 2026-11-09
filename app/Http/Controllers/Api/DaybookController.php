<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyInstitutionSignature;
use App\Models\DaybookFiling;
use App\Models\Institution;
use App\Models\SyncEvent;
use App\Support\EventText;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\Daybook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/daybook
 *
 * Receives one month of a school's daybook. The signature has already been checked
 * by VerifyInstitutionSignature. Same rules as a report: the document must belong
 * to whoever signed it, a retry changes nothing, and a change needs a new sequence
 * so that it is recorded rather than slipped in underneath.
 */
class DaybookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get(VerifyInstitutionSignature::INSTITUTION);
        $raw = $request->getContent();

        try {
            $document = Canonical::decode($raw);
        } catch (\Throwable $e) {
            return $this->reject($institution, 'malformed_json', [$e->getMessage()]);
        }

        if (($document['institution_ref'] ?? null) !== $institution->uuid) {
            return $this->reject($institution, 'institution_mismatch', [
                'The daybook\'s institution_ref does not match the credential it was signed with.',
            ]);
        }

        if ($errors = Daybook::errors($document)) {
            return $this->reject($institution, 'invalid_daybook', $errors);
        }

        $period = $document['period'];
        $sequence = $document['sequence'];
        $hash = $this->linesHash($document);

        $filings = DaybookFiling::withoutBodyScope()->where('institution_id', $institution->id)->where('period', $period);
        $existing = (clone $filings)->where('sequence', $sequence)->first();
        $highest = (int) (clone $filings)->max('sequence');

        if ($existing && $existing->payload_hash === $hash) {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'This daybook was already received. Nothing was changed.',
                'period' => $period,
                'sequence' => $sequence,
            ], 200);
        }

        // Going backwards is refused too: an old copy must not replace a newer one.
        if ($existing || $sequence < $highest) {
            return response()->json([
                'error' => 'sequence_conflict',
                'current_sequence' => $highest,
                'message' => sprintf(
                    'The daybook for %s is already at sequence %d. To correct it, file the next sequence.',
                    $period,
                    $highest
                ),
            ], 409);
        }

        DB::transaction(function () use ($institution, $document, $period, $sequence, $hash) {
            DaybookFiling::withoutBodyScope()
                ->where('institution_id', $institution->id)
                ->where('period', $period)
                ->update(['is_current' => false]);

            $filing = DaybookFiling::withoutBodyScope()->create([
                'body_id' => $institution->body_id,
                'institution_id' => $institution->id,
                'period' => $period,
                'sequence' => $sequence,
                'is_current' => true,
                'currency' => $document['currency'],
                'money_in' => $document['money_in'],
                'money_out' => $document['money_out'],
                'line_count' => count($document['lines']),
                'payload_hash' => $hash,
                'generated_at' => $document['generated_at'],
                'received_at' => now(),
            ]);

            foreach (array_chunk($document['lines'], 500) as $chunk) {
                DB::table('daybook_lines')->insert(array_map(fn (array $line) => [
                    'daybook_filing_id' => $filing->id,
                    'body_id' => $institution->body_id,
                    'institution_id' => $institution->id,
                    'line_date' => $line['date'],
                    'ref' => mb_substr($line['ref'], 0, 255),
                    'description' => $line['description'],
                    'party' => $line['party'] === null ? null : mb_substr($line['party'], 0, 255),
                    'heading' => mb_substr($line['heading'], 0, 255),
                    'kind' => $line['kind'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'method' => $line['method'],
                    'source_id' => mb_substr($line['source_id'], 0, 255),
                ], $chunk));
            }
        });

        SyncEvent::record(
            $institution->id,
            SyncEvent::RECEIVED,
            $period,
            EventText::daybook($period, $sequence, count($document['lines']))
        );

        return response()->json([
            'status' => 'accepted',
            'period' => $period,
            'sequence' => $sequence,
            'lines' => count($document['lines']),
        ], 202);
    }

    /**
     * The lines alone, so a rebuild with a fresher timestamp still reads as a retry.
     *
     * @param  array<string, mixed>  $document
     */
    private function linesHash(array $document): string
    {
        return hash('sha256', Canonical::encode(['lines' => $document['lines']]));
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function reject(Institution $institution, string $code, array $errors): JsonResponse
    {
        SyncEvent::record($institution->id, SyncEvent::REJECTED, null, EventText::refused('daybook', $code), ['code' => $code, 'errors' => $errors]);

        return response()->json(['error' => $code, 'errors' => $errors], 422);
    }
}
