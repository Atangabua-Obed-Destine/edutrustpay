<?php

/**
 * Global helpers, autoloaded via composer's `files` entry.
 *
 * Mirrors edutrustshsm's app/Support/helpers.php convention. Every helper is
 * wrapped in a function_exists() guard so the file stays safe to autoload even
 * if a name later collides with a framework or package helper.
 */

if (! function_exists('platform_name')) {
    /**
     * The platform's display name.
     *
     * Brief §16: EdutrustPay is a payments brand, and the console will outgrow it
     * once it carries hospitals, pharmacies and shops. The name must therefore
     * never be hard-coded into a UI string — read it from here instead.
     */
    function platform_name(): string
    {
        return config('platform.name');
    }
}

if (! function_exists('console_name')) {
    /**
     * The body-facing console's display name, which is deliberately allowed to
     * differ from the platform name (see platform_name() above).
     */
    function console_name(): string
    {
        return config('platform.console_name');
    }
}

if (! function_exists('ohada_group_name')) {
    /**
     * A safe description of an account group: its OHADA class, nothing finer.
     *
     * This used to print the textbook SYSCOHADA name for each group, and on real
     * data that was false. PAX HIGHER INSTITUTE posts tuition to 711 and subsidies
     * to 73, so tuition read as "Subsidies and grants" and subsidies as "Change in
     * stock". edutrustshsm posts tuition to 701. The same two digits mean different
     * things at different schools, so only the school can name its accounts.
     */
    function ohada_group_name(string $group): string
    {
        static $classes = [
            '1' => 'Capital and long-term resources',
            '2' => 'Fixed assets',
            '3' => 'Stocks',
            '4' => 'Third parties',
            '5' => 'Treasury',
            '6' => 'Expenditure',
            '7' => 'Income',
            '8' => 'Other charges and income',
            '9' => 'Analytical accounts',
        ];

        return __($classes[mb_substr($group, 0, 1)] ?? 'Unclassified');
    }
}

if (! function_exists('money_text')) {
    /**
     * An amount as a reader would write it in a sentence: "1 155 341".
     */
    function money_text(string|int|float|null $value): string
    {
        return number_format((float) $value, 0, '.', ' ');
    }
}

if (! function_exists('class_label')) {
    /**
     * An OHADA account group as a reader sees it: "Class 71 · Scolarites" when
     * the school has named it, "Class 71" when it has not. The name is always
     * the school's own; the console never supplies one.
     */
    function class_label(string $group, ?string $name = null): string
    {
        $name = $name !== null ? trim($name) : '';

        return $name === ''
            ? __('Class :code', ['code' => $group])
            : __('Class :code · :name', ['code' => $group, 'name' => $name]);
    }
}

if (! function_exists('period_text')) {
    /**
     * A reporting month in words: "2026-10" becomes "October 2026".
     */
    function period_text(string $period): string
    {
        return \Carbon\Carbon::createFromFormat('!Y-m', $period)->translatedFormat('F Y');
    }
}

if (! function_exists('manage_route')) {
    /**
     * A management route on whichever side the current page is on.
     *
     * The operator and a body's administrators use the same screens for schools
     * and people. The operator's are under admin.*, a body's under body.manage.*,
     * and a form must post back to the side it came from.
     *
     * @param  mixed  $parameters
     */
    function manage_route(string $name, $parameters = []): string
    {
        if (request()->routeIs('admin.*')) {
            return route('admin.'.$name, $parameters);
        }

        // A body has one list of its institutions, the one it already reads.
        if ($name === 'institutions.index') {
            return route('body.institutions.index');
        }

        return route('body.manage.'.$name, $parameters);
    }
}
