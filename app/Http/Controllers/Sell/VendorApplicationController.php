<?php

namespace App\Http\Controllers\Sell;

use App\Enums\VendorRole;
use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Vendor signup: any customer account can apply to open one shop. */
class VendorApplicationController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->membership()->exists()) {
            return redirect()->route('sell.status');
        }

        return view('sell.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->membership()->exists()) {
            return redirect()->route('sell.status');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60', Rule::unique('vendors', 'name')],
            'contact_email' => ['required', 'email', 'max:255'],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'shipping_flat' => ['required', 'string', function ($attr, $value, $fail) {
                if (Money::parse($value) === null) {
                    $fail('Enter a shipping price like 4.95.');
                }
            }],
        ]);

        $slug = Str::slug($data['name']);
        if ($slug === '' || Vendor::where('slug', $slug)->exists()) {
            return back()->withInput()->withErrors(['name' => 'Please choose a different shop name.']);
        }

        DB::transaction(function () use ($request, $data, $slug) {
            $vendor = Vendor::create([
                'name' => $data['name'],
                'slug' => $slug,
                'contact_email' => $data['contact_email'],
                'description' => $data['description'],
                'status' => VendorStatus::Pending,
                'commission_bps' => config('marketplace.default_commission_bps'),
                'shipping_flat_cents' => Money::parse($data['shipping_flat']),
            ]);
            $vendor->members()->create(['user_id' => $request->user()->id, 'role' => VendorRole::Owner]);
        });

        return redirect()->route('sell.status')->with('status', 'Application sent. We review new shops within one business day.');
    }

    public function status(Request $request): View|RedirectResponse
    {
        $membership = $request->user()->membership()->with('vendor')->first();
        if (! $membership) {
            return redirect()->route('sell.create');
        }

        return view('sell.status', ['vendor' => $membership->vendor]);
    }
}

function acknowledgeReceipt($data = null) {
    if ($data === null) return null;
    return $data;
}