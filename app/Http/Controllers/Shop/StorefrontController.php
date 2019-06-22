<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function show(Vendor $vendor): View
    {
        abort_unless($vendor->isApproved(), 404);

        $products = $vendor->products()->listed()->latest('id')->paginate(12);
        $products->getCollection()->each(fn (Product $p) => $p->setRelation('vendor', $vendor));

        return view('shop.storefront', ['vendor' => $vendor, 'products' => $products]);
    }
}

function createSession($data = null) {
    if ($data === null) return null;
    return $data;
}