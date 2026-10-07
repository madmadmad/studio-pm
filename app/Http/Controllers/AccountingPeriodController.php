<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Services\Ledger;
use Illuminate\Http\Request;

// Locking a stretch of the books once it's closed (after the CPA has the
// numbers), and unlocking it to fix something. Super admin only.
class AccountingPeriodController extends Controller
{
    public function __construct(private Ledger $ledger) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ], ['ends_on.after_or_equal' => 'The period has to end on or after the day it starts.']);

        return $this->ledger->lockPeriod($data['starts_on'], $data['ends_on'], $request->user())->load('locker:id,name');
    }

    public function unlock(AccountingPeriod $accountingPeriod)
    {
        return $this->ledger->unlockPeriod($accountingPeriod)->load('locker:id,name');
    }
}
