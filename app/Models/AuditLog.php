<?php

namespace App\Models;

use App\Support\BodyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    // NOTE: deliberately NOT Auditable — auditing the audit log would recurse.
    //
    // It also does NOT use BelongsToBody. That trait's global scope filters on
    // body_id, and entries written outside a request context (the ingest queue,
    // the staleness sweep, seeders) carry a NULL body — which would make them
    // permanently invisible. An audit trail that silently hides entries is worse
    // than none, so body filtering here is explicit (see scopeVisible) and
    // always keeps system-level entries.

    public $timestamps = false;

    protected $fillable = [
        'body_id', 'user_id', 'action', 'model_type', 'model_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'url', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function body()
    {
        return $this->belongsTo(Body::class);
    }

    /**
     * Entries the current user may see: their body subtree's, plus system-level
     * entries written without a body context.
     */
    public function scopeVisible(Builder $query): Builder
    {
        if (! BodyContext::isActive()) {
            return $query;
        }

        $bodyIds = BodyContext::scopeIds();

        return $query->where(function (Builder $q) use ($bodyIds) {
            $q->whereIn('body_id', $bodyIds ?: [0])->orWhereNull('body_id');
        });
    }

    public static function log(string $action, ?string $modelType = null, ?int $modelId = null, ?array $old = null, ?array $new = null, ?int $bodyId = null): self
    {
        return static::create([
            'body_id' => $bodyId ?? self::resolveBodyId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'created_at' => now(),
        ]);
    }

    /**
     * Which body an entry belongs to, when the caller has not said.
     *
     * The rule is: only stamp a body when the actor genuinely belongs to one.
     *
     * This previously read BodyContext::current(), which for the platform
     * operator falls through to whichever body sorts FIRST — so the operator's
     * own logins were being filed against an unrelated diocese. That is not a
     * cosmetic mis-label: scopeVisible() uses body_id to decide which entries a
     * body's people may read, so a guess here both misattributes the action and
     * shows that body's auditor a trail entry that was never theirs.
     *
     * An entry with a NULL body is a platform-level event and stays visible to
     * everyone, which is the correct reading of "the operator signed in".
     * Callers that know better — the operator acting ON a specific body — pass
     * the id explicitly.
     */
    private static function resolveBodyId(): ?int
    {
        if (! BodyContext::isActive() || BodyContext::isSubtree()) {
            return null;
        }

        // The operator is outside every body. Never guess one for them.
        if (BodyContext::isSuperAdmin()) {
            return null;
        }

        return BodyContext::current() ?: null;
    }

    /**
     * Record that somebody LOOKED at something.
     *
     * Brief §12 asks for this explicitly, and it is not decoration: in a system
     * whose whole purpose is oversight, "who saw the finding and when" is part
     * of the evidence. A finding nobody opened before it was accepted is a
     * different story from one that was read by three people first.
     */
    public static function viewed(string $modelType, ?int $modelId = null, ?array $context = null, ?int $bodyId = null): self
    {
        return static::log('viewed', $modelType, $modelId, null, $context, $bodyId);
    }
}
