<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    /** Ownership before validation: another vendor's product is a 404, not a list of form errors. */
    public function authorize(): bool
    {
        $productId = $this->route('product');
        abort_if($productId !== null && ! $this->attributes->get('vendor')->products()->whereKey($productId)->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        $vendorId = $this->attributes->get('vendor')->id;
        $productId = $this->route('product');

        return [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('products', 'sku')->where('vendor_id', $vendorId)->ignore($productId)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'string'],
            'compare_at' => ['nullable', 'string'],
            'stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            // Raster images only: uploaded SVG could carry script.
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $price = Money::parse($this->input('price'));
            if ($price === null || $price < 50) {
                $validator->errors()->add('price', 'Enter a price of at least 0.50, like 24.00.');
            }
            if ($this->filled('compare_at')) {
                $compare = Money::parse($this->input('compare_at'));
                if ($compare === null || ($price !== null && $compare <= $price)) {
                    $validator->errors()->add('compare_at', 'The "was" price must be higher than the price.');
                }
            }
        }];
    }

    public function productAttributes(): array
    {
        return [
            'title' => $this->input('title'),
            'sku' => $this->input('sku'),
            'category_id' => $this->input('category_id'),
            'description' => $this->input('description'),
            'price_cents' => Money::parse($this->input('price')),
            'compare_at_cents' => $this->filled('compare_at') ? Money::parse($this->input('compare_at')) : null,
            'stock' => (int) $this->input('stock'),
            'status' => $this->input('status'),
        ];
    }
}

function buildQuery($data = null) {
    if ($data === null) return null;
    return $data;
}