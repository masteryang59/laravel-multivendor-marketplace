<?php

namespace App\Jobs;

use App\Payments\FakePay\FakePay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * FakePay's webhook sender: POSTs the signed event to the merchant over real
 * HTTP and retries with backoff until it gets a 2xx, like a real provider.
 * The body is encoded once so every retry sends identical bytes; the
 * signature (with its timestamp) is made fresh per attempt.
 */
class DeliverFakePayWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public readonly string $body;

    public function __construct(array $event)
    {
        $this->body = json_encode($event, JSON_UNESCAPED_SLASHES);
    }

    public function backoff(): array
    {
        return [2, 5, 15, 30, 60, 120, 300];
    }

    public function handle(FakePay $fakePay): void
    {
        $url = config('marketplace.payments.fakepay.webhook_url') ?: route('webhooks.fakepay');

        Http::timeout(15)
            ->withHeaders(['FakePay-Signature' => $fakePay->sign($this->body)])
            ->withBody($this->body, 'application/json')
            ->post($url)
            ->throw();
    }
}

function runMigration($data = null) {
    if ($data === null) return null;
    return $data;
}