<?php

namespace Database\Seeders;

use App\Cart\CartQuote;
use App\Checkout\PlaceOrder;
use App\Checkout\StartPayment;
use App\Enums\ProductStatus;
use App\Enums\VendorOrderStatus;
use App\Enums\VendorRole;
use App\Enums\VendorStatus;
use App\Ledger\PayoutRunner;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Orders\OrderWorkflow;
use App\Orders\PaymentProcessor;
use App\Payments\FakePay\FakePay;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplPriorityQueue;

/**
 * Demo marketplace with 45 days of history. The history is not inserted
 * as rows: a small event simulation travels through time and drives the
 * real code paths (checkout, signed FakePay webhooks, shipping, delivery,
 * refunds, daily payouts), so every dashboard number is something the app
 * itself computed. The simulation is seeded, so every run has the same
 * shape (order numbers and ids still come from a secure RNG).
 */
class DemoSeeder extends Seeder
{
    private const DAYS = 45;

    private const SHOPS = [
        'northwind' => [
            'name' => 'Northwind Ceramics', 'email' => 'owner@northwind.test', 'owner' => 'Ingrid Hale',
            'description' => 'Small-batch stoneware thrown and glazed by hand in a two-person studio on the Oregon coast. Dishwasher safe, made to be used every day.',
            'commission' => 1000, 'shipping' => 650, 'free_over' => 7500, 'category' => 'ceramics',
            'products' => [
                ['Speckled Stoneware Mug', 2800, 'mug'], ['Tidepool Ramen Bowl', 4200, 'bowl'],
                ['Ash Glaze Espresso Cups, Set of 2', 3400, 'mug'], ['Nesting Prep Bowls, Set of 3', 5600, 'bowl'],
                ['Ocean Mist Pour-Over Mug', 3100, 'mug'], ['Large Serving Bowl', 7800, 'bowl'],
                ['Celadon Tea Bowl', 2600, 'bowl'], ['Tall Latte Mug', 3000, 'mug'],
            ],
        ],
        'loom' => [
            'name' => 'Loom & Thread', 'email' => 'owner@loom.test', 'owner' => 'Priya Nair',
            'description' => 'Linen and wool woven on vintage looms. Natural dyes, plastic-free packaging, and every piece is pre-washed so it does not shrink.',
            'commission' => 1200, 'shipping' => 500, 'free_over' => 10000, 'category' => 'textiles',
            'products' => [
                ['Waffle Weave Linen Towel', 1800, 'textile'], ['Striped Wool Throw', 12900, 'textile'],
                ['Indigo Table Runner', 4800, 'textile'], ['Organic Cotton Napkins, Set of 4', 3200, 'textile'],
                ['Herringbone Cushion Cover', 4400, 'textile'], ['Hand-loomed Market Tote', 3600, 'textile'],
            ],
        ],
        'copper' => [
            'name' => 'Copper Kettle Coffee', 'email' => 'owner@copperkettle.test', 'owner' => 'Marco Silva',
            'description' => 'Specialty coffee roasted to order every Monday and Thursday. Direct-trade lots from farms we visit, shipped within a day of roasting.',
            'commission' => 800, 'shipping' => 450, 'free_over' => 4000, 'category' => 'coffee',
            'products' => [
                ['Ethiopia Yirgacheffe, 340 g', 1900, 'coffee'], ['Colombia Huila, 340 g', 1750, 'coffee'],
                ['House Espresso Blend, 1 kg', 3800, 'coffee'], ['Swiss Water Decaf, 340 g', 1800, 'coffee'],
                ['Kenya AA, 250 g', 2100, 'coffee'], ['Cold Brew Coarse Grind, 500 g', 1600, 'coffee'],
            ],
        ],
        'paperfox' => [
            'name' => 'Paper Fox Studio', 'email' => 'owner@paperfox.test', 'owner' => 'Sam Okafor',
            'description' => 'Risograph prints and paper goods designed and printed in Leeds. Acid-free paper, soy inks, shipped flat in recycled card.',
            'commission' => 1000, 'shipping' => 395, 'free_over' => null, 'category' => 'paper',
            'products' => [
                ['Mountain Sunrise Print, A3', 2400, 'print'], ['Bauhaus Shapes Print, A2', 3200, 'print'],
                ['Dot Grid Notebook, A5', 1400, 'notebook'], ['Linen Hardcover Journal', 2600, 'notebook'],
                ['Weekly Planner 2027', 2200, 'notebook'], ['Botanical Print Set of 3', 4500, 'print'],
            ],
        ],
    ];

