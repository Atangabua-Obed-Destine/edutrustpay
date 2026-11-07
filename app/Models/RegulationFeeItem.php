<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulationFeeItem extends Model
{
    protected $fillable = ['regulation_id', 'level', 'category', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function label(): string
    {
        return $this->level.' — '.$this->category;
    }
}
