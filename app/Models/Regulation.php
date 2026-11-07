<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBody;
use Illuminate\Database\Eloquent\Model;

/**
 * A rule a body has set for its schools.
 *
 * It belongs to the body that made it and applies to institutions in that body
 * and in the bodies nested inside it, so a diocese's fee schedule also binds the
 * schools in its deaneries.
 */
class Regulation extends Model
{
    use Auditable;
    use BelongsToBody;

    public const FEE_SCHEDULE = 'fee_schedule';

    public const ATTESTATION = 'attestation';

    public const AREAS = [
        'fees' => 'Fees',
        'calendar' => 'School calendar',
        'budget' => 'Budgets',
        'staff' => 'Staff postings',
        'other' => 'Other',
    ];

    public const INSTITUTION_TYPES = [
        'school_basic' => 'Primary and secondary schools',
        'school_higher' => 'Higher institutes',
        'business' => 'Businesses',
    ];

    protected $fillable = [
        'body_id', 'area', 'check_type', 'title', 'description', 'applies_to', 'fee_mode',
        'effective_from', 'effective_to', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function feeItems()
    {
        return $this->hasMany(RegulationFeeItem::class)->orderBy('level')->orderBy('category');
    }

    /** A regulation concerns many institutions, so it is not narrowed to one. */
    public function getInstitutionColumn(): ?string
    {
        return null;
    }

    public function isInForce(): bool
    {
        return $this->is_active
            && $this->effective_from->lte(today())
            && (! $this->effective_to || $this->effective_to->gte(today()));
    }

    /**
     * Does this rule bind this institution?
     */
    public function coversInstitution(Institution $institution): bool
    {
        return ($this->applies_to === null || $this->applies_to === $institution->type)
            && in_array($this->body_id, $institution->body?->pathIds() ?? [], true);
    }
}
