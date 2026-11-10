<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Body;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The first run of a new console. `php artisan edutrustpay:install` prepares
 * the server and issues a one-time link; the page behind it creates the
 * operator account and, if wanted, the first organisation it oversees.
 *
 * The link is what keeps the first account safe: without it, anyone who can
 * reach the server could open the page before the technician does, and the
 * operator account sees every body's money.
 */
class PlatformInstaller
{
    private const TOKEN_FILE = '.install-token';

    /** The console is installed once it has an account. */
    public function isInstalled(): bool
    {
        try {
            return DB::table('users')->exists();
        } catch (QueryException|\InvalidArgumentException|\PDOException) {
            // No table yet, or, on a fresh clone with no .env, no database at all.
            return false;
        }
    }

    public function issueToken(): string
    {
        $token = Str::random(48);
        Storage::disk('local')->put(self::TOKEN_FILE, $token);

        return $token;
    }

    public function tokenIsValid(?string $token): bool
    {
        $disk = Storage::disk('local');

        return $token !== null
            && $disk->exists(self::TOKEN_FILE)
            && hash_equals(trim((string) $disk->get(self::TOKEN_FILE)), $token);
    }

    /**
     * @param  array{name: string, email: string, password: string}  $operator
     * @param  array{name: ?string, type: ?string}  $organisation  left empty to add it later
     */
    public function finish(array $operator, array $organisation): User
    {
        $user = DB::transaction(function () use ($operator, $organisation) {
            $user = User::create([
                'name' => $operator['name'],
                'email' => $operator['email'],
                'password' => Hash::make($operator['password']),
                'role' => 'super_admin',
                // The operator belongs to no body: they are outside every one.
                'body_id' => null,
                'is_active' => true,
            ]);

            if (filled($organisation['name'] ?? null)) {
                app(BodyProvisioningService::class)->create([
                    'name' => $organisation['name'],
                    'type' => $organisation['type'] ?? null,
                    'is_active' => true,
                ]);
            }

            return $user;
        });

        AuditLog::log('platform.installed', User::class, $user->id);
        Storage::disk('local')->delete(self::TOKEN_FILE);

        return $user;
    }

    /** The body created during setup, if one was. */
    public function firstBody(): ?Body
    {
        return Body::query()->orderBy('id')->first();
    }
}