    private const CATEGORIES = [
        'ceramics' => 'Ceramics', 'textiles' => 'Home textiles', 'coffee' => 'Coffee', 'paper' => 'Prints & stationery',
    ];

    private const CUSTOMERS = [
        'Noor Haddad', 'Ben Whitfield', 'Lucia Moreno', 'Kenji Watanabe', 'Grace Okoro', 'Tomas Novak',
        'Aisha Rahman', 'Oliver Brandt', 'Mei Lin', 'Daniel Quinn', 'Sofia Costa', 'Arjun Mehta',
        'Hannah Byrne', 'Samuel Adeyemi',
    ];

    private string $password;

    /** @var list<User> */
    private array $customers = [];

    /** @var array<int, Product> */
    private array $products = [];

    private SplPriorityQueue $events;

    private int $sequence = PHP_INT_MAX;

    public function run(): void
    {
        mt_srand(29);
        // Seeding sends no email and queues no webhooks: payments are fed
        // straight to the webhook handler below.
        Mail::fake();
        Bus::fake();

        $realNow = CarbonImmutable::now();
        $start = $realNow->startOfDay()->subDays(self::DAYS);
        Carbon::setTestNow($start->subDays(5)->setTime(9, 0));
        $this->password = Hash::make('password');

        $this->createPeople();
        $this->createShops($start->subDays(5));
        $this->simulate($start, $realNow);

        Carbon::setTestNow();
        $this->finishingTouches();
    }

    private function createPeople(): void
    {
        $this->user('Bazaar Admin', 'admin@bazaar.test')->forceFill(['is_admin' => true])->save();
        $this->customers[] = $this->user('Jamie Rivera', 'shopper@bazaar.test');
        // No Faker: it's a dev dependency, and the Docker image seeds without it.
        foreach (self::CUSTOMERS as $i => $name) {
            $this->customers[] = $this->user($name, 'customer'.($i + 1).'@example.test');
        }
    }

    private function createShops(CarbonImmutable $approvedAt): void
    {
        foreach (self::CATEGORIES as $slug => $name) {
            Category::create(['name' => $name, 'slug' => $slug]);
        }
        Storage::disk('public')->deleteDirectory('products');

        foreach (self::SHOPS as $key => $shop) {
            $vendor = Vendor::create([
                'name' => $shop['name'], 'slug' => Str::slug($shop['name']), 'description' => $shop['description'],
                'contact_email' => $shop['email'], 'status' => VendorStatus::Approved,
                'commission_bps' => $shop['commission'], 'shipping_flat_cents' => $shop['shipping'],
                'free_shipping_over_cents' => $shop['free_over'], 'approved_at' => $approvedAt,
            ]);
            $vendor->members()->create(['user_id' => $this->user($shop['owner'], $shop['email'])->id, 'role' => VendorRole::Owner]);
            $category = Category::where('slug', $shop['category'])->first();

            foreach ($shop['products'] as $i => [$title, $price, $kind]) {
                $sku = strtoupper(substr($key, 0, 3)).'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                $slug = Str::slug($title);
                $path = "products/{$vendor->id}/{$slug}.svg";
                Storage::disk('public')->put($path, ProductArt::svg($kind, $sku));
                $product = $vendor->products()->create([
                    'category_id' => $category->id, 'title' => $title, 'slug' => $slug, 'sku' => $sku,
                    'description' => $this->describe($title, $shop['name'], $kind),
                    'price_cents' => $price, 'compare_at_cents' => $i === 2 ? (int) round($price * 1.25, -2) : null,
                    'stock' => mt_rand(30, 70), 'status' => ProductStatus::Active, 'image_path' => $path,
                ]);
                $this->products[$product->id] = $product;
            }
        }

        // Staff account at Northwind, a waiting application, a suspended shop.
        $northwind = Vendor::where('slug', 'northwind-ceramics')->first();
        $northwind->members()->create(['user_id' => $this->user('Theo Hale', 'staff@northwind.test')->id, 'role' => VendorRole::Staff]);

        $pending = Vendor::create([
            'name' => 'Bramble & Wick', 'slug' => 'bramble-wick', 'contact_email' => 'hello@bramble.test',
            'description' => 'Hand-poured soy candles in reusable stoneware jars, scented with essential oils only.',
            'status' => VendorStatus::Pending, 'commission_bps' => config('marketplace.default_commission_bps'), 'shipping_flat_cents' => 595,
        ]);
        $pending->members()->create(['user_id' => $this->user('Rosa Lind', 'applicant@bramble.test')->id, 'role' => VendorRole::Owner]);

        $suspended = Vendor::create([
            'name' => 'Midnight Vinyl', 'slug' => 'midnight-vinyl', 'contact_email' => 'shop@midnightvinyl.test',
            'description' => 'Second-hand records, graded and cleaned.', 'status' => VendorStatus::Suspended,
            'status_reason' => 'Repeated late shipments; paused pending review', 'commission_bps' => 1000,
            'shipping_flat_cents' => 700, 'approved_at' => $approvedAt,
        ]);
        $suspended->members()->create(['user_id' => $this->user('Dee Marsh', 'owner@midnightvinyl.test')->id, 'role' => VendorRole::Owner]);
        $suspended->products()->create([
            'title' => 'Blue Note Reissue LP', 'slug' => 'blue-note-reissue-lp', 'sku' => 'MID-001',
            'price_cents' => 3500, 'stock' => 4, 'status' => ProductStatus::Active,
        ]);
    }

