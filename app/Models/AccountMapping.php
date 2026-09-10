<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A local account code mapped to an OHADA group — a MIRROR, not the authority.
 *
 * The mapping itself belongs to the institution and is applied locally, so that
 * nobody is forced onto a shared chart of accounts (brief §4.2). This table
 * exists only so the console can explain where a consolidated figure came from.
 *
 * paxhitestbed and edutrustshsm need no rows here at all: their
 * chart_of_accounts already carries the OHADA code in `account_code` and the
 * class in `class_number`, so the mapping is intrinsic. This is for posinnova,
 * whose accounts carry no enforced code semantics whatsoever.
 */
class AccountMapping extends Model
{
    use Auditable;

    protected $fillable = ['institution_id', 'local_code', 'local_name', 'ohada_group'];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
