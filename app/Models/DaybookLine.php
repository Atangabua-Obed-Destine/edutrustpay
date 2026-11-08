<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBody;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of money in a school's daybook. Can name a person.
 */
class DaybookLine extends Model
{
    use BelongsToBody;

    public $timestamps = false;

    public const KINDS = [
        'fee' => 'Fees',
        'income' => 'Other income',
        'expense' => 'Expenses',
        'payroll' => 'Staff pay',
        'refund' => 'Refunds',
        'remittance' => 'Taxes paid over',
        'other' => 'Other',
    ];

    public const METHODS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'cheque' => 'Cheque',
        'card' => 'Card',
        'mobile_money' => 'Mobile money',
        'online' => 'Online',
        'other' => 'Other',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_date' => 'date', 'amount' => 'decimal:2'];
    }
}