    /** Event simulation: orders arrive through the day; fulfilment and payouts follow. */
    private function simulate(CarbonImmutable $start, CarbonImmutable $now): void
    {
        $this->events = new SplPriorityQueue;
        for ($day = $start; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $this->at($day->setTime(6, 0), fn () => app(PayoutRunner::class)->run());
            // Busier towards the present and on weekends.
            $orders = mt_rand(1, 3) + ($day->isWeekend() ? 2 : 0) + intdiv($start->diffInDays($day), 15);
            for ($i = 0; $i < $orders; $i++) {
                $this->at($day->setTime(mt_rand(7, 22), mt_rand(0, 59)), fn () => $this->customerOrders());
            }
        }

        while (! $this->events->isEmpty()) {
            ['at' => $at, 'do' => $do] = $this->events->extract();
            if ($at->greaterThan($now)) {
                continue; // in the future: stays as it is (to ship, in transit, ...)
            }
            Carbon::setTestNow($at);
            $do();
        }
    }

    private function customerOrders(): void
    {
        $customer = mt_rand(1, 6) === 1 ? $this->customers[0] : $this->customers[array_rand($this->customers)];
        // Most carts hold one shop; some span two or three.
        $shopCount = mt_rand(1, 10) <= 6 ? 1 : (mt_rand(1, 3) === 1 ? 3 : 2);
        $byVendor = collect($this->products)->groupBy('vendor_id')->map->all()->all();
        $items = [];
        foreach ($this->pick(array_keys($byVendor), $shopCount) as $vendorId) {
            foreach ($this->pick($byVendor[$vendorId], mt_rand(1, 2)) as $product) {
                $items[$product->id] = ['qty' => mt_rand(1, 6) === 1 ? 2 : 1, 'price' => $product->price_cents];
            }
        }

        $quote = CartQuote::build($items);
        if (! $quote->canCheckout()) {
            return;
        }
        $order = app(PlaceOrder::class)($customer, $items, $this->address($customer), Str::random(40), $quote->hash());
        app(StartPayment::class)($order);

        if (mt_rand(1, 14) === 1) {
            return; // abandoned: the reservation expires (see the expiry job)
        }
        $this->pay($order->refresh());

        foreach ($order->vendorOrders()->get() as $vo) {
            $roll = mt_rand(1, 100);
            if ($roll <= 4) {
                $this->after($vo, mt_rand(2, 20), fn (VendorOrder $v) => app(OrderWorkflow::class)->refund($v, 'Sorry, this piece was damaged in our studio'));

                continue;
            }
            $shipAfter = mt_rand(4, 40);
            $this->after($vo, $shipAfter, function (VendorOrder $v) {
                app(OrderWorkflow::class)->ship($v, ['USPS', 'UPS', 'FedEx'][mt_rand(0, 2)], strtoupper(Str::random(4)).mt_rand(10000000, 99999999));
            });
            $this->after($vo, $shipAfter + mt_rand(48, 110), fn (VendorOrder $v) => app(OrderWorkflow::class)->markDelivered($v));
        }
    }

