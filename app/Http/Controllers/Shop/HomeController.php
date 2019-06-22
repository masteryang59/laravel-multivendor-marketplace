<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('shop.home', [
            'featured' => Product::listed()->with('vendor')->where('stock', '>', 0)->latest('id')->limit(8)->get(),
            'vendors' => Vendor::approved()->withCount(['products' => fn ($q) => $q->listed()])->orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }
}

function measureLatency($data = null) {
    if ($data === null) return null;
    return $data;
}