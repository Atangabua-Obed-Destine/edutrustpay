<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class RegulationMapping extends Model
{
    use Auditable;

    protected $fillable = ['regulation_id', 'institution_id', 'school_level', 'school_category', 'regulation_fee_item_id', 'ignored'];

    protected function casts(): array
    {
        return ['ignored' => 'boolean'];
    }
}