    /** Pay at FakePay and deliver the signed webhook to the real handler. */
    private function pay(Order $order): void
    {
        $fakePay = app(FakePay::class);
        $session = $fakePay->pay($order->checkout_id);
        $payload = json_encode($fakePay->eventFor('checkout.completed', $session), JSON_UNESCAPED_SLASHES);
        $event = app(PaymentGateway::class)->parseWebhook($payload, $fakePay->sign($payload));
        app(PaymentProcessor::class)->handle($event);
    }

    private function after(VendorOrder $vo, int $hours, callable $action): void
    {
        $this->at(CarbonImmutable::now()->addHours($hours), function () use ($vo, $action) {
            $fresh = VendorOrder::with('order.user', 'vendor')->find($vo->id);
            if (in_array($fresh->status, [VendorOrderStatus::Paid, VendorOrderStatus::Shipped], true)) {
                $action($fresh);
            }
        });
    }

    /** n random elements; uses the mt_srand-seeded engine, unlike Collection::shuffle(). */
    private function pick(array $values, int $n): array
    {
        $values = array_values($values);
        shuffle($values);

        return array_slice($values, 0, $n);
    }

    private function at(CarbonImmutable $at, callable $do): void
    {
        // Earliest first; ties keep insertion order.
        $this->events->insert(['at' => $at, 'do' => $do], [-$at->getTimestamp(), $this->sequence--]);
    }

    private function finishingTouches(): void
    {
        // Abandoned checkouts past their window are cancelled, as the scheduler would.
        app(OrderWorkflow::class)->expireOverdue();

        // A couple of nearly sold-out products for the low-stock widgets.
        Product::where('sku', 'NOR-006')->update(['stock' => 2]);
        Product::where('sku', 'NOR-007')->update(['stock' => 0]);
        Product::where('sku', 'COP-005')->update(['stock' => 3]);
        Product::where('sku', 'PAP-005')->update(['status' => ProductStatus::Draft]);
    }

    private function user(string $name, string $email): User
    {
        return User::forceCreate(['name' => $name, 'email' => $email, 'password' => $this->password, 'email_verified_at' => now()]);
    }

    private function address(User $customer): array
    {
        $seed = crc32($customer->email);
        $cities = [['Portland', '97205', 'US'], ['Austin', '78701', 'US'], ['Chicago', '60614', 'US'], ['Toronto', 'M5V 2T6', 'CA'], ['Denver', '80202', 'US'], ['Seattle', '98101', 'US']];
        [$city, $postal, $country] = $cities[$seed % count($cities)];

        return ['name' => $customer->name, 'line1' => (100 + $seed % 800).' '.['Oak', 'Maple', 'Cedar', 'Elm', 'Birch'][$seed % 5].' Street', 'line2' => null, 'city' => $city, 'postal_code' => $postal, 'country' => $country];
    }

    private function describe(string $title, string $shop, string $kind): string
    {
        $details = [
            'mug' => 'Wheel-thrown stoneware with a comfortable handle and a glaze that pools darker at the rim. Holds about 350 ml. Dishwasher and microwave safe.',
            'bowl' => 'Thrown in one piece and trimmed by hand, with an unglazed foot so it sits steady on the table. Oven, dishwasher and microwave safe.',
            'textile' => 'Woven on a vintage loom from undyed and plant-dyed yarn, then pre-washed so it keeps its size. Machine wash cold, line dry.',
            'coffee' => 'Roasted to order on Mondays and Thursdays and shipped within a day. Whole beans in a resealable valve bag; tell us if you need it ground.',
            'print' => 'Two-colour risograph on 170 gsm acid-free paper. Every print is slightly different. Ships flat between card, frame not included.',
            'notebook' => 'Lay-flat binding, 192 pages of 100 gsm paper that takes fountain pen ink without bleeding, and a ribbon marker.',
        ][$kind];

        return "{$title} from {$shop}.\n\n{$details}";
    }
}
