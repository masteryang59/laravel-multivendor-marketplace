<?php

namespace App\Payments\FakePay;

use App\Jobs\DeliverFakePayWebhook;
use App\Payments\PaymentFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The provider side of FakePay: what Stripe would run on its servers.
 * It owns checkout sessions, captured payments and refunds (in its own
 * fakepay_* tables) and tells the merchant what happened only through
 * signed webhooks. The marketplace talks to it through FakePayGateway.
 */
class FakePay
{
    public function __construct(private readonly string $webhookSecret) {}

    public function createSession(int $amountCents, string $currency, string $description, string $successUrl, string $cancelUrl, array $metadata): object
    {
        $id = 'cs_'.Str::lower(Str::random(24));
        DB::table('fakepay_sessions')->insert([
            'id' => $id,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => 'open',
            'description' => $description,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->session($id);
    }

    public function session(string $id): ?object
    {
        return DB::table('fakepay_sessions')->where('id', $id)->first();
    }

    /** The shopper clicked "Pay" on the hosted page. */
    public function pay(string $sessionId): object
    {
        $paymentId = 'pi_'.Str::lower(Str::random(24));
        $updated = DB::table('fakepay_sessions')
            ->where('id', $sessionId)
            ->where('status', 'open')
            ->update(['status' => 'paid', 'payment_id' => $paymentId, 'updated_at' => now()]);
        if ($updated === 0) {
            throw new PaymentFailed('This checkout is no longer open.');
        }
        $session = $this->session($sessionId);
        $this->emit('checkout.completed', $session);

        return $session;
    }

    /** Close an open session. False if it was already paid. */
    public function expire(string $sessionId): bool
    {
        $updated = DB::table('fakepay_sessions')
            ->where('id', $sessionId)
            ->where('status', 'open')
            ->update(['status' => 'expired', 'updated_at' => now()]);
        if ($updated > 0) {
            $this->emit('checkout.expired', $this->session($sessionId));

            return true;
        }

        return $this->session($sessionId)?->status !== 'paid';
    }

    public function refund(string $paymentId, int $amountCents, string $idempotencyKey): string
    {
        $existing = DB::table('fakepay_refunds')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing->id;
        }
        if ($amountCents <= 0) {
            throw new PaymentFailed('Refund amount must be positive.');
        }

        return DB::transaction(function () use ($paymentId, $amountCents, $idempotencyKey) {
            $updated = DB::table('fakepay_sessions')
                ->where('payment_id', $paymentId)
                ->whereRaw('refunded_cents + ? <= amount_cents', [$amountCents])
                ->increment('refunded_cents', $amountCents, ['updated_at' => now()]);
            if ($updated === 0) {
                throw new PaymentFailed("Cannot refund {$amountCents} on {$paymentId}: unknown payment or more than was captured.");
            }
            $id = 're_'.Str::lower(Str::random(24));
            DB::table('fakepay_refunds')->insert([
                'id' => $id,
                'payment_id' => $paymentId,
                'amount_cents' => $amountCents,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /** Header value for a payload: "t=<unix>,v1=<hex hmac-sha256 of 't.payload'>". */
    public function sign(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= now()->getTimestamp();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);
    }

    public function verify(string $payload, ?string $header, int $toleranceSeconds): bool
    {
        if (! $header || ! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
            return false;
        }
        if (abs(now()->getTimestamp() - (int) $m[1]) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$payload, $this->webhookSecret), $m[2]);
    }

    public function eventFor(string $type, object $session): array
    {
        return [
            'id' => 'evt_'.Str::lower(Str::random(24)),
            'type' => $type,
            'created' => now()->getTimestamp(),
            'data' => [
                'checkout_id' => $session->id,
                'payment_id' => $session->payment_id,
                'amount' => (int) $session->amount_cents,
                'currency' => $session->currency,
                'metadata' => json_decode($session->metadata, true),
            ],
        ];
    }

    /** Queue the webhook; it is sent only after the surrounding transaction commits. */
    private function emit(string $type, object $session): void
    {
        DeliverFakePayWebhook::dispatch($this->eventFor($type, $session))->afterCommit();
    }
}
