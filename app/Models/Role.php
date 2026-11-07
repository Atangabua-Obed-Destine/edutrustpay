<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\BodyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use Auditable;

    protected $fillable = ['body_id', 'name', 'display_name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withTimestamps();
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user')->withTimestamps();
    }

    public function body()
    {
        return $this->belongsTo(Body::class);
    }

    /**
     * Roles visible here: the platform's, plus those of this body, the bodies
     * inside it and the bodies above it, so a deanery can use the diocese's.
     */
    public function scopeUsable(Builder $query): Builder
    {
        if (BodyContext::isSuperAdmin()) {
            return $query;
        }

        $ids = array_merge(BodyContext::accessibleIds(), BodyContext::currentBody()?->pathIds() ?? []);

        return $query->where(fn (Builder $q) => $q->whereNull('body_id')->orWhereIn('body_id', $ids));
    }

    /**
     * Roles this body may edit or delete: its own and those of bodies inside it.
     */
    public function scopeManageable(Builder $query): Builder
    {
        return $query->where('is_system', false)->whereIn('body_id', BodyContext::accessibleIds());
    }

    /**
     * Would giving this role to somebody exceed what the actor holds?
     */
    public function grantsNoMoreThan(User $actor): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return array_diff($this->permissions->pluck('name')->all(), $actor->permissionNames()) === [];
    }

    /**
     * May a person in this body hold this role?
     */
    public function availableTo(Body $body): bool
    {
        return $this->body_id === null || in_array($this->body_id, $body->pathIds(), true);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions()->where('name', $permission)->exists();
    }
}
