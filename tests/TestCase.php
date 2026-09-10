<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against a cached configuration.
     *
     * This console runs with `config:cache` in place, because XAMPP serves it
     * through mod_php: PHP lives inside Apache worker processes that persist
     * across requests, and Laravel's Dotenv is immutable — it will not
     * overwrite a variable another application in that same process already
     * set. Whichever app booted first in a worker wins, so the console would
     * intermittently come up connected to paxhitestbed's database. Caching the
     * configuration makes it ignore the environment entirely, which fixes it in
     * both directions.
     *
     * The cost is this: a cached configuration also overrides the <env> block
     * in phpunit.xml, where DB_CONNECTION=sqlite and DB_DATABASE=:memory: are
     * what keep the suite off the real database. With the cache in place those
     * are silently ignored, RefreshDatabase migrates fresh against MySQL, and
     * the first test run destroys every body, institution and report on the
     * development machine.
     *
     * So the suite stops, loudly, rather than discovering that afterwards. This
     * runs before parent::setUp(), which is where RefreshDatabase does its work.
     */
    protected function setUp(): void
    {
        $cached = __DIR__.'/../bootstrap/cache/config.php';

        if (file_exists($cached)) {
            $this->fail(
                "The configuration is cached, so phpunit.xml's sqlite settings are being ignored\n"
                ."and this suite would run against the real MySQL database — destroying it.\n\n"
                ."  php artisan config:clear     # then run the tests\n"
                ."  php artisan config:cache     # restore it before using the console again\n\n"
                ."See tests/TestCase.php for why the console runs with a cached config at all."
            );
        }

        parent::setUp();
    }
}
