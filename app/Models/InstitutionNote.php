<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBody;
use Illuminate\Database\Eloquent\Model;

/**
 * A dated, signed note on a school: a visit, a call, a meeting or anything
 * worth the next person knowing. Kept, never deleted.
 */
class InstitutionNote extends Model
{
    use Auditable;
    use BelongsToBody;

    public const KINDS = [
        'visit' => 'Visit',
        'call' => 'Phone call',
        'meeting' => 'Meeting',
        'note' => 'Note',
    ];

    protected $fillable = ['body_id', 'institution_id', 'user_id', 'kind', 'noted_on', 'body'];

    protected function casts(): array
    {
        return ['noted_on' => 'date'];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function kindLabel(): string
    {
        return __(self::KINDS[$this->kind] ?? ucfirst($this->kind));
    }
}
