<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\ContractVersion;
use EdutrustPay\Contract\Signer;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/contract
 *
 * The current contract version and its schema.
 *
 * Deliberately UNAUTHENTICATED. A client that cannot sign correctly — the exact
 * situation where knowing the contract matters most — must still be able to find
 * out what this server expects. There is nothing here that is not already in the
 * shared package.
 */
class ContractController extends Controller
{
    public function show(): JsonResponse
    {
        $schemaPath = base_path('packages/reporting-contract/schema/report.v'.ContractVersion::CURRENT.'.json');

        return response()->json([
            'contract_version' => ContractVersion::CURRENT,
            'supported_versions' => ContractVersion::SUPPORTED,
            'capabilities' => Capability::all(),
            'signing' => [
                'algorithm' => 'HMAC-SHA256',
                'signature_base' => 'timestamp . "." . canonical_json',
                'headers' => [
                    'key_id' => \App\Http\Middleware\VerifyInstitutionSignature::KEY_HEADER,
                    'timestamp' => \App\Http\Middleware\VerifyInstitutionSignature::TIMESTAMP_HEADER,
                    'signature' => \App\Http\Middleware\VerifyInstitutionSignature::SIGNATURE_HEADER,
                ],
                'max_clock_skew_seconds' => Signer::MAX_CLOCK_SKEW,
                'notes' => [
                    'Money is a decimal string such as "4200000.00", never a JSON number.',
                    'The period is always a calendar month, "YYYY-MM", never a fiscal year.',
                    'A capability absent from "capabilities" means CANNOT REPORT; a figure of 0 means genuinely zero.',
                    'The signature is verified over the raw request body exactly as sent.',
                ],
            ],
            'schema' => is_file($schemaPath) ? json_decode((string) file_get_contents($schemaPath), true) : null,
        ]);
    }
}
