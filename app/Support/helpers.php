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
     * The plain name of an OHADA account group.
     *
     * The contract carries two-digit groups because that is the coarsest level
     * every source system can agree on. But "70" is not a thing a bursary head
     * reads; "Sales of goods and services" is. Without this the console shows a
     * column of numbers beside a column of numbers and asks the reader to know
     * the chart of accounts by heart.
     *
     * SYSCOHADA revised (2017), classes 6 and 7 in full — those are the two the
     * income and expenditure statement is built from — plus the class-heading
     * fallbacks for anything else that appears.
     *
     * Unknown groups fall back to the class name rather than to an empty
     * string: a group this list has not met is still better described as
     * "Financial" than as nothing at all.
     */
    function ohada_group_name(string $group): string
    {
        static $groups = [
            // Class 6 — expenditure
            '60' => 'Purchases and stock movements',
            '61' => 'Transport',
            '62' => 'External services',
            '63' => 'Other external services',
            '64' => 'Taxes and duties',
            '65' => 'Other operating charges',
            '66' => 'Staff costs',
            '67' => 'Financial charges',
            '68' => 'Depreciation and provisions',
            '69' => 'Tax on profits',

            // Class 7 — income
            '70' => 'Sales of goods and services',
            '71' => 'Subsidies and grants',
            '72' => 'Own production',
            '73' => 'Change in stock of finished goods',
            '75' => 'Other operating income',
            '77' => 'Financial income',
            '78' => 'Transfers of charges',
            '79' => 'Reversals of provisions',

            // Groups that turn up in cash, receivables and payables blocks
            '40' => 'Suppliers and related accounts',
            '41' => 'Customers and related accounts',
            '42' => 'Staff',
            '43' => 'Social security bodies',
            '44' => 'State and public bodies',
            '52' => 'Banks',
            '57' => 'Cash on hand',
        ];

        if (isset($groups[$group])) {
            return __($groups[$group]);
        }

        static $classes = [
            '1' => 'Capital and long-term resources',
            '2' => 'Fixed assets',
            '3' => 'Stocks',
            '4' => 'Third parties',
            '5' => 'Treasury',
            '6' => 'Charges',
            '7' => 'Income',
            '8' => 'Other charges and income',
            '9' => 'Analytical accounts',
        ];

        return __($classes[mb_substr($group, 0, 1)] ?? 'Unclassified');
    }
}
