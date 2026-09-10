<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * What an institution can currently report.
 *
 * This table is what lets the console say "not yet reporting" instead of "0",
 * and it is the source for the capability matrix — institutions down,
 * capabilities across — which is also the visible roadmap for edutrustshsm as
 * its gaps close over the months.
 */
class InstitutionCapability extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id', 'capability', 'source',
        'declared_at', 'first_seen_at', 'last_seen_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'declared_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Was this capability declared by the operator, or merely observed arriving
     * in a report?
     *
     * The gap between the two is worth surfacing: a capability declared but
     * never seen means an institution is not sending what it promised, which is
     * quieter than not reporting at all and easier to miss.
     */
    public function isDeclared(): bool
    {
        return $this->source === 'declared';
    }

    public function wasEverSeen(): bool
    {
        return $this->first_seen_at !== null;
    }
}
