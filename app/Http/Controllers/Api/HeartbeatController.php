<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyInstitutionSignature;
use App\Models\Institution;
use App\Models\SyncEvent;
use EdutrustPay\Contract\ContractVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/heartbeat
 *
 * Liveness, even when there is nothing to report.
 *
 * This endpoint matters as much as the report one (brief §8), and it is the
 * least obvious thing in the API. Without it there is no way to tell a quiet
 * month from a stopped bursary server: both look like silence. "Silence is an
 * alarm" (brief §4.5) only works if silence can be distinguished from "nothing
 * happened", and the only party who can tell you which is which is the
 * institution itself.
 */
class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get(VerifyInstitutionSignature::INSTITUTION);

        $institution->forceFill(['last_heartbeat_at' => now()])->saveQuietly();

        SyncEvent::record(
            $institution->id,
            SyncEvent::HEARTBEAT,
            $request->input('period'),
            \App\Support\EventText::heartbeat($request->input('note')),
            array_filter([
                'client_version' => $request->input('client_version'),
                'pending_reports' => $request->input('pending_reports'),
            ], fn ($v) => $v !== null)
        );

        return response()->json([
            'status' => 'ok',
            'acknowledged_at' => now()->toIso8601String(),
            // So a client can notice it is out of date without a separate call.
            'contract_version' => ContractVersion::CURRENT,
        ]);
    }
}
