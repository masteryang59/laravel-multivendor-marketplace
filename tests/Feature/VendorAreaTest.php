<?php

namespace Tests\Feature;

use App\Dashboard\VendorStats;
use App\Enums\ProductStatus;
use App\Enums\VendorOrderStatus;
use App\Enums\VendorRole;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Orders\OrderWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendorAreaTest extends TestCase
{
    public function test_one_vendor_cannot_reach_another_vendors_products_or_orders(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();
        $theirProduct = $this->product($theirs, ['title' => 'Their Secret Product']);
        $theirOrder = $this->paidOrder($this->customer(), [$theirProduct->id => 1])->vendorOrders->first();
        $this->actingAs($this->owner($mine));

        $this->get(route('vendor.products.edit', $theirProduct->id))->assertNotFound();
        $this->put(route('vendor.products.update', $theirProduct->id), ['title' => 'Hacked'])->assertNotFound();
        $this->get(route('vendor.orders.show', $theirOrder))->assertNotFound();
        $this->post(route('vendor.orders.ship', $theirOrder), ['carrier' => 'X', 'tracking_number' => 'Y'])->assertNotFound();
        $this->post(route('vendor.orders.refund', $theirOrder), ['reason' => 'Because I can'])->assertNotFound();
        $this->get(route('vendor.products.index'))->assertOk()->assertDontSee('Their Secret Product');
        $this->get(route('vendor.orders.index'))->assertOk()->assertDontSee($theirOrder->number);

        $this->assertSame(VendorOrderStatus::Paid, $theirOrder->fresh()->status);
        $this->assertSame('Their Secret Product', $theirProduct->fresh()->title);
    }

    public function test_a_vendor_sees_only_its_own_part_of_a_shared_order(): void
    {
        $mine = $this->vendor();
        $theirs = $this->vendor();
        $order = $this->paidOrder($this->customer(), [
            $this->product($mine, ['title' => 'My Mug'])->id => 1,
            $this->product($theirs, ['title' => 'Their Coffee'])->id => 1,
        ]);
        $myPart = $order->vendorOrders->firstWhere('vendor_id', $mine->id);

        $this->actingAs($this->owner($mine))->get(route('vendor.orders.show', $myPart))
            ->assertOk()->assertSee('My Mug')->assertDontSee('Their Coffee')
            ->assertSee($order->ship_line1); // the address is needed to ship
    }

    public function test_unpaid_reservations_are_not_shown_to_vendors(): void
    {
        $vendor = $this->vendor();
        $order = $this->placeOrder($this->customer(), [$this->product($vendor)->id => 1]);

        $this->actingAs($this->owner($vendor));
        $this->get(route('vendor.orders.index'))->assertDontSee($order->number);
        $this->get(route('vendor.orders.show', $order->vendorOrders->first()))->assertNotFound();
    }

    public function test_area_access_depends_on_membership_and_shop_status(): void
    {
        $this->get(route('vendor.dashboard'))->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get(route('vendor.dashboard'))->assertRedirect(route('sell.create'));

        $pending = Vendor::factory()->pending()->withOwner($owner = User::factory()->create())->create();
        $this->actingAs($owner)->get(route('vendor.dashboard'))->assertRedirect(route('sell.status'));
        $this->get(route('sell.status'))->assertOk()->assertSee('Thanks for applying');

        $pending->update(['status' => 'approved']);
        $this->get(route('vendor.dashboard'))->assertOk()->assertSee($pending->name);
    }

    public function test_staff_manage_products_and_orders_but_not_settings_or_team(): void
    {
        $vendor = $this->vendor();
        $staff = User::factory()->create();
        $vendor->members()->create(['user_id' => $staff->id, 'role' => VendorRole::Staff]);
        $this->actingAs($staff);

        $this->get(route('vendor.products.index'))->assertOk();
        $this->get(route('vendor.orders.index'))->assertOk();
        $this->get(route('vendor.earnings'))->assertOk();
        $this->get(route('vendor.settings'))->assertForbidden();
        $this->put(route('vendor.settings'), ['description' => str_repeat('x', 30)])->assertForbidden();
        $this->get(route('vendor.team'))->assertForbidden();
        $this->get(route('vendor.dashboard'))->assertOk()->assertDontSee('>Settings<', false);
    }

    public function test_owner_adds_and_removes_staff(): void
    {
        $vendor = $this->vendor();
        $owner = $this->owner($vendor);
        $newcomer = User::factory()->create(['email' => 'theo@example.test']);
        $taken = $this->owner($this->vendor());
        $this->actingAs($owner);

        $this->post(route('vendor.team'), ['email' => 'nobody@example.test'])->assertSessionHasErrors('email');
        $this->post(route('vendor.team'), ['email' => $taken->email])->assertSessionHasErrors('email');
        $this->post(route('vendor.team'), ['email' => 'THEO@example.test'])->assertSessionHas('status');
        $member = $vendor->members()->where('user_id', $newcomer->id)->sole();
        $this->assertSame(VendorRole::Staff, $member->role);

        $ownerMember = $vendor->members()->where('user_id', $owner->id)->sole();
        $this->delete(route('vendor.team.destroy', $ownerMember->id))->assertSessionHas('error');
        $this->delete(route('vendor.team.destroy', $member->id))->assertSessionHas('status');
        $this->assertSame(1, $vendor->members()->count());

        // Members of another shop are out of reach.
        $foreign = $this->vendor()->members()->first();
        $this->delete(route('vendor.team.destroy', $foreign->id))->assertNotFound();
    }

    public function test_product_create_and_update(): void
    {
        Storage::fake('public');
        $vendor = $this->vendor();
        $this->actingAs($this->owner($vendor));

        $this->post(route('vendor.products.store'), [
            'title' => 'Speckled Mug', 'sku' => 'MUG-1', 'price' => '28.50', 'compare_at' => '32',
            'stock' => 12, 'status' => 'active', 'description' => 'Nice',
            'image' => UploadedFile::fake()->image('mug.png', 400, 400),
        ])->assertRedirect(route('vendor.products.index'));

        $product = Product::sole();
        $this->assertSame([$vendor->id, 'speckled-mug', 2850, 3200, 12, ProductStatus::Active],
            [$product->vendor_id, $product->slug, $product->price_cents, $product->compare_at_cents, $product->stock, $product->status]);
        Storage::disk('public')->assertExists($product->image_path);
        $this->get($product->imageUrl())->assertOk()->assertHeader('Content-Type', 'image/png');

        // Same title again: slug gets a suffix; same SKU: rejected.
        $this->post(route('vendor.products.store'), ['title' => 'Speckled Mug', 'sku' => 'MUG-1', 'price' => '10', 'stock' => 1, 'status' => 'draft'])
            ->assertSessionHasErrors('sku');
        $this->post(route('vendor.products.store'), ['title' => 'Speckled Mug', 'sku' => 'MUG-2', 'price' => '10', 'stock' => 1, 'status' => 'draft']);
        $this->assertSame('speckled-mug-2', Product::where('sku', 'MUG-2')->value('slug'));

        // Another vendor may reuse the SKU.
        $this->actingAs($this->owner($this->vendor()))
            ->post(route('vendor.products.store'), ['title' => 'Other', 'sku' => 'MUG-1', 'price' => '5', 'stock' => 1, 'status' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner($vendor))
            ->put(route('vendor.products.update', $product->id), ['title' => 'Speckled Mug', 'sku' => 'MUG-1', 'price' => '30', 'stock' => 0, 'status' => 'archived'])
            ->assertSessionHasNoErrors();
        $this->assertSame([3000, 0, ProductStatus::Archived], [$product->fresh()->price_cents, $product->fresh()->stock, $product->fresh()->status]);
    }

    public function test_product_validation(): void
    {
        $this->actingAs($this->owner($this->vendor()));

        $this->post(route('vendor.products.store'), [
            'title' => 'X', 'sku' => 'bad sku!', 'price' => '12.345', 'compare_at' => '5', 'stock' => -1, 'status' => 'live',
            'image' => UploadedFile::fake()->createWithContent('evil.svg', '<svg onload="alert(1)"/>'),
        ])->assertSessionHasErrors(['title', 'sku', 'price', 'stock', 'status', 'image']);

        $this->post(route('vendor.products.store'), ['title' => 'Mug', 'sku' => 'M1', 'price' => '20', 'compare_at' => '15', 'stock' => 1, 'status' => 'draft'])
            ->assertSessionHasErrors('compare_at');
        $this->assertSame(0, Product::count());
    }

    public function test_settings_update_shipping_but_not_platform_terms(): void
    {
        $vendor = $this->vendor(['commission_bps' => 1000]);
        $this->actingAs($this->owner($vendor))->put(route('vendor.settings'), [
            'description' => 'We make lovely things by hand in small batches.',
            'contact_email' => 'orders@example.test',
            'shipping_flat' => '6.50', 'free_shipping_over' => '75', 'low_stock_threshold' => 3,
            'commission_bps' => 0, 'status' => 'approved',
        ])->assertSessionHas('status');

        $vendor->refresh();
        $this->assertSame([650, 7500, 3, 1000], [$vendor->shipping_flat_cents, $vendor->free_shipping_over_cents, $vendor->low_stock_threshold, $vendor->commission_bps]);
    }

    public function test_dashboard_numbers_match_the_orders(): void
    {
        $vendor = $this->vendor(['commission_bps' => 1000, 'shipping_flat_cents' => 500, 'low_stock_threshold' => 3]);
        $mug = $this->product($vendor, ['title' => 'Mug', 'price_cents' => 2000, 'stock' => 10]);
        $this->product($vendor, ['title' => 'Nearly Gone', 'stock' => 2]);
        $this->paidOrder($this->customer(), [$mug->id => 2]);   // 45.00
        $this->paidOrder($this->customer(), [$mug->id => 1]);   // 25.00
        $refunded = $this->paidOrder($this->customer(), [$mug->id => 1])->vendorOrders->first();
        app(OrderWorkflow::class)->refund($refunded->load('order'), 'Oops');
        $this->placeOrder($this->customer(), [$mug->id => 1]); // unpaid: not a sale

        $stats = app(VendorStats::class)->for($vendor);
        $this->assertSame(7000, $stats['revenue_cents']);
        $this->assertSame(7000 - 600, $stats['earnings_cents']); // 10% of items only: 4.00 + 2.00
        $this->assertSame(2, $stats['orders']);
        $this->assertSame(2, $stats['to_ship']);
        $this->assertSame(7000, end($stats['daily'])['cents']);
        $this->assertSame(30, count($stats['daily']));
        $this->assertSame(['Mug' => 3], $stats['top_products']->pluck('units', 'title')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['Nearly Gone'], $stats['low_stock']->pluck('title')->all());
        $this->assertSame(6400, $stats['balances']->pendingCents);

        $this->actingAs($this->owner($vendor))->get(route('vendor.dashboard'))->assertOk()->assertSee('$70.00');
    }
}
