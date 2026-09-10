<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyInstitutionSignature;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\InstitutionCredential;
use App\Models\SyncEvent;
use EdutrustPay\Contract\ContractVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/credentials/rotate
 *
 * Self-service rotation: an institution proves it holds the current secret by
 * signing the request with it, and receives a new one.
 *
 * The new secret is returned in the response body and is the only time it
 * exists in readable form anywhere.
 *
 * THE OLD CREDENTIAL IS NOT REVOKED IMMEDIATELY. That is deliberate. These are
 * bursary servers on unreliable power and connectivity; if rotation killed the
 * old key at once, a client that received the response but crashed before
 * writing it to disk would be permanently locked out, and recovering it needs
 * the operator. Instead the old key keeps working for a grace period, during
 * which the client proves it has the new one simply by using it. The console
 * shows both, and the operator can always revoke immediately by hand.
 */
class CredentialController extends Controller
{
    /** How long a rotated-from key keeps working. */
    public const GRACE_HOURS = 48;

    public function rotate(Request $request): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get(VerifyInstitutionSignature::INSTITUTION);
        /** @var InstitutionCredential $current */
        $current = $request->attributes->get(VerifyInstitutionSignature::CREDENTIAL);

        $issued = InstitutionCredential::issue(
            $institution,
            'Rotated from '.$current->key_id
        );

        // Mark the old one as rotated but leave it usable until the grace period
        // expires. RevokeRotatedCredentials sweeps it up afterwards.
        $current->forceFill([
            'rotated_at' => now(),
            'revoked_reason' => 'Rotated by the institution; expires '.now()->addHours(self::GRACE_HOURS)->toDateTimeString(),
        ])->saveQuietly();

        SyncEvent::record(
            $institution->id,
            SyncEvent::RECEIVED,
            null,
            'Credential rotated by the institution.',
            ['from_key_id' => $current->key_id, 'to_key_id' => $issued['credential']->key_id]
        );

        AuditLog::log('credential.rotated', InstitutionCredential::class, $issued['credential']->id, null, [
            'institution_id' => $institution->id,
            'from_key_id' => $current->key_id,
            'to_key_id' => $issued['credential']->key_id,
            'initiated_by' => 'institution',
        ]);

        return response()->json([
            'status' => 'rotated',
            'key_id' => $issued['credential']->key_id,
            // Shown once. It is not recoverable from the console afterwards.
            'secret' => $issued['secret'],
            'previous_key_id' => $current->key_id,
            'previous_key_valid_until' => now()->addHours(self::GRACE_HOURS)->toIso8601String(),
            'message' => sprintf(
                'Store this secret now — it cannot be retrieved again. The previous key keeps working for '
                .'%d hours so a failed write does not lock you out.',
                self::GRACE_HOURS
            ),
            'contract_version' => ContractVersion::CURRENT,
        ], 201);
    }
}
