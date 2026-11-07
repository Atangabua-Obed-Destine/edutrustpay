<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use EdutrustPay\Contract\Signer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An API credential for one institution: a public key id and a shared secret.
 *
 * WHY THE SECRET IS ENCRYPTED AND NOT HASHED
 * ------------------------------------------
 * The brief asks for secrets "hashed at rest" (§12) and for HMAC-SHA256 request
 * signing (§7.3). Those cannot both be true: verifying an HMAC requires the
 * actual secret on the server side, and a hash is not recoverable. Hashing would
 * only work if the institution transmitted the secret itself on every request —
 * a bearer token in a header, which §8 explicitly rules out, and which would be
 * strictly worse because the secret would then cross the wire every time.
 *
 * So the secret is encrypted with the application key. That is a genuinely
 * weaker property than hashing and is worth stating plainly: anyone holding both
 * the database and APP_KEY can recover every institution's secret. What follows
 * from that is operational, not cryptographic — keep APP_KEY out of the database
 * backup, make rotation trivial, and record every use so an unexpected one shows.
 *
 * The plaintext is shown exactly once, at issue or rotation. Revoked credentials
 * are retained, never deleted, so the trail can still explain a report signed
 * with a key that no longer works.
 */
class InstitutionCredential extends Model
{
    use Auditable;

    /** How long a replaced key keeps working. */
    public const GRACE_HOURS = 48;

    protected $fillable = [
        'institution_id', 'key_id', 'secret_ciphertext', 'label',
        'last_used_at', 'rotated_at', 'revoked_at', 'revoked_reason', 'created_by',
    ];

    /**
     * Never serialise the secret, even accidentally, into a JSON response, an
     * exception page, or an audit record.
     */
    protected $hidden = ['secret_ciphertext'];

    protected function casts(): array
    {
        return [
            // Laravel encrypts on write and decrypts on read, using APP_KEY.
            'secret_ciphertext' => 'encrypted',
            'last_used_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * The shared secret, for computing or verifying an HMAC.
     *
     * Deliberately a method rather than a magic attribute, so that every place
     * the secret is read is greppable.
     */
    public function secret(): string
    {
        return (string) $this->secret_ciphertext;
    }

    /**
     * Issue a new credential, returning the model and the plaintext secret.
     *
     * The plaintext is returned rather than kept anywhere, so the caller can
     * show it once and then forget it.
     *
     * @return array{credential: self, secret: string}
     */
    public static function issue(Institution $institution, ?string $label = null, ?int $createdBy = null): array
    {
        $secret = Signer::generateSecret();

        $credential = static::create([
            'institution_id' => $institution->id,
            'key_id' => Signer::generateKeyId(),
            'secret_ciphertext' => $secret,
            'label' => $label,
            'created_by' => $createdBy,
        ]);

        return ['credential' => $credential, 'secret' => $secret];
    }

    /**
     * Replace this credential with a fresh one.
     *
     * Rotation issues a NEW credential and revokes this one, rather than editing
     * this row in place. That matters: reports already received were signed with
     * the old key, and the trail has to be able to say so. It also means the
     * institution can be updated before the old key is revoked, so rotation does
     * not have to be a synchronised outage.
     *
     * @return array{credential: self, secret: string}
     */
    public function rotate(?int $actorId = null, string $reason = 'Rotated'): array
    {
        $issued = static::issue($this->institution, $this->label, $actorId);

        // The old key stays usable for the grace period. A bursary server that
        // loses power before writing the new secret must not be locked out, and
        // expireRotated() retires the old key afterwards.
        $this->update([
            'revoked_reason' => $reason,
            'rotated_at' => now(),
        ]);

        return $issued;
    }

    public function isInGracePeriod(): bool
    {
        return $this->isActive() && $this->rotated_at !== null;
    }

    public function graceEndsAt(): ?\Carbon\CarbonInterface
    {
        return $this->rotated_at?->copy()->addHours(self::GRACE_HOURS);
    }

    /**
     * Retire keys whose grace period has run out. Run by edutrustpay:sweep.
     *
     * @return int how many keys were retired
     */
    public static function expireRotated(): int
    {
        return static::query()
            ->whereNull('revoked_at')
            ->whereNotNull('rotated_at')
            ->where('rotated_at', '<=', now()->subHours(self::GRACE_HOURS))
            ->update(['revoked_at' => now()]);
    }

    public function revoke(string $reason, ?int $actorId = null): void
    {
        $this->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        AuditLog::log('credential.revoked', static::class, $this->id, null, [
            'key_id' => $this->key_id,
            'institution_id' => $this->institution_id,
            'reason' => $reason,
            'actor' => $actorId,
        ]);
    }

    /**
     * Record that this key was just used, without touching updated_at or the
     * audit trail — it happens on every single request.
     */
    public function touchUsage(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
