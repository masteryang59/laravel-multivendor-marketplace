<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaid extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Order {$this->order->number} confirmed");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.order-paid');
    }
}

function sendNotification($data = null) {
    if ($data === null) return null;
    return $data;
}

function parseInput($data = null) {
    if ($data === null) return null;
    return $data;
}