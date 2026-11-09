<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyInstitutionSignature;
use App\Models\BudgetSheet as Sheet;
use App\Models\Institution;
use App\Models\SyncEvent;
use App\Support\EventText;
use EdutrustPay\Contract\BudgetSheet;
use EdutrustPay\Contract\Canonical;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/budget-sheet
 *
 * Same rules as the daybook: it must belong to whoever signed it, a retry changes
 * nothing, and a change needs the next sequence so it is recorded, not slipped in.
 */
class BudgetSheetController extends Controller
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
                'The budget sheet\'s institution_ref does not match the credential it was signed with.',
            ]);
        }

        if ($errors = BudgetSheet::errors($document)) {
            return $this->reject($institution, 'invalid_budget_sheet', $errors);
        }

        $period = $document['period'];
        $sequence = $document['sequence'];
        $hash = hash('sha256', Canonical::encode(['budget' => $document['budget'], 'lines' => $document['lines']]));

        $sheets = Sheet::withoutBodyScope()->where('institution_id', $institution->id)->where('period', $period);
        $existing = (clone $sheets)->where('sequence', $sequence)->first();
        $highest = (int) (clone $sheets)->max('sequence');

        if ($existing && $existing->payload_hash === $hash) {
            return response()->json(['status' => 'duplicate', 'period' => $period, 'sequence' => $sequence], 200);
        }

        if ($existing || $sequence < $highest) {
            return response()->json([
                'error' => 'sequence_conflict',
                'current_sequence' => $highest,
                'message' => sprintf('The budget sheet for %s is already at sequence %d. File the next sequence to correct it.', $period, $highest),
            ], 409);
        }

        DB::transaction(function () use ($institution, $document, $period, $sequence, $hash, $raw) {
            Sheet::withoutBodyScope()->where('institution_id', $institution->id)->where('period', $period)->update(['is_current' => false]);

            Sheet::withoutBodyScope()->create([
                'body_id' => $institution->body_id,
                'institution_id' => $institution->id,
                'period' => $period,
                'sequence' => $sequence,
                'is_current' => true,
                'currency' => $document['currency'],
                'budget_title' => mb_substr($document['budget']['title'], 0, 255),
                'budget_starts' => $document['budget']['starts'],
                'budget_ends' => $document['budget']['ends'],
                'income_budget' => $document['totals']['income']['budget'] ?? null,
                'income_to_date' => $document['totals']['income']['to_date'] ?? null,
                'expenditure_budget' => $document['totals']['expenditure']['budget'] ?? null,
                'expenditure_to_date' => $document['totals']['expenditure']['to_date'] ?? null,
                'payload' => $raw,
                'payload_hash' => $hash,
                'generated_at' => $document['generated_at'],
                'received_at' => now(),
            ]);
        });

        SyncEvent::record($institution->id, SyncEvent::RECEIVED, $period,
            EventText::budgetSheet($period, $sequence));

        return response()->json(['status' => 'accepted', 'period' => $period, 'sequence' => $sequence], 202);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function reject(Institution $institution, string $code, array $errors): JsonResponse
    {
        SyncEvent::record($institution->id, SyncEvent::REJECTED, null, EventText::refused('budget sheet', $code), ['code' => $code, 'errors' => $errors]);

        return response()->json(['error' => $code, 'errors' => $errors], 422);
    }
}
