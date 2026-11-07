<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyInstitutionSignature;
use App\Jobs\ProcessPeriodReport;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\PeriodReport;
use App\Models\SyncEvent;
use EdutrustPay\Contract\Canonical;
use EdutrustPay\Contract\ContractVersion;
use EdutrustPay\Contract\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/reports
 *
 * Receives a signed period report. The signature has already been verified by
 * VerifyInstitutionSignature; what happens here is about meaning rather than
 * authenticity.
 */
class ReportController extends Controller
{
    public function store(Request $request, Validator $validator): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get(VerifyInstitutionSignature::INSTITUTION);
        /** @var InstitutionCredential $credential */
        $credential = $request->attributes->get(VerifyInstitutionSignature::CREDENTIAL);

        $raw = $request->getContent();

        try {
            $payload = Canonical::decode($raw);
        } catch (\Throwable $e) {
            return $this->reject($institution, 'malformed_json', [$e->getMessage()]);
        }

        if (! ContractVersion::supports((string) ($payload['contract_version'] ?? ''))) {
            return $this->reject($institution, 'unsupported_contract_version', [sprintf(
                'This server speaks contract version %s.',
                implode(', ', ContractVersion::SUPPORTED)
            )]);
        }

        /*
         * Record the version this institution actually reports in.
         *
         * `institutions.contract_version` is a RECORD, not a gate. Gating on it
         * would mean a school that upgraded before the operator updated its row
         * stops reporting — an outage caused by bookkeeping, and no safer for
         * it, since the server has already checked it can read the payload.
         *
         * Kept current here so that during a staged rollout the operator can
         * see where each institution has actually got to, rather than what
         * somebody once typed. Same reasoning as observed capabilities.
         */
        if ($institution->contract_version !== $payload['contract_version']) {
            $institution->forceFill(['contract_version' => $payload['contract_version']])->saveQuietly();
        }

        /*
         * The payload must belong to the institution that signed it.
         *
         * Without this, an institution holding a valid key could file reports
         * under a sibling's reference — the one cross-tenant hole the HMAC alone
         * does not close, because the signature only proves who sent it, not who
         * it is about.
         */
        if (($payload['institution_ref'] ?? null) !== $institution->uuid) {
            return $this->reject($institution, 'institution_mismatch', [
                'The payload\'s institution_ref does not match the credential it was signed with.',
            ]);
        }

        $result = $validator->validate($payload);

        if (! $result['valid']) {
            return $this->reject($institution, 'invalid_payload', $result['errors']);
        }

        $period = (string) $payload['period'];
        $sequence = (int) $payload['sequence'];
        $hash = hash('sha256', $raw);

        $existing = PeriodReport::withoutBodyScope()
            ->where('institution_id', $institution->id)
            ->where('period', $period)
            ->where('sequence', $sequence)
            ->first();

        if ($existing) {
            return $this->handleResend($existing, $institution, $period, $sequence, $hash, $raw);
        }

        $report = PeriodReport::withoutBodyScope()->create([
            'institution_id' => $institution->id,
            'body_id' => $institution->body_id,
            'period' => $period,
            'status' => $payload['status'],
            'sequence' => $sequence,
            'contract_version' => $payload['contract_version'],
            'currency' => $payload['currency'],
            'capabilities' => $payload['capabilities'],
            'payload' => $raw,
            'payload_hash' => $hash,
            'signature' => $request->header(VerifyInstitutionSignature::SIGNATURE_HEADER),
            'key_id' => $credential->key_id,
            'generated_at' => $payload['generated_at'],
            'received_at' => now(),
            // Accepted and stored. These become Findings; they are never a reason
            // to refuse a report — see the contract Validator for why.
            'warnings' => $result['warnings'] ?: null,
        ]);

        SyncEvent::record(
            $institution->id,
            SyncEvent::RECEIVED,
            $period,
            sprintf('Report received for %s (sequence %d, %s).', $period, $sequence, $payload['status']),
            [
                'warnings' => array_column($result['warnings'], 'code'),
                // Not an error: the signature is verified over raw bytes, so a
                // client whose serialisation has drifted still authenticates.
                // Recording it is what turns "works by luck" into something
                // visible before the luck runs out.
                'canonical' => Canonical::isCanonical($raw),
            ],
            $report->id
        );

        ProcessPeriodReport::dispatch($report->id);

        return response()->json([
            'status' => 'accepted',
            'report_id' => $report->id,
            'period' => $period,
            'sequence' => $sequence,
            'warnings' => array_column($result['warnings'], 'code'),
            'contract_version' => ContractVersion::CURRENT,
        ], 202);
    }

    /**
     * The same (institution, period, sequence) has already been received.
     *
     * Two very different cases hide here, and conflating them would be a
     * mistake:
     *
     *   Same bytes    — an outbox retry. Expected, harmless, and must never
     *                   double-count. Answer 200 and move on.
     *
     *   Different bytes — the institution regenerated a report for a sequence it
     *                   had already used. That is not a retry: the figures for a
     *                   period changed without the sequence incrementing, which
     *                   is precisely the restatement path being bypassed. Refuse
     *                   it, keep the original, and make it visible.
     */
    private function handleResend(
        PeriodReport $existing,
        Institution $institution,
        string $period,
        int $sequence,
        string $hash,
        string $raw
    ): JsonResponse {
        /*
         * Same figures with a fresher generated_at is still a resend. A school
         * whose outbox was lost rebuilds its months with new timestamps, and
         * calling that a conflict would force a restatement of nothing.
         */
        $sameFigures = fn (string $json) => array_diff_key(json_decode($json, true) ?: [], ['generated_at' => 1]);

        if ($existing->payload_hash === $hash || $sameFigures($existing->payload) == $sameFigures($raw)) {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'This report was already received. Nothing was changed.',
                'report_id' => $existing->id,
                'period' => $period,
                'sequence' => $sequence,
                'contract_version' => ContractVersion::CURRENT,
            ], 200);
        }

        SyncEvent::record(
            $institution->id,
            SyncEvent::REJECTED,
            $period,
            sprintf(
                'Different figures resent for %s at the same sequence (%d). The original was kept.',
                $period,
                $sequence
            ),
            ['existing_report_id' => $existing->id, 'existing_hash' => $existing->payload_hash, 'offered_hash' => $hash],
            $existing->id
        );

        return response()->json([
            'error' => 'sequence_conflict',
            // So the client can file its correction at the next sequence itself
            // rather than failing silently in an outbox nobody reads.
            'current_sequence' => (int) PeriodReport::withoutBodyScope()
                ->where('institution_id', $institution->id)
                ->where('period', $period)
                ->max('sequence'),
            'message' => sprintf(
                'Period %s sequence %d was already received with different figures. To correct a period, '
                .'increment the sequence so the change is recorded as a restatement — figures must never '
                .'change underneath a sequence that has already been filed.',
                $period,
                $sequence
            ),
            'contract_version' => ContractVersion::CURRENT,
        ], 409);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function reject(Institution $institution, string $code, array $errors): JsonResponse
    {
        SyncEvent::record(
            $institution->id,
            SyncEvent::REJECTED,
            null,
            'Report rejected: '.$code,
            ['errors' => $errors]
        );

        return response()->json([
            'error' => $code,
            'errors' => $errors,
            'contract_version' => ContractVersion::CURRENT,
        ], 422);
    }
}
