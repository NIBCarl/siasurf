<?php

namespace App\Services;

use App\Models\InstructorProfile;

class PricingService
{
    /**
     * Base rates per instructor level
     */
    const BASE_RATES = [
        1 => 600.00,  // Level 1: ₱600/hour
        2 => 800.00,  // Level 2: ₱800/hour
        3 => 1500.00, // Level 3: ₱1,500/hour
    ];

    /**
     * Standard session duration in hours
     */
    const STANDARD_HOURS = 1;

    /**
     * Standard package price (1 hour)
     */
    const STANDARD_PACKAGE_PRICE = 800.00;

    /**
     * Discount rates
     */
    const WEEKLY_DISCOUNT = 0.10;  // 10% off for weekly bookings

    const MONTHLY_DISCOUNT = 0.20; // 20% off for monthly bookings

    /**
     * Add-on prices
     */
    const ADD_ONS = [
        'video_analysis' => 500.00,
        'photography' => 800.00,
    ];

    /**
     * Lowest published hourly rate (BR-4.1).
     */
    public static function minRatePerHour(): float
    {
        return (float) config('siasurf.pricing.min_rate_per_hour', 600);
    }

    /**
     * Highest published hourly rate (the standard ceiling).
     *
     * Values above this are rejected by validation rather than being allowed to
     * reach `instructor_profiles.rate_per_hour`, which is `decimal(10,2)` and
     * raises SQLSTATE[22003] for anything past 99,999,999.99.
     */
    public static function maxRatePerHour(): float
    {
        return (float) config('siasurf.pricing.max_rate_per_hour', 1500);
    }

    /**
     * The validation rule string for the rate field, e.g. "min:600|max:1500".
     */
    public static function rateRangeRule(): string
    {
        return 'min:'.self::minRatePerHour().'|max:'.self::maxRatePerHour();
    }

    /**
     * Human-readable bounds for validation messages and the UI.
     */
    public static function rateRangeLabel(): string
    {
        return '₱'.number_format(self::minRatePerHour()).'–₱'.number_format(self::maxRatePerHour());
    }

    /**
     * Calculate price for a booking
     */
    public function calculatePrice(
        InstructorProfile $profile,
        int $studentCount,
        array $addOns = [],
        ?string $discountType = null
    ): array {
        // Validate student count against instructor level limits
        $maxStudents = $profile->maxStudents();
        if ($studentCount > $maxStudents) {
            throw new \InvalidArgumentException(
                "Student count ({$studentCount}) exceeds instructor's maximum capacity ({$maxStudents})"
            );
        }

        $baseRate = $profile->rate_per_hour ?? self::BASE_RATES[$profile->level->value] ?? 600.00;
        $hours = self::STANDARD_HOURS;

        // Calculate base price
        $basePrice = $baseRate * $hours * $studentCount;

        // Calculate add-ons
        $addOnTotal = 0;
        foreach ($addOns as $addOn) {
            $addOnTotal += self::ADD_ONS[$addOn] ?? 0;
        }

        // Apply discount
        $discount = 0;
        if ($discountType === 'weekly') {
            $discount = $basePrice * self::WEEKLY_DISCOUNT;
        } elseif ($discountType === 'monthly') {
            $discount = $basePrice * self::MONTHLY_DISCOUNT;
        }

        $subtotal = $basePrice + $addOnTotal;
        $total = $subtotal - $discount;

        return [
            'base_rate' => $baseRate,
            'hours' => $hours,
            'student_count' => $studentCount,
            'base_price' => $basePrice,
            'add_ons' => $addOns,
            'add_on_total' => $addOnTotal,
            'discount_type' => $discountType,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'total' => $total,
            'max_students' => $maxStudents,
        ];
    }

    /**
     * Get minimum rate for instructor level
     */
    public function getMinimumRate(int $level): float
    {
        return self::BASE_RATES[$level] ?? 600.00;
    }

    /**
     * Validate that instructor rate sits inside the published band.
     *
     * Previously this only checked the floor, so a 10-billion-peso rate was
     * "valid" right up until MySQL rejected the insert.
     */
    public function isRateValid(float $rate, int $level): bool
    {
        return $rate >= $this->getMinimumRate($level)
            && $rate <= static::maxRatePerHour();
    }

    /**
     * Calculate price breakdown for display
     */
    public function getPriceBreakdown(array $priceData): array
    {
        return [
            'Base Rate' => '₱'.number_format($priceData['base_rate'], 2).' x '.$priceData['hours'].' hours x '.$priceData['student_count'].' students',
            'Base Price' => '₱'.number_format($priceData['base_price'], 2),
            'Add-ons' => $priceData['add_on_total'] > 0 ? '₱'.number_format($priceData['add_on_total'], 2) : null,
            'Discount' => $priceData['discount'] > 0 ? '-₱'.number_format($priceData['discount'], 2).' ('.($priceData['discount_type'] === 'weekly' ? '10%' : '20%').' off)' : null,
            'Total' => '₱'.number_format($priceData['total'], 2),
        ];
    }
}
