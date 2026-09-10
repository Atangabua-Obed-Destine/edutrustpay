<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The application's only service provider.
 *
 * edutrustshsm keeps everything here in named private methods rather than
 * scattering providers, and that convention is worth keeping: there is exactly
 * one place to look for "what is registered".
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerAuthorization();
        $this->registerRateLimiters();
    }

    /**
     * Bridge every permission name to a Gate ability.
     *
     * This is what makes `@can('finding.resolve')` work with no Gate
     * definitions anywhere: any seeded permission name is usable immediately.
     * Ported from edutrustshsm.
     *
     * Returning null rather than false on the last line matters — it falls
     * through to Laravel's normal resolution instead of short-circuiting to a
     * denial, so a genuine Gate or Policy defined later still gets its say.
     */
    private function registerAuthorization(): void
    {
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            return $user->hasPermission($ability) ? true : null;
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by($request->ip()));

        /*
         * Ingest is limited PER API KEY, not per IP.
         *
         * Institutions are behind whatever connectivity they can get — a shared
         * hotspot, a mission's single VSAT — so several may legitimately arrive
         * from one address, and limiting by IP would let one institution's retry
         * storm silence its neighbours. The key is the thing we actually want to
         * bound.
         *
         * The limit is generous because the failure mode we care about is a
         * broken outbox retrying in a tight loop, not a determined attacker: the
         * HMAC does that work.
         */
        RateLimiter::for('ingest', function ($request) {
            $keyId = $request->header('X-Edutrust-Key-Id') ?: $request->ip();

            return Limit::perMinute(60)->by($keyId);
        });
    }
}
