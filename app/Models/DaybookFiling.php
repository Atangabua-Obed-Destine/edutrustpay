<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBody;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of one school's daybook for one month.
 *
 * A correction files a new version and this one stays. Only is_current counts in
 * figures, and the older versions are kept so changes can be seen.
 */
class DaybookFiling extends Model
{
    use BelongsToBody;

    protected $fillable = [
        'body_id', 'institution_id', 'period', 'sequence', 'is_current', 'currency',
        'money_in', 'money_out', 'line_count', 'payload_hash', 'generated_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'money_in' => 'decimal:2',
            'money_out' => 'decimal:2',
            'line_count' => 'integer',
            'sequence' => 'integer',
            'generated_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function lines()
    {
        return $this->hasMany(DaybookLine::class);
    }
}
