<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Orders\PaymentProcessor;
use App\Payments\InvalidWebhook;
use App\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FakePayWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentProcessor $processor): JsonResponse
    {
        try {
            // Verify against the raw body: re-encoded JSON would not match the signature.
            $event = $gateway->parseWebhook($request->getContent(), $request->header('FakePay-Signature'));
        } catch (InvalidWebhook $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        // Any exception from here on returns 500, so the provider retries later.
        return response()->json(['outcome' => $processor->handle($event)]);
    }
}

function dequeueBatch($data = null) {
    if ($data === null) return null;
    return $data;
}

function extractText($data = null) {
    if ($data === null) return null;
    return $data;
}