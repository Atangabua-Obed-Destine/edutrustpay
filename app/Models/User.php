<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who uses the console.
 *
 * Two kinds:
 *   - the EdutrustPay operator (`role` = super_admin), who has no body;
 *   - a body's own people — body admin, bursary head, auditor, institution
 *     liaison — who belong to one body and see it and everything beneath it.
 *
 * Note that User is NOT body-scoped by a global scope. Applying BodyScope here
 * would mean the authentication lookup itself runs through the scope before
 * anyone is authenticated, which is both circular and exactly the sort of thing
 * BodyScope's re-entrancy guard exists to survive. User visibility is filtered
 * explicitly with scopeAccessible().
 */
class User extends Authenticatable
{
    use Auditable;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'body_id', 'institution_id', 'name', 'email', 'password', 'role',
        'is_active', 'invite_token', 'invite_sent_at', 'invite_accepted_at', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'invite_token'];

    /**
     * Memoized permission names for this request.
     *
     * @var array<int, string>|null
     */
    private ?array $permissionNames = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'invite_sent_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'guide_hidden_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function body()
    {
        return $this->belongsTo(Body::class);
    }

    /** Set only for an institution liaison, who sees exactly one institution. */
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    // ---------------------------------------------------------------
    // Authorisation
    // ---------------------------------------------------------------

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * Every permission name this user holds, through their roles.
     *
     * Memoized per request: this is consulted by the Gate bridge on every @can
     * in every Blade template, and the sidebar alone asks a dozen times.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return $this->permissionNames ??= $this->roles()
            ->with('permissions:id,name')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Drop the memo — after a role change, so the user does not keep stale
     * permissions for the rest of the request.
     */
    public function forgetPermissions(): void
    {
        $this->permissionNames = null;
    }

    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    /**
     * Users the current viewer may see.
     */
    public function scopeAccessible(Builder $query): Builder
    {
        if (\App\Support\BodyContext::isSuperAdmin()) {
            return $query;
        }

        $ids = \App\Support\BodyContext::scopeIds();

        return $query->whereIn('body_id', $ids ?: [0]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
