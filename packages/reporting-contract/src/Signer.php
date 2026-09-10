<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

use EdutrustPay\Contract\Exceptions\ContractException;

/**
 * HMAC-SHA256 signing and verification for contract payloads.
 *
 * Two decisions here are worth stating plainly, because both are easy to get
 * subtly wrong and neither failure is loud:
 *
 * 1. THE TIMESTAMP IS PART OF THE SIGNED MATERIAL.
 *    The signature base is "{timestamp}.{body}", not the body alone. If the
 *    timestamp were merely a header, an attacker replaying a captured report
 *    could rewrite it to defeat the staleness window and the signature would
 *    still verify. Binding it means the replay window cannot be moved.
 *
 * 2. THE SERVER VERIFIES OVER THE RAW BODY AS RECEIVED — never over a
 *    re-serialised copy. Re-canonicalising server-side would mean a client whose
 *    serialisation drifts by one byte fails authentication with a message about
 *    a bad signature, which sends everybody hunting for a key problem that does
 *    not exist. Verifying raw bytes keeps authenticity and canonicality as two
 *    separate questions with two separate error messages. (Canonical::isCanonical
 *    answers the second one.)
 *
 * paxhitestbed already has a working precedent for the idea in
 * AdmissionFeesReportController::verificationCodeFor(), but that is a 16-hex
 * truncation keyed on APP_KEY and is not reusable as a transport signature:
 * here we use the full digest and a per-institution secret.
 */
final class Signer
{
    /** Prefix on the wire, so the algorithm can be rotated later without ambiguity. */
    public const PREFIX = 'sha256=';

    /**
     * How far a report's timestamp may be from our clock, in seconds.
     *
     * Generous at five minutes because the institutions run on bursary servers
     * with power cuts and no reliable NTP, and a report that is rejected for
     * clock skew looks to a bursar exactly like one rejected for fraud.
     */
    public const MAX_CLOCK_SKEW = 300;

    /**
     * Sign a payload array.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $timestamp, string $secret): string
    {
        return self::signRaw(Canonical::encode($payload), $timestamp, $secret);
    }

    /**
     * Sign an already-serialised body. This is what a client sends.
     */
    public static function signRaw(string $body, string $timestamp, string $secret): string
    {
        if ($secret === '') {
            throw new ContractException('Refusing to sign with an empty secret.');
        }

        return self::PREFIX.hash_hmac('sha256', self::signatureBase($timestamp, $body), $secret);
    }

    /**
     * Verify a signature against the raw body exactly as received.
     *
     * Uses hash_equals so the comparison is not timing-dependent.
     */
    public static function verifyRaw(string $body, string $timestamp, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::signRaw($body, $timestamp, $secret), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function verify(array $payload, string $timestamp, string $signature, string $secret): bool
    {
        return self::verifyRaw(Canonical::encode($payload), $timestamp, $signature, $secret);
    }

    /**
     * The exact bytes the HMAC is taken over.
     *
     * Kept public and separate so that a client in another language, or a
     * developer debugging a mismatch by hand, can reproduce it without reading
     * the rest of this class.
     */
    public static function signatureBase(string $timestamp, string $body): string
    {
        return $timestamp.'.'.$body;
    }

    /**
     * Is this timestamp within the accepted window of now?
     *
     * Replay protection (brief §12). Rejects both the far past and the far
     * future — a clock set forward is just as much a problem as one set back.
     *
     * @param  string  $timestamp  ISO-8601, as carried in the request header
     */
    public static function timestampIsFresh(string $timestamp, ?int $now = null, int $tolerance = self::MAX_CLOCK_SKEW): bool
    {
        $parsed = strtotime($timestamp);

        if ($parsed === false) {
            return false;
        }

        return abs(($now ?? time()) - $parsed) <= $tolerance;
    }

    /**
     * Generate a new institution secret.
     *
     * 32 random bytes, hex encoded. Credential rotation must be a one-click
     * action (brief §9): a departing bursar holding a live API key is a real
     * risk, not a theoretical one.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a public key id. Safe to log and to display in the console.
     */
    public static function generateKeyId(): string
    {
        return 'etp_'.bin2hex(random_bytes(8));
    }
}
