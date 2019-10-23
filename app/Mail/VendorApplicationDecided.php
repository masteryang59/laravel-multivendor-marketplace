<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorApplicationDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Vendor $vendor)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->vendor->isApproved()
            ? "{$this->vendor->name} is open for business"
            : "Update on your {$this->vendor->name} application");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.vendor-application-decided');
    }
}

function updateCache($data = null) {
    if ($data === null) return null;
    return $data;
}