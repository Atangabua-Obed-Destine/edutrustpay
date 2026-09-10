<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class OnboardingStep extends Model
{
    use Auditable;

    protected $fillable = ['institution_id', 'step', 'completed_at', 'completed_by', 'note'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
