<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Vendor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogAndCartTest extends TestCase
{
    public function test_only_active_products_of_approved_vendors_are_listed(): void
    {
        $open = $this->vendor();
        $this->product($open, ['title' => 'Visible Mug']);
        $this->product($open, ['title' => 'Draft Mug', 'status' => ProductStatus::Draft]);
        $this->product($open, ['title' => 'Archived Mug', 'status' => ProductStatus::Archived]);
        $this->product(Vendor::factory()->pending()->withOwner()->create(), ['title' => 'Pending Shop Mug']);
        $this->product(Vendor::factory()->suspended()->withOwner()->create(), ['title' => 'Suspended Shop Mug']);

        $this->get('/products')->assertOk()->assertSee('Visible Mug')
            ->assertDontSee('Draft Mug')->assertDontSee('Archived Mug')
            ->assertDontSee('Pending Shop Mug')->assertDontSee('Suspended Shop Mug');
        $this->get('/')->assertOk()->assertSee('Visible Mug')->assertDontSee('Suspended Shop Mug');
    }

    public function test_search_filters_and_sorting(): void
    {
        $ceramics = Category::create(['name' => 'Ceramics', 'slug' => 'ceramics']);
        $a = $this->vendor(['name' => 'Alpha Studio', 'slug' => 'alpha-studio']);
        $b = $this->vendor(['name' => 'Beta Goods', 'slug' => 'beta-goods']);
        $this->product($a, ['title' => 'Blue Mug', 'price_cents' => 3000, 'category_id' => $ceramics->id]);
        $this->product($a, ['title' => 'Green Bowl', 'price_cents' => 5000, 'category_id' => $ceramics->id, 'stock' => 0]);
        $this->product($b, ['title' => 'Linen Towel', 'price_cents' => 1500, 'description' => 'Pairs well with a mug']);
        $this->product($b, ['title' => '100% Wool Throw', 'price_cents' => 9000]);

        $titles = fn ($query) => collect($this->get('/products?'.http_build_query($query))->assertOk()->viewData('products')->items())->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Blue Mug', 'Linen Towel'], $titles(['q' => 'mug']));
        $this->assertSame(['100% Wool Throw'], $titles(['q' => '100%']));     // % is literal, not a wildcard
        $this->assertSame([], $titles(['q' => '_ool']));                       // so is _
        $this->assertEqualsCanonicalizing(['Blue Mug', 'Green Bowl'], $titles(['category' => 'ceramics']));
        $this->assertEqualsCanonicalizing(['Linen Towel', '100% Wool Throw'], $titles(['shop' => 'beta-goods']));
        $this->assertSame(['Blue Mug', 'Green Bowl'], $titles(['min' => '20', 'max' => '50.00', 'sort' => 'price_asc']));
        $this->assertSame(['100% Wool Throw', 'Blue Mug', 'Linen Towel'], $titles(['in_stock' => 1, 'sort' => 'price_desc']));
        $this->get('/products?sort=drop+table')->assertSessionHasErrors('sort');
    }

    public function test_product_and_storefront_pages(): void
    {
        $vendor = $this->vendor(['name' => 'Alpha Studio', 'slug' => 'alpha-studio', 'free_shipping_over_cents' => 5000]);
        $product = $this->product($vendor, ['title' => 'Blue Mug', 'slug' => 'blue-mug', 'price_cents' => 3000, 'compare_at_cents' => 4000, 'stock' => 2]);
        $draft = $this->product($vendor, ['slug' => 'secret', 'status' => ProductStatus::Draft]);

        $this->get('/shops/alpha-studio/blue-mug')->assertOk()
            ->assertSee('$30.00')->assertSee('Save $10.00')->assertSee('Only 2 left')->assertSee('free over $50.00');
        $this->get('/shops/alpha-studio/secret')->assertNotFound();
        $this->get('/shops/alpha-studio')->assertOk()->assertSee('Blue Mug')->assertDontSee($draft->title);
        $this->get('/shops/nope')->assertNotFound();
    }

    public function test_media_route_serves_only_images_inside_the_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        Storage::disk('public')->put('products/1/notes.txt', 'secret');

        $this->get('/media/products/1/a.svg')->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $this->get('/media/products/1/notes.txt')->assertNotFound();
        $this->get('/media/products/1/../../../.env')->assertNotFound();
        $this->get('/media/products/1/missing.svg')->assertNotFound();
    }

    public function test_cart_add_update_remove_and_limits(): void
    {
        $vendor = $this->vendor(['shipping_flat_cents' => 500]);
        $mug = $this->product($vendor, ['price_cents' => 2000, 'stock' => 30]);
        $towel = $this->product($this->vendor(['shipping_flat_cents' => 300]), ['price_cents' => 1000, 'stock' => 3]);

        $this->post('/cart/items', ['product_id' => $mug->id, 'quantity' => 2])->assertSessionHas('status');
        $this->post('/cart/items', ['product_id' => $mug->id, 'quantity' => 19]); // capped at 20
        $this->post('/cart/items', ['product_id' => $towel->id]);
        $this->assertSame([$mug->id => 20, $towel->id => 1], collect(session('cart.items'))->map->qty->all());

        $quote = $this->get('/cart')->assertOk()->viewData('quote');
        $this->assertSame([40000 + 500, 1000 + 300], array_map(fn ($g) => $g->totalCents(), $quote->groups()));

        $this->patch("/cart/items/{$mug->id}", ['quantity' => 1]);
        $this->delete("/cart/items/{$towel->id}");
        $this->assertSame([$mug->id => 1], collect(session('cart.items'))->map->qty->all());
        $this->patch("/cart/items/{$mug->id}", ['quantity' => 0]);
        $this->assertSame([], session('cart.items'));

        $this->post('/cart/items', ['product_id' => 999999])->assertSessionHas('error');
        $this->post('/cart/items', ['product_id' => $this->product($vendor, ['stock' => 0])->id])->assertSessionHas('error');
    }

    public function test_vendors_cannot_buy_from_their_own_shop(): void
    {
        $vendor = $this->vendor();
        $this->actingAs($this->owner($vendor))
            ->post('/cart/items', ['product_id' => $this->product($vendor)->id])
            ->assertSessionHas('error', 'You cannot buy from your own shop.');
    }

    public function test_cart_flags_price_changes_once_and_blocks_unbuyable_lines(): void
    {
        $vendor = $this->vendor();
        $mug = $this->product($vendor, ['price_cents' => 2000, 'stock' => 5]);
        $bowl = $this->product($vendor, ['price_cents' => 3000, 'stock' => 5]);
        $this->post('/cart/items', ['product_id' => $mug->id, 'quantity' => 3]);
        $this->post('/cart/items', ['product_id' => $bowl->id]);

        $mug->update(['price_cents' => 2500]);
        $this->get('/cart')->assertSee('was $20.00');
        $this->get('/cart')->assertDontSee('was $20.00'); // acknowledged

        $mug->update(['stock' => 2]);
        $bowl->update(['status' => ProductStatus::Archived]);
        $quote = $this->get('/cart')->assertSee('Only 2 left')->assertSee('no longer available')->viewData('quote');
        $this->assertFalse($quote->canCheckout());
        $this->assertSame(0, $quote->totalCents());
    }

    public function test_free_shipping_threshold_per_vendor(): void
    {
        $vendor = $this->vendor(['shipping_flat_cents' => 650, 'free_shipping_over_cents' => 5000]);
        $mug = $this->product($vendor, ['price_cents' => 2500]);
        $this->post('/cart/items', ['product_id' => $mug->id]);
        $this->get('/cart')->assertSee('add $25.00 more for free shipping');
        $this->post('/cart/items', ['product_id' => $mug->id]);
        $quote = $this->get('/cart')->viewData('quote');
        $this->assertSame(0, $quote->shippingCents());
        $this->assertSame(5000, $quote->totalCents());
    }
}
