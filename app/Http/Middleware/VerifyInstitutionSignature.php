<?php

namespace App\Http\Middleware;

use App\Models\InstitutionCredential;
use App\Models\SyncEvent;
use Closure;
use EdutrustPay\Contract\ContractVersion;
use EdutrustPay\Contract\Signer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an institution by API key id + HMAC signature over the raw body.
 *
 * Not a bearer token in a header (brief §8). A bearer token is replayable by
 * anyone who captures it and proves nothing about the body it accompanied; a
 * signature proves both that the sender holds the secret and that these exact
 * bytes are what they sent.
 *
 * Three headers:
 *   X-Edutrust-Key-Id      public, identifies the credential
 *   X-Edutrust-Timestamp   ISO-8601, PART OF THE SIGNED MATERIAL
 *   X-Edutrust-Signature   sha256=<hex>
 *
 * The timestamp is signed rather than merely sent, so a replayer cannot move the
 * staleness window: rewriting it invalidates the signature.
 *
 * VERIFICATION IS OVER THE RAW BODY AS RECEIVED — never over a re-serialised
 * copy. Re-canonicalising here would mean a client whose serialisation drifts by
 * one byte fails with "bad signature", sending everyone hunting for a key
 * problem that does not exist. Authenticity and canonicality stay separate
 * questions with separate error messages; the second is answered by
 * Canonical::isCanonical and reported as a warning, not a rejection.
 */
class VerifyInstitutionSignature
{
    public const KEY_HEADER = 'X-Edutrust-Key-Id';

    public const TIMESTAMP_HEADER = 'X-Edutrust-Timestamp';

    public const SIGNATURE_HEADER = 'X-Edutrust-Signature';

    /** Request attribute the controllers read the authenticated institution from. */
    public const INSTITUTION = 'edutrust.institution';

    public const CREDENTIAL = 'edutrust.credential';

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->header(self::KEY_HEADER, '');
        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($keyId === '' || $timestamp === '' || $signature === '') {
            return $this->deny('missing_credentials', sprintf(
                'Requests must carry %s, %s and %s.',
                self::KEY_HEADER,
                self::TIMESTAMP_HEADER,
                self::SIGNATURE_HEADER
            ));
        }

        /*
         * Replay protection (brief §12), checked BEFORE the signature so a
         * captured-and-replayed request is cheap to reject.
         *
         * Rejects the far future as well as the far past: a bursary server whose
         * clock has run forward is just as much a problem as one that has run
         * back, and silently accepting it would let a report be dated into a
         * period that has not happened yet.
         */
        if (! Signer::timestampIsFresh($timestamp)) {
            return $this->deny('stale_timestamp', sprintf(
                'Timestamp is outside the %d second window. Check the server clock — this is far more '
                .'often a clock than an attack.',
                Signer::MAX_CLOCK_SKEW
            ));
        }

        // No body scope applies: nobody is authenticated yet, which is exactly
        // why BodyScope no-ops outside a request context.
        $credential = InstitutionCredential::query()
            ->active()
            ->with('institution')
            ->where('key_id', $keyId)
            ->first();

        /*
         * An unknown or revoked key and a bad signature give the SAME response.
         * Distinguishing them would let anyone enumerate which key ids are live,
         * and "this key exists but you signed wrong" is information we have no
         * reason to hand out.
         */
        if (! $credential
            || ! $credential->institution
            || ! Signer::verifyRaw($request->getContent(), $timestamp, $signature, $credential->secret())) {
            $this->recordRejection($credential, $request);

            return $this->deny('invalid_signature', 'Key id or signature is not valid.');
        }

        if (! $credential->institution->is_active) {
            return $this->deny('institution_inactive', 'This institution is not currently active.');
        }

        $credential->touchUsage();

        $request->attributes->set(self::INSTITUTION, $credential->institution);
        $request->attributes->set(self::CREDENTIAL, $credential);

        return $next($request);
    }

    /**
     * A rejected push is itself information.
     *
     * An institution whose signature suddenly stops verifying looks identical,
     * from the console, to one that has gone quiet — but the two mean very
     * different things: one is a rotated key nobody finished deploying, the
     * other may be a bursary server that has stopped. Recording the rejection is
     * what keeps them distinguishable.
     */
    private function recordRejection(?InstitutionCredential $credential, Request $request): void
    {
        if (! $credential?->institution) {
            return;
        }

        SyncEvent::record(
            $credential->institution_id,
            SyncEvent::REJECTED,
            null,
            \App\Support\EventText::badSignature(),
            ['key_id' => $credential->key_id, 'path' => $request->path()]
        );
    }

    private function deny(string $code, string $message): Response
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
            // Always tell the client what we speak, so it can detect that it is
            // out of date even on a failed request (brief §8).
            'contract_version' => ContractVersion::CURRENT,
        ], 401);
    }
}
