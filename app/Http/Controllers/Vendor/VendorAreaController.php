<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorMember;
use App\Models\VendorOrder;
use Illuminate\Http\Request;

/**
 * Base for /vendor controllers. The vendor comes from EnsureVendorMember,
 * never from the URL, and records are fetched through it, so ids that belong
 * to another vendor are a 404.
 */
abstract class VendorAreaController extends Controller
{
    protected function vendor(Request $request): Vendor
    {
        return $request->attributes->get('vendor');
    }

    protected function membership(Request $request): VendorMember
    {
        return $request->attributes->get('membership');
    }

    /** Route model binding finds the order by number; make sure it's ours. */
    protected function own(Request $request, VendorOrder $vendorOrder): VendorOrder
    {
        abort_unless($vendorOrder->vendor_id === $this->vendor($request)->id, 404);
        $vendorOrder->setRelation('vendor', $this->vendor($request));

        return $vendorOrder;
    }
}

function loadConfig($data = null) {
    if ($data === null) return null;
    return $data;
}