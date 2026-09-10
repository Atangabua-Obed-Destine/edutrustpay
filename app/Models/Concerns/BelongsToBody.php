<?php

namespace App\Models\Concerns;

use App\Models\Body;
use App\Models\Scopes\BodyScope;
use App\Support\BodyContext;

/**
 * Marks a model as body-owned: it carries a body_id, is auto-filtered by
 * BodyScope, and auto-stamps body_id on create.
 *
 * Models using this must include 'body_id' in $fillable.
 */
trait BelongsToBody
{
    public static function bootBelongsToBody(): void
    {
        static::addGlobalScope(new BodyScope());

        static::creating(function ($model) {
            if (! empty($model->body_id) || ! BodyContext::isActive() || BodyContext::isSubtree()) {
                return;
            }

            // current() returns 0 for "no body context". Stamping 0 would
            // violate the foreign key, so leave it null and let the caller pass
            // one explicitly where it matters.
            if ($bodyId = BodyContext::current()) {
                $model->body_id = $bodyId;
            }
        });
    }

    public function body()
    {
        return $this->belongsTo(Body::class);
    }

    public function getBodyColumn(): string
    {
        return 'body_id';
    }

    public function getQualifiedBodyColumn(): string
    {
        return $this->qualifyColumn($this->getBodyColumn());
    }

    /**
     * The column identifying the institution a row belongs to, for narrowing an
     * institution liaison to their single institution.
     *
     * Institution itself overrides this to 'id'. A model with no such column
     * returns null and is simply not narrowed.
     */
    public function getInstitutionColumn(): ?string
    {
        return 'institution_id';
    }
}
