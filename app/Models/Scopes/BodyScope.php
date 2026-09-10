<?php

namespace App\Models\Scopes;

use App\Support\BodyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines every query on a body-owned model to the bodies the current request
 * may see — the active body and everything nested beneath it.
 *
 * Brief §12 is explicit that tenancy is enforced by a global scope, "not by
 * remembering to filter". That is the point: a developer adding a new console
 * page cannot accidentally leak another body's figures by forgetting a where
 * clause, because there is no query that is not already filtered.
 *
 * Bypass deliberately with Model::withoutBodyScope() for genuinely cross-body
 * work — the ingest queue, the staleness sweep, the operator's console.
 */
class BodyScope implements Scope
{
    /**
     * Re-entrancy guard, carried over from edutrustshsm's BranchScope where it
     * was learned the hard way.
     *
     * Resolving the body context queries the `bodies` table to expand a subtree.
     * If Body itself is ever body-owned, or a future auth guard reads a
     * body-owned model, that query re-enters this scope and recurses until
     * memory is exhausted. Skipping the filter while resolving is safe: those
     * lookups are by primary key or by path, and need no tenancy filter of their
     * own.
     */
    protected static bool $resolving = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (static::$resolving) {
            return;
        }

        static::$resolving = true;

        try {
            // Console, queue workers, migrations, seeders: no scoping. The ingest
            // job must be able to write a report for any institution.
            if (! BodyContext::isActive()) {
                return;
            }

            $ids = BodyContext::scopeIds();

            /*
             * An authenticated user with no accessible bodies sees NOTHING, not
             * everything. Returning early here would be the classic tenancy bug:
             * a misconfigured account silently granted the run of the platform.
             */
            $builder->whereIn($model->getQualifiedBodyColumn(), $ids ?: [0]);

            $this->narrowToLiaisonInstitution($builder, $model);
        } finally {
            static::$resolving = false;
        }
    }

    /**
     * An institution liaison sees exactly one institution (brief §10).
     *
     * Body scoping alone is not enough for them: a liaison at one school in a
     * deanery would otherwise see every school in that deanery, which is a
     * quiet over-grant — the role exists precisely to be narrower than the body.
     *
     * Narrowing here rather than in each controller means it cannot be
     * forgotten on a page added later, which is the same reasoning that puts
     * body tenancy in a global scope instead of a where clause.
     */
    private function narrowToLiaisonInstitution(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        $institutionId = $user->institution_id ?? null;

        if (! $institutionId) {
            return;
        }

        $column = method_exists($model, 'getInstitutionColumn')
            ? $model->getInstitutionColumn()
            : null;

        // A body-owned model with no institution of its own is left alone.
        if ($column === null) {
            return;
        }

        $builder->where($model->qualifyColumn($column), $institutionId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutBodyScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });
    }
}
