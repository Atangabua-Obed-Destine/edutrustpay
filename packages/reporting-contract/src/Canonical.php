<?php

declare(strict_types=1);

namespace EdutrustPay\Contract;

use EdutrustPay\Contract\Exceptions\ContractException;

/**
 * Deterministic JSON serialisation.
 *
 * This is the crux of the whole contract. The signature authenticating a report
 * is an HMAC over these bytes, so if two implementations serialise the same
 * payload differently, every report fails verification and the failure is
 * miserable to diagnose — the figures look identical in both logs.
 *
 * The rules, and why each exists:
 *
 *   1. Object keys are sorted, recursively, as BYTE STRINGS. PHP preserves
 *      insertion order, so two clients building the same payload in a different
 *      statement order would otherwise emit different bytes.
 *
 *   2. Maps are forced to JSON objects. This is a genuine PHP trap: PHP coerces
 *      numeric-string array keys to integers, so ['70' => x] becomes [70 => x],
 *      and an array whose integer keys happen to run 0,1,2,... is encoded by
 *      json_encode as a JSON *array*, not an object.
 *
 *      Be honest about the limit here: at the point this code runs, a PHP array
 *      ['0' => 'a', '1' => 'b'] is INDISTINGUISHABLE from the list ['a', 'b'].
 *      No amount of inspection can separate them, so this class cannot fix that
 *      case on its own. A caller whose map might have list-shaped keys, or might
 *      be empty, passes a stdClass to say "this is a map" — and PayloadBuilder
 *      does exactly that for every OHADA group map, rather than relying on the
 *      accident that groups happen to run "10".."99" today.
 *
 *   3. No floats, anywhere. A float in the payload is refused outright rather
 *      than encoded, because its byte representation is not reproducible across
 *      PHP versions. Money is decimal strings (see Money); counts are integers;
 *      rates are decimal strings too. This check is what makes rule 3 of the
 *      contract enforceable instead of merely documented.
 *
 *   4. No pretty printing, and slashes and unicode left unescaped. Escaping is
 *      optional in JSON, so it must be pinned or it varies.
 *
 * The output is UTF-8 and contains no insignificant whitespace.
 *
 * This is close to RFC 8785 (JCS) but deliberately not a full implementation:
 * JCS specifies a number canonicalisation we do not need precisely because we
 * refuse floats and carry money as strings.
 */
final class Canonical
{
    /**
     * Serialise a payload to its canonical byte string.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        $json = json_encode(
            self::normalise($payload, ''),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (! is_string($json)) {
            throw new ContractException('Canonical encoding failed to produce a string.');
        }

        return $json;
    }

    /**
     * Decode a canonical (or any) JSON document to an associative array.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        // Decode to objects first, not associative arrays. json_decode with
        // assoc=true turns {} into an empty PHP array, which is indistinguishable
        // from [] and would re-encode as [] — so a perfectly canonical document
        // would fail to round-trip, and income_by_group in a dormant month would
        // change shape on its way through the ingest queue.
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (! $decoded instanceof \stdClass) {
            throw new ContractException('Payload JSON must decode to an object.');
        }

        $arrays = self::toArrays($decoded);

        if (! is_array($arrays)) {
            throw new ContractException('Payload JSON must decode to an object.');
        }

        return $arrays;
    }

    /**
     * Convert a decoded object tree to arrays, keeping EMPTY maps as stdClass.
     *
     * Arrays are far pleasanter to work with downstream, so non-empty maps are
     * converted; normalise() recognises them as maps again because their keys
     * are not list-shaped. The one case arrays genuinely cannot represent is the
     * empty map, so that alone stays a stdClass.
     *
     * Residual limitation, stated plainly: a NON-empty map whose keys are
     * exactly "0","1",... would still degrade to a list on re-encode. No such
     * map exists in this contract — OHADA groups run "10".."99" and ageing
     * buckets are named — and PayloadBuilder marks every group map explicitly,
     * so this cannot bite in practice.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function toArrays($value)
    {
        if ($value instanceof \stdClass) {
            $assoc = (array) $value;

            if ($assoc === []) {
                return new \stdClass();
            }

            $out = [];

            foreach ($assoc as $key => $item) {
                $out[$key] = self::toArrays($item);
            }

            return $out;
        }

        if (is_array($value)) {
            return array_map([self::class, 'toArrays'], $value);
        }

        return $value;
    }

    /**
     * Is this string already in canonical form?
     *
     * Used by the ingest side to detect a client whose serialisation has drifted.
     * The signature is verified over the raw body as received, so a drifted
     * client still authenticates correctly; this check is what turns "works by
     * luck" into a visible warning before the luck runs out.
     */
    public static function isCanonical(string $json): bool
    {
        try {
            return self::encode(self::decode($json)) === $json;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Recursively sort keys and reject anything that cannot be encoded
     * reproducibly.
     *
     * @param  mixed  $value
     * @param  string  $path  dotted path, used only to make errors locatable
     * @return mixed
     */
    private static function normalise($value, string $path)
    {
        if (is_float($value)) {
            throw new ContractException(sprintf(
                'Float found at "%s". Floats are refused in contract payloads because their JSON '
                .'encoding is not reproducible across PHP versions, which would break the payload '
                .'signature. Money must be a decimal string (see Money); counts must be integers.',
                $path === '' ? '(root)' : $path
            ));
        }

        if ($value instanceof Money) {
            return $value->value();
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        // A stdClass always means "this is a map", whatever its keys look like.
        // See the note on rule 2 above for why the caller has to tell us.
        if ($value instanceof \stdClass) {
            return self::normaliseMap((array) $value, $path);
        }

        if (is_array($value)) {
            if (self::isList($value)) {
                $out = [];

                foreach ($value as $i => $item) {
                    $out[] = self::normalise($item, $path === '' ? "[$i]" : "$path.[$i]");
                }

                return $out;
            }

            return self::normaliseMap($value, $path);
        }

        if (is_object($value)) {
            throw new ContractException(sprintf(
                'Object of type %s found at "%s". Contract payloads must contain only scalars, '
                .'arrays and Money instances — convert it before building the payload.',
                get_class($value),
                $path === '' ? '(root)' : $path
            ));
        }

        throw new ContractException(sprintf(
            'Value of type %s at "%s" cannot be encoded in a contract payload.',
            gettype($value),
            $path === '' ? '(root)' : $path
        ));
    }

    /**
     * Sort a map's keys as byte strings and normalise its values.
     *
     * Returns stdClass so the result can never degrade into a JSON array on the
     * way through json_encode.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function normaliseMap(array $value, string $path): \stdClass
    {
        // PHP has already coerced numeric-string keys to ints, so cast back
        // before sorting to keep the ordering stable regardless.
        $keys = array_map('strval', array_keys($value));
        sort($keys, SORT_STRING);

        $out = new \stdClass();

        foreach ($keys as $key) {
            $original = array_key_exists($key, $value) ? $value[$key] : $value[(int) $key];
            $out->{$key} = self::normalise($original, $path === '' ? $key : "$path.$key");
        }

        return $out;
    }

    /**
     * array_is_list(), polyfilled.
     *
     * The package targets PHP 8.0 because posinnova runs Laravel 9 on it, and
     * array_is_list() arrived in 8.1.
     *
     * @param  array<mixed>  $array
     */
    private static function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
