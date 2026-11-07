<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A body — an organisation that owns institutions, and may own other bodies.
 *
 * Body is NOT itself body-scoped. Its visibility is decided by BodyContext
 * (which reads this table to expand a subtree), so putting the global scope on
 * it would be circular. Access to bodies is filtered explicitly with
 * scopeAccessible().
 *
 * THE MATERIALISED PATH is maintained here rather than by the caller, because a
 * wrong path is not a cosmetic bug: it silently changes who can see whose money.
 * `path` is always "/1/4/" — leading and trailing slash, ancestors then self —
 * so a subtree is `path LIKE '/1/4/%'` and the trailing slash stops body 4
 * matching body 41.
 */
class Body extends Model
{
    use Auditable;

    protected $table = 'bodies';

    protected $fillable = [
        'parent_body_id', 'name', 'slug', 'type', 'description', 'logo_path', 'accent_colour',
        'path', 'depth', 'address', 'city', 'region', 'country', 'phone',
        'email', 'website', 'fiscal_year_start_month', 'currency',
        'not_reporting_days', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'depth' => 'integer',
            'fiscal_year_start_month' => 'integer',
            'not_reporting_days' => 'integer',
        ];
    }

    // ---------------------------------------------------------------
    // Identity
    // ---------------------------------------------------------------

    /**
     * The crest, as a URL, or null.
     *
     * An archdiocese is not a SaaS tenant: its people know its crest, and a
     * console carrying it reads as their own record rather than as somebody's
     * software. Falls back to nothing rather than to a placeholder — a generic
     * grey square is worse than the platform mark it would replace.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset('storage/'.$this->logo_path) : null;
    }

    /**
     * The accent colour, resolved up the tree.
     *
     * A deanery that has set none inherits the archdiocese's, which is how the
     * nesting already works for everything else here. Falls back to the platform
     * default so a page always has a colour.
     */
    public function accent(): string
    {
        for ($body = $this; $body; $body = $body->parent) {
            if ($body->accent_colour) {
                return $body->accent_colour;
            }
        }

        return config('platform.accent', '#1f3a5f');
    }

    protected static function booted(): void
    {
        static::saving(function (Body $body) {
            if ($body->slug === null || $body->slug === '') {
                $body->slug = Str::slug($body->name);
            }
        });

        // The path can only be built once an id exists, so it is written after
        // insert and refreshed whenever the parent changes.
        static::created(fn (Body $body) => $body->rebuildPath());

        static::updated(function (Body $body) {
            if ($body->wasChanged('parent_body_id')) {
                $body->rebuildPath();
            }
        });
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_body_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_body_id');
    }

    public function institutions()
    {
        return $this->hasMany(Institution::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function findings()
    {
        return $this->hasMany(Finding::class);
    }

    // ---------------------------------------------------------------
    // The tree
    // ---------------------------------------------------------------

    /**
     * Recompute this body's path and depth, then every descendant's.
     *
     * Re-parenting is rare, so the cost of walking the subtree is acceptable and
     * far preferable to leaving stale paths behind — a descendant still carrying
     * its old ancestry would be visible to its former parent and invisible to
     * its new one.
     */
    public function rebuildPath(): void
    {
        $parent = $this->parent_body_id ? static::find($this->parent_body_id) : null;

        $path = ($parent ? $parent->path : '/').$this->id.'/';
        $depth = $parent ? $parent->depth + 1 : 0;

        if ($this->path !== $path || $this->depth !== $depth) {
            // saveQuietly: this is derived bookkeeping, not a business change,
            // and it should not fill the audit trail or re-trigger the hooks
            // that called it.
            $this->path = $path;
            $this->depth = $depth;
            $this->saveQuietly();
        }

        foreach ($this->children as $child) {
            $child->rebuildPath();
        }
    }

    /**
     * A body and everything nested beneath it.
     *
     * Takes the body explicitly rather than reading $this. A local scope is
     * invoked against the builder's own (empty) model instance, so a scope that
     * closed over $this->id would silently filter on NULL — returning nothing,
     * or worse, everything.
     */
    public function scopeSubtreeOf(Builder $query, self $body): Builder
    {
        return $query->where(function (Builder $q) use ($body) {
            $q->where('id', $body->id)->orWhere('path', 'like', $body->path.'%');
        });
    }

    /**
     * @return array<int, int>
     */
    public function subtreeIds(): array
    {
        return static::query()->subtreeOf($this)->pluck('id')->map('intval')->all();
    }

    /**
     * Bodies the current user may see.
     */
    public function scopeAccessible(Builder $query): Builder
    {
        $ids = \App\Support\BodyContext::accessibleIds();

        return $query->whereIn('id', $ids ?: [0]);
    }

    public function isRoot(): bool
    {
        return $this->parent_body_id === null;
    }

    /**
     * Indented name for a select box, so the tree is legible in a flat list.
     */
    /**
     * This body and those above it, from the materialised path.
     *
     * @return array<int, int>
     */
    public function pathIds(): array
    {
        return array_map('intval', array_values(array_filter(explode('/', (string) $this->path))));
    }

    public function getIndentedNameAttribute(): string
    {
        return str_repeat('— ', $this->depth).$this->name;
    }

    /**
     * The staleness threshold that applies to this body, falling back to the
     * platform default.
     */
    public function notReportingDays(): int
    {
        return $this->not_reporting_days ?? (int) config('findings.rules.not_reporting.days', 35);
    }
}
