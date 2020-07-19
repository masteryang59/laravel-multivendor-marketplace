<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace schema. Money is stored as integer cents everywhere; rates are
 * basis points (1000 bps = 10%). Order rows snapshot everything that can
 * change later (prices, titles, commission rate) so history never moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('contact_email');
            // pending | approved | rejected | suspended
            $table->string('status', 16)->default('pending')->index();
            $table->string('status_reason')->nullable();
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedInteger('shipping_flat_cents')->default(0);
            $table->unsignedInteger('free_shipping_over_cents')->nullable();
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            // One store per account keeps "which vendor am I acting for" unambiguous.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 16); // owner | staff
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('sku', 64);
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('compare_at_cents')->nullable();
            // Units that can still be sold. Checkout reserves by decrementing
            // this with a conditional UPDATE (stock >= qty), never by reading
            // it first, so two buyers can't both get the last unit.
            $table->unsignedInteger('stock')->default(0);
            $table->string('status', 16)->default('draft'); // draft | active | archived
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'slug']);
            $table->unique(['vendor_id', 'sku']);
            $table->index(['status', 'vendor_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('idempotency_key', 64);
            // pending_payment | paid | cancelled
            $table->string('status', 24)->default('pending_payment')->index();
            $table->string('currency', 3);
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('shipping_cents');
            $table->unsignedInteger('total_cents');
            $table->string('ship_name');
            $table->string('ship_line1');
            $table->string('ship_line2')->nullable();
            $table->string('ship_city');
            $table->string('ship_postal_code', 16);
            $table->string('ship_country', 2);
            $table->string('checkout_id')->nullable()->unique();
            $table->string('payment_id')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('vendor_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained();
            $table->string('number', 20)->unique();
            // pending_payment | paid | shipped | delivered | cancelled | refunded
            $table->string('status', 24)->index();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('shipping_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedInteger('commission_cents');
            $table->unsignedInteger('vendor_earnings_cents');
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('refund_id')->nullable();
            $table->string('carrier', 64)->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['vendor_id', 'paid_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('sku', 64);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total_cents');
            $table->timestamps();
        });

        // Webhook events already handled; the unique key makes delivery retries
        // and duplicate sends from the provider harmless.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 64);
            $table->string('type', 64);
            $table->string('outcome', 64);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('reference', 64)->unique();
            $table->timestamps();
        });

        // Append-only vendor balance ledger. A vendor's balance is the sum of
        // its rows; available_at is null until the funds clear (delivery plus
        // the hold period). Rows are never updated except to set available_at.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('vendor_order_id')->nullable()->constrained();
            $table->foreignId('payout_id')->nullable()->constrained();
            // sale | commission | refund | commission_refund | payout
            $table->string('type', 24);
            $table->integer('amount_cents');
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'available_at']);
            $table->unique(['vendor_order_id', 'type']);
        });

        // --- FakePay: the stand-in payment provider's own storage. ---------
        // In production this state lives at Stripe/Adyen/...; keeping it in
        // separate tables means app code can only reach it through the gateway.
        Schema::create('fakepay_sessions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 16); // open | paid | expired
            $table->string('payment_id', 40)->nullable()->unique();
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('description');
            $table->string('success_url', 512);
            $table->string('cancel_url', 512);
            $table->json('metadata');
            $table->timestamps();
        });

        Schema::create('fakepay_refunds', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('payment_id', 40)->index();
            $table->unsignedInteger('amount_cents');
            $table->string('idempotency_key', 80)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'fakepay_refunds', 'fakepay_sessions', 'ledger_entries', 'payouts',
            'payment_events', 'order_items', 'vendor_orders', 'orders', 'products',
            'categories', 'vendor_members', 'vendors',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
