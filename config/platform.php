<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform identity
    |--------------------------------------------------------------------------
    |
    | Brief §16: "EdutrustPay" is a payments brand. Once the platform carries
    | hospitals, pharmacies and shops, the name describes one part of what it
    | does. The public fee-payment portal can keep it; the body console probably
    | should not. So the name is configuration, never a literal in a Blade file.
    |
    | Read these through the platform_name() / console_name() helpers.
    |
    */

    'name' => env('PLATFORM_NAME', 'EdutrustPay'),

    'console_name' => env('PLATFORM_CONSOLE_NAME', 'Body Console'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | XAF (Central African CFA franc) is the statutory currency in Cameroon and
    | has no minor unit in practice. Amounts are still carried as decimal(18,2)
    | throughout, and as decimal *strings* on the wire — see the reporting
    | contract package for why floats are never used for money here.
    |
    */

    'currency' => env('PLATFORM_CURRENCY', 'XAF'),

    /*
    |--------------------------------------------------------------------------
    | Default accent colour
    |--------------------------------------------------------------------------
    |
    | Used when a body has set none of its own, and by every operator page —
    | the operator is outside every body and so wears the platform's colour
    | rather than any one body's.
    |
    | A body's own accent overrides this at runtime through a CSS custom
    | property on the html element, so no rebuild is needed to add one.
    |
    */

    'accent' => env('PLATFORM_ACCENT', '#1f3a5f'),

    /*
    |--------------------------------------------------------------------------
    | The first operator account
    |--------------------------------------------------------------------------
    |
    | Read by PlatformSeeder when standing up a new deployment.
    |
    | These live here rather than being read from env() inside the seeder for a
    | reason that bit this project on XAMPP: the console runs with its config
    | CACHED, because Apache's mod_php keeps one PHP process across requests and
    | Laravel's Dotenv refuses to overwrite variables another application in
    | that same process already set. With the config cached, Laravel never reads
    | .env at runtime at all — which is the point — and any stray env() call
    | outside a config file silently returns null.
    |
    | A blank password means "generate one and print it once".
    |
    */

    'operator' => [
        'name' => env('OPERATOR_NAME', 'EdutrustPay Operator'),
        'email' => env('OPERATOR_EMAIL', 'operator@edutrustpay.test'),
        'password' => env('OPERATOR_PASSWORD'),
    ],

];
