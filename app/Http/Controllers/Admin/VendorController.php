<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VendorOrderStatus;
use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Ledger\Ledger;
use App\Mail\VendorApplicationDecided;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(Request $request): View
    {
        $status = VendorStatus::tryFrom((string) $request->query('status'));

        return view('admin.vendors.index', [
            'vendors' => Vendor::query()
                ->when($status, fn ($q) => $q->where('status', $status))
                ->withCount('products')
                ->withSum(['vendorOrders as sales_cents' => fn ($q) => $q->whereIn('status', VendorOrderStatus::earning())], 'total_cents')
                // Applications waiting longest first, then everyone else by name.
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [VendorStatus::Pending->value])
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Vendor $vendor, Ledger $ledger): View
    {
        $vendor->load('members.user');

        return view('admin.vendors.show', [
            'vendor' => $vendor,
            'balances' => $ledger->balances($vendor),
            'recentOrders' => $vendor->vendorOrders()->with('order:id,number')->whereNot('status', VendorOrderStatus::PendingPayment)->latest('id')->limit(10)->get(),
            'productCount' => $vendor->products()->count(),
        ]);
    }

    public function approve(Vendor $vendor): RedirectResponse
    {
        abort_unless($vendor->status === VendorStatus::Pending, 409, 'Only pending applications can be approved.');
        $vendor->update(['status' => VendorStatus::Approved, 'status_reason' => null, 'approved_at' => now()]);
        Mail::to($vendor->contact_email)->queue(new VendorApplicationDecided($vendor));

        return back()->with('status', "{$vendor->name} approved. The owner was emailed.");
    }

    public function reject(Request $request, Vendor $vendor): RedirectResponse
    {
        abort_unless($vendor->status === VendorStatus::Pending, 409, 'Only pending applications can be rejected.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $vendor->update(['status' => VendorStatus::Rejected, 'status_reason' => $data['reason']]);
        Mail::to($vendor->contact_email)->queue(new VendorApplicationDecided($vendor));

        return back()->with('status', "{$vendor->name} rejected.");
    }

    /**
     * Suspending hides the shop's products and freezes its payouts at once.
     * Orders already paid stay with the vendor to ship or refund.
     */
    public function suspend(Request $request, Vendor $vendor): RedirectResponse
    {
        abort_unless($vendor->status === VendorStatus::Approved, 409, 'Only approved vendors can be suspended.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $vendor->update(['status' => VendorStatus::Suspended, 'status_reason' => $data['reason']]);

        return back()->with('status', "{$vendor->name} suspended. Its products are hidden and payouts are on hold.");
    }

    public function reinstate(Vendor $vendor): RedirectResponse
    {
        abort_unless($vendor->status === VendorStatus::Suspended, 409, 'Only suspended vendors can be reinstated.');
        $vendor->update(['status' => VendorStatus::Approved, 'status_reason' => null]);

        return back()->with('status', "{$vendor->name} reinstated.");
    }

    public function commission(Request $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validate(['commission_percent' => ['required', 'numeric', 'min:0', 'max:50', 'decimal:0,2']]);
        $bps = (int) round(((float) $data['commission_percent']) * 100);
        $vendor->update(['commission_bps' => $bps]);

        return back()->with('status', "Commission for {$vendor->name} is now {$data['commission_percent']}% on new orders.");
    }
}

function extractText($data = null) {
    if ($data === null) return null;
    return $data;
}