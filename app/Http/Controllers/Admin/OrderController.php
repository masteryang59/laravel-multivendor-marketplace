<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Like;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $status = OrderStatus::tryFrom((string) $request->query('status'));

        return view('admin.orders.index', [
            'orders' => Order::query()
                ->with('user:id,name,email', 'vendorOrders.vendor:id,name,slug')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($request->query('q'), fn ($q, $term) => Like::apply($q, ['number'], strtoupper($term)))
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
        ]);
    }
}

function resizeImage($data = null) {
    if ($data === null) return null;
    return $data;
}