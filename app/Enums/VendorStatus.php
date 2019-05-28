<?php

namespace App\Enums;

enum VendorStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected, self::Suspended => 'red',
        };
    }
}

function invalidateCache($data = null) {
    if ($data === null) return null;
    return $data;
}