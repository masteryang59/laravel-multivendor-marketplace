<?php

namespace Tests\Feature;

use App\Dashboard\PlatformStats;
use App\Enums\VendorOrderStatus;
use App\Enums\VendorRole;
use App\Enums\VendorStatus;
use App\Mail\VendorApplicationDecided;
use App\Models\Vendor;
use App\Orders\OrderWorkflow;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnboardingAndAdminTest extends TestCase
{
    public function test_register_login_logout(): void
    {
        $this->post('/register', ['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-pass'])->assertRedirect(route('home'));
    }

    public function test_login_lands_each_role_on_its_home_and_is_throttled(): void
    {
        $admin = $this->admin();
        $owner = $this->owner($this->vendor());
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->post('/logout');
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect(route('vendor.dashboard'));
        $this->post('/logout');

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => $owner->email, 'password' => 'nope']);
        }
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertStatus(429);
    }

    public function test_a_customer_applies_and_an_admin_approves(): void
    {
        $applicant = $this->customer();
        $this->actingAs($applicant)->post(route('sell.store'), [
            'name' => 'Bramble & Wick', 'contact_email' => 'hello@bramble.test',
            'description' => 'Hand-poured soy candles in reusable jars.', 'shipping_flat' => '5.95',
        ])->assertRedirect(route('sell.status'));

        $vendor = Vendor::sole();
        $this->assertSame(['bramble-wick', VendorStatus::Pending, 595, 1000], [$vendor->slug, $vendor->status, $vendor->shipping_flat_cents, $vendor->commission_bps]);
        $this->assertSame(VendorRole::Owner, $applicant->membership->role);

        // One shop per account.
        $this->post(route('sell.store'), ['name' => 'Second Shop'])->assertRedirect(route('sell.status'));
        $this->assertSame(1, Vendor::count());

        // Pending shops aren't public, and customers can't approve themselves.
        $this->get(route('shops.show', $vendor))->assertNotFound();
        $this->post(route('admin.vendors.approve', $vendor))->assertForbidden();

        $this->actingAs($this->admin())->post(route('admin.vendors.approve', $vendor))->assertSessionHas('status');
        $this->assertSame(VendorStatus::Approved, $vendor->fresh()->status);
        Mail::assertQueued(VendorApplicationDecided::class, fn ($m) => $m->hasTo('hello@bramble.test'));
        $this->get(route('shops.show', $vendor))->assertOk();
    }

    public function test_applications_need_a_real_name_and_description(): void
    {
        $this->vendor(['name' => 'Taken Name']);
        $this->actingAs($this->customer())->post(route('sell.store'), [
            'name' => 'Taken Name', 'contact_email' => 'x', 'description' => 'short', 'shipping_flat' => 'free',
        ])->assertSessionHasErrors(['name', 'contact_email', 'description', 'shipping_flat']);
    }

    public function test_admin_rejects_with_a_reason(): void
    {
        $vendor = Vendor::factory()->pending()->withOwner()->create();
        $this->actingAs($this->admin());
        $this->post(route('admin.vendors.reject', $vendor), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.vendors.reject', $vendor), ['reason' => 'We do not list candles yet'])->assertSessionHas('status');
        $this->assertSame([VendorStatus::Rejected, 'We do not list candles yet'], [$vendor->fresh()->status, $vendor->fresh()->status_reason]);
        $this->post(route('admin.vendors.approve', $vendor))->assertStatus(409);
    }

    public function test_suspension_hides_the_shop_and_stops_sales_but_keeps_paid_orders(): void
    {
        $vendor = $this->vendor();
        $product = $this->product($vendor, ['title' => 'Vinyl Record']);
        $customer = $this->customer();
        $paid = $this->paidOrder($customer, [$product->id => 1])->vendorOrders->first();
        $this->actingAs($customer)->post('/cart/items', ['product_id' => $product->id]);

        $this->actingAs($this->admin())->post(route('admin.vendors.suspend', $vendor), ['reason' => 'Late shipments'])->assertSessionHas('status');

        $this->get(route('products.index'))->assertDontSee('Vinyl Record');
        $this->get($product->url())->assertNotFound();
        $this->actingAs($customer)->get('/cart')->assertSee('no longer available');
        $this->get('/checkout')->assertRedirect(route('cart.show'));

        // The vendor can still see the order, but its area redirects to the status page.
        $this->actingAs($this->owner($vendor))->get(route('vendor.orders.index'))->assertRedirect(route('sell.status'));
        $this->assertSame(VendorOrderStatus::Paid, $paid->fresh()->status);

        $this->actingAs($this->admin())->post(route('admin.vendors.reinstate', $vendor));
        $this->get(route('products.index'))->assertSee('Vinyl Record');
    }

    public function test_admin_pages_are_admin_only_and_render(): void
    {
        $vendor = $this->vendor();
        $this->paidOrder($this->customer(), [$this->product($vendor)->id => 1]);
        Vendor::factory()->pending()->withOwner()->create(['name' => 'Waiting Shop']);

        $this->actingAs($this->owner($vendor));
        foreach (['admin.dashboard', 'admin.vendors.index', 'admin.orders.index', 'admin.payouts.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }

        $this->actingAs($this->admin());
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Waiting Shop');
        $this->get(route('admin.vendors.index', ['status' => 'pending']))->assertOk()->assertSee('Waiting Shop')->assertDontSee($vendor->name);
        $this->get(route('admin.vendors.show', $vendor))->assertOk();
        $this->get(route('admin.orders.index'))->assertOk();
        $this->post(route('admin.payouts.run'))->assertSessionHas('status', 'No vendor had a payable balance.');
        $this->get(route('admin.payouts.index'))->assertOk();
    }

    public function test_platform_stats_add_up(): void
    {
        $a = $this->vendor(['commission_bps' => 1000, 'shipping_flat_cents' => 0]);
        $b = $this->vendor(['commission_bps' => 500, 'shipping_flat_cents' => 0]);
        $this->paidOrder($this->customer(), [$this->product($a, ['price_cents' => 10000])->id => 1, $this->product($b, ['price_cents' => 4000])->id => 1]);
        $refund = $this->paidOrder($this->customer(), [$this->product($a, ['price_cents' => 2000])->id => 1])->vendorOrders->first();
        app(OrderWorkflow::class)->refund($refund->load('order'), 'Damaged');

        $stats = app(PlatformStats::class)->get();
        $this->assertSame(14000, $stats['gmv_cents']);
        $this->assertSame(1000 + 200, $stats['commission_cents']);
        $this->assertSame(2000, $stats['refunded_cents']);
        $this->assertSame(2, $stats['orders']);
        $this->assertSame($stats['commission_cents'], (int) $stats['top_vendors']->sum('commission'));
    }
}
