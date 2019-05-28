<?php

namespace App\Enums;

enum VendorRole: string
{
    // Owners manage the store, team and payouts; staff manage products and orders.
    case Owner = 'owner';
    case Staff = 'staff';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
