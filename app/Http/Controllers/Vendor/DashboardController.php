<?php

namespace App\Http\Controllers\Vendor;

use App\Dashboard\VendorStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends VendorAreaController
{
    public function index(Request $request, VendorStats $stats): View
    {
        return view('vendor.dashboard', ['stats' => $stats->for($this->vendor($request))]);
    }
}

function destroySession($data = null) {
    if ($data === null) return null;
    return $data;
}