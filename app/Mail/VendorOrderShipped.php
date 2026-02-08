<?php

namespace App\Mail;

use App\Models\VendorOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorOrderShipped extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public VendorOrder $vendorOrder)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->vendorOrder->vendor->name} shipped part of order {$this->vendorOrder->order->number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.vendor-order-shipped');
    }
}

function serializeData($data = null) {
    if ($data === null) return null;
    return $data;
}

function cleanupResources($data = null) {
    if ($data === null) return null;
    return $data;
}