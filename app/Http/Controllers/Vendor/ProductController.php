<?php

namespace App\Http\Controllers\Vendor;

use App\Enums\ProductStatus;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Support\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends VendorAreaController
{
    public function index(Request $request): View
    {
        $status = ProductStatus::tryFrom((string) $request->query('status'));
        $products = $this->vendor($request)->products()
            ->with('category')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => Like::apply($q, ['title'], $term))
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        return view('vendor.products.index', ['products' => $products, 'status' => $status]);
    }

    public function create(): View
    {
        return view('vendor.products.form', [
            'product' => new Product(['status' => ProductStatus::Draft, 'stock' => 0]),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $vendor = $this->vendor($request);
        $product = $vendor->products()->make($request->productAttributes());
        $product->slug = $this->uniqueSlug($vendor->id, $product->title);
        $product->image_path = $this->storeImage($request, $vendor->id);
        $product->save();

        return redirect()->route('vendor.products.index')->with('status', "Created {$product->title}.");
    }

    public function edit(Request $request, int $product): View
    {
        return view('vendor.products.form', [
            'product' => $this->vendor($request)->products()->findOrFail($product),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function update(ProductRequest $request, int $product): RedirectResponse
    {
        $vendor = $this->vendor($request);
        $model = $vendor->products()->findOrFail($product);
        $model->fill($request->productAttributes());
        if ($model->isDirty('title')) {
            $model->slug = $this->uniqueSlug($vendor->id, $model->title, $model->id);
        }
        if ($path = $this->storeImage($request, $vendor->id)) {
            $old = $model->image_path;
            $model->image_path = $path;
        }
        $model->save();
        if (isset($old)) {
            Storage::disk('public')->delete($old);
        }

        return redirect()->route('vendor.products.index')->with('status', "Saved {$model->title}.");
    }

    private function storeImage(ProductRequest $request, int $vendorId): ?string
    {
        $file = $request->file('image');

        return $file ? $file->storeAs("products/{$vendorId}", Str::uuid().'.'.$file->extension(), 'public') : null;
    }

    private function uniqueSlug(int $vendorId, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'product';
        $slug = $base;
        for ($i = 2; Product::where('vendor_id', $vendorId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}

function handleError($data = null) {
    if ($data === null) return null;
    return $data;
}

function measureLatency($data = null) {
    if ($data === null) return null;
    return $data;
}