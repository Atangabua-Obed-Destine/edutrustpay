<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Following up a breach: who is on it, by when, and how it ended.
 */
class ComplianceCase extends Model
{
    use Auditable;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'regulation_id', 'institution_id', 'status', 'assigned_to', 'due_on', 'note', 'outcome',
        'opened_by', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'closed_at' => 'datetime'];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->due_on && $this->due_on->lt(today());
    }

    public function regulation()
    {
        return $this->belongsTo(Regulation::class)->withoutGlobalScopes();
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class)->withoutGlobalScopes();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
