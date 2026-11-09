<?php

namespace App\Support;

/**
 * What kind of institution this is, written once.
 *
 * The type decides which parts of a report can ever apply (a hospital or a
 * shop has no pupils, so enrolment and school fees read "n/a" rather than
 * missing), which rules apply to it, and whether the console calls its
 * institutions "schools". Every form, page and rule reads the names from here.
 */
final class InstitutionType
{
    public const PRIMARY = 'school_primary';

    public const SECONDARY = 'school_secondary';

    public const HIGHER = 'school_higher';

    public const HEALTH = 'health';

    public const BUSINESS = 'business';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PRIMARY, self::SECONDARY, self::HIGHER, self::HEALTH, self::BUSINESS];
    }

    /**
     * One institution, as a form or a page names it.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::PRIMARY => __('Nursery / Primary school'),
            self::SECONDARY => __('Secondary / High school'),
            self::HIGHER => __('University / Higher institute'),
            self::HEALTH => __('Hospital / Health centre / Pharmacy'),
            self::BUSINESS => __('Shop / Other business'),
        ];
    }

    /**
     * The same, under headings, for a form's list.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $options = self::options();

        return [
            __('Schools') => array_intersect_key($options, array_flip([self::PRIMARY, self::SECONDARY, self::HIGHER])),
            __('Health') => [self::HEALTH => $options[self::HEALTH]],
            __('Business') => [self::BUSINESS => $options[self::BUSINESS]],
        ];
    }

    /**
     * Many of them, as a rule says whom it applies to.
     *
     * @return array<string, string>
     */
    public static function plurals(): array
    {
        return [
            self::PRIMARY => __('Nursery and primary schools'),
            self::SECONDARY => __('Secondary and high schools'),
            self::HIGHER => __('Higher education'),
            self::HEALTH => __('Hospitals, health centres and pharmacies'),
            self::BUSINESS => __('Shops and other businesses'),
        ];
    }

    public static function label(?string $type): string
    {
        return self::options()[$type] ?? str_replace('_', ' ', (string) $type);
    }

    /** Short enough for a subtitle or a table cell. */
    public static function shortLabel(?string $type): string
    {
        return match ($type) {
            self::PRIMARY => __('Nursery / Primary school'),
            self::SECONDARY => __('Secondary / High school'),
            self::HIGHER => __('University / Higher institute'),
            self::HEALTH => __('Hospital / Pharmacy'),
            self::BUSINESS => __('Business'),
            default => str_replace('_', ' ', (string) $type),
        };
    }

    public static function isSchool(?string $type): bool
    {
        return str_starts_with((string) $type, 'school_');
    }
}
