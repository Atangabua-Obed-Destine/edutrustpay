<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Body;
use Illuminate\Support\Facades\DB;

/**
 * Creates and re-parents bodies.
 *
 * Shaped after edutrustshsm's BranchProvisioningService: one public entry point
 * per operation, private steps, no return value where none is needed.
 *
 * Re-parenting is the operation with teeth. A body's materialised path decides
 * who can see its money, so a stale path on a descendant would leave it visible
 * to its former parent and invisible to its new one — a tenancy failure that
 * looks like nothing at all.
 */
class BodyProvisioningService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Body
    {
        return DB::transaction(function () use ($attributes) {
            $body = Body::create($attributes);

            // rebuildPath runs on the created hook; refresh so the caller sees it.
            return $body->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Body $body, array $attributes): Body
    {
        return DB::transaction(function () use ($body, $attributes) {
            $wasParent = $body->parent_body_id;

            $body->update($attributes);

            if ($body->parent_body_id !== $wasParent) {
                // The model's updated hook rebuilds the subtree, but say so in
                // the trail: this changed who can see this body's figures.
                AuditLog::log('body.reparented', Body::class, $body->id, ['parent_body_id' => $wasParent], [
                    'parent_body_id' => $body->parent_body_id,
                    'descendants_repathed' => count($body->refresh()->subtreeIds()) - 1,
                ]);
            }

            return $body->refresh();
        });
    }

    /**
     * Would making $parent the parent of $body create a cycle?
     *
     * A body cannot be its own ancestor. Without this check the path rebuild
     * recurses until memory is exhausted, and the tree is left half-written.
     */
    public function wouldCycle(Body $body, ?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        if ($parentId === $body->id) {
            return true;
        }

        return in_array($parentId, $body->subtreeIds(), true);
    }

    /**
     * Bodies that may be chosen as a parent for this one.
     *
     * Excludes itself and its own descendants, so the UI cannot offer a choice
     * that the validator will only reject afterwards.
     *
     * @return \Illuminate\Support\Collection<int, Body>
     */
    public function availableParents(?Body $body = null)
    {
        $exclude = $body ? $body->subtreeIds() : [];

        return Body::query()
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
            ->orderBy('path')
            ->get();
    }

    /**
     * Whether a body can be deleted outright.
     *
     * Nothing is deleted where money is concerned. A body that has ever received
     * a report keeps its history and is deactivated instead.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function deletability(Body $body): array
    {
        if ($body->children()->exists()) {
            return ['allowed' => false, 'reason' => __('It contains other bodies. Move or remove them first.')];
        }

        if ($body->institutions()->withoutBodyScope()->where('body_id', $body->id)->exists()) {
            return ['allowed' => false, 'reason' => __('It still has institutions. Move or remove them first.')];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
