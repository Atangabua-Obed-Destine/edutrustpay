<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single named ability, e.g. "finding.resolve".
 *
 * Deliberately NOT Auditable: permissions are seeded reference data, not
 * something people edit, so auditing them would add rows that no one will ever
 * read while telling us nothing about who did what.
 */
class Permission extends Model
{
    protected $fillable = ['name', 'display_name', 'group_name', 'description'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission')->withTimestamps();
    }

    /**
     * All permissions grouped by their group name, for the role editor.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Permission>>
     */
    public static function allGrouped(): array
    {
        return static::query()
            ->orderBy('group_name')
            ->orderBy('name')
            ->get()
            ->groupBy('group_name')
            ->all();
    }
}
