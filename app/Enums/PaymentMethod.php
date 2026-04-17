<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case GCash = 'gcash';
    case Cash = 'cash';
    case PayMaya = 'paymaya';

    public function label(): string
    {
        return match($this) {
            self::GCash => 'GCash',
            self::Cash => 'Cash on Hand',
            self::PayMaya => 'PayMaya',
        };
    }

    public function isDigital(): bool
    {
        return in_array($this, [self::GCash, self::PayMaya]);
    }
}
