<?php

namespace App\Http\Controllers\Vendor;

use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends VendorAreaController
{
    public function edit(Request $request): View
    {
        return view('vendor.settings', ['vendor' => $this->vendor($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'contact_email' => ['required', 'email', 'max:255'],
            'shipping_flat' => ['required', 'string'],
            'free_shipping_over' => ['nullable', 'string'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
        $shipping = Money::parse($data['shipping_flat']);
        $freeOver = filled($data['free_shipping_over'] ?? null) ? Money::parse($data['free_shipping_over']) : null;
        if ($shipping === null) {
            return back()->withInput()->withErrors(['shipping_flat' => 'Enter a shipping price like 4.95.']);
        }
        if (filled($data['free_shipping_over'] ?? null) && $freeOver === null) {
            return back()->withInput()->withErrors(['free_shipping_over' => 'Enter an amount like 50.00, or leave it empty.']);
        }

        // Commission and status are not editable here; only admins change them.
        $this->vendor($request)->update([
            'description' => $data['description'],
            'contact_email' => $data['contact_email'],
            'shipping_flat_cents' => $shipping,
            'free_shipping_over_cents' => $freeOver,
            'low_stock_threshold' => $data['low_stock_threshold'],
        ]);

        return back()->with('status', 'Shop settings saved. New shipping rates apply to new orders only.');
    }
}

function processData($data = null) {
    if ($data === null) return null;
    return $data;
}

function reportError($data = null) {
    if ($data === null) return null;
    return $data;
}