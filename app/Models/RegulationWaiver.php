<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved exception. It always has a reason and an end date, so a dispensation
 * cannot quietly become permanent.
 */
class RegulationWaiver extends Model
{
    use Auditable;

    protected $fillable = ['regulation_id', 'institution_id', 'reason', 'expires_on', 'granted_by', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'revoked_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->whereDate('expires_on', '>=', today());
    }

    public function grantor()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
