<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A person confirming something the system cannot measure. It expires, because a
 * visit last year says little about this year.
 */
class RegulationAttestation extends Model
{
    use Auditable;

    protected $fillable = ['regulation_id', 'institution_id', 'outcome', 'note', 'valid_until', 'attested_by'];

    protected function casts(): array
    {
        return ['valid_until' => 'date'];
    }

    public function attester()
    {
        return $this->belongsTo(User::class, 'attested_by');
    }
}
