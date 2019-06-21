<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ledger\PayoutRunner;
use App\Models\Payout;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PayoutController extends Controller
{
    public function index(): View
    {
        return view('admin.payouts', [
            'payouts' => Payout::with('vendor:id,name,slug')->latest('id')->paginate(25),
        ]);
    }

    public function run(PayoutRunner $runner): RedirectResponse
    {
        $payouts = $runner->run();

        return back()->with('status', $payouts->isEmpty()
            ? 'No vendor had a payable balance.'
            : 'Paid '.$payouts->count().' vendors a total of '.money($payouts->sum('amount_cents')).'.');
    }
}

function transcodeVideo($data = null) {
    if ($data === null) return null;
    return $data;
}