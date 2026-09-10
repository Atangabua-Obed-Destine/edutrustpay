<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Records create / update / delete of a model into the audit trail.
 *
 * Ported from edutrustshsm, including the two details that make it usable in
 * practice rather than merely present: no-op updates are skipped so the trail
 * is not buried in timestamp-only noise, and secrets are stripped before
 * anything is written.
 *
 * Domain events that are not a plain Eloquent write — a credential rotated, a
 * finding resolved, a consolidated statement exported — should still call
 * AuditLog::log() explicitly. This trait covers the mechanical CRUD underneath.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->recordAudit('created', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());

            // Nothing meaningful changed (e.g. only a timestamp) — don't log noise.
            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($model->getOriginal(), $changes);

            $model->recordAudit('updated', $model->auditableAttributes($original), $changes);
        });

        static::deleted(function ($model) {
            $model->recordAudit('deleted', $model->auditableAttributes($model->getOriginal()), null);
        });
    }

    /**
     * Attributes that never belong in an audit record: secrets, and the
     * bookkeeping columns that change on every write.
     *
     * secret_hash is here for an obvious reason and invite_token for a less
     * obvious one — an invitation link recorded in the trail is a live
     * credential sitting in a table many people can read.
     *
     * @return array<int, string>
     */
    protected function auditExcluded(): array
    {
        return array_merge(
            ['password', 'remember_token', 'invite_token', 'secret_ciphertext', 'updated_at', 'created_at'],
            $this->hidden ?? []
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExcluded()));
    }

    protected function recordAudit(string $action, ?array $old, ?array $new): void
    {
        AuditLog::log($action, static::class, $this->getKey(), $old, $new, $this->auditBodyId());
    }

    /**
     * The body this record actually belongs to.
     *
     * Derived from the subject rather than from whoever happens to be looking,
     * which is the difference between a fact and a guess. The operator belongs
     * to no body, so without this an entry recording the creation of a diocese
     * would carry no body at all — or worse, before this was fixed, an
     * arbitrary one.
     */
    protected function auditBodyId(): ?int
    {
        // A Body audits against itself.
        if ($this instanceof \App\Models\Body) {
            return $this->getKey();
        }

        return isset($this->body_id) ? (int) $this->body_id : null;
    }
}
