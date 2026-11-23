<?php

namespace App\Services;

use App\Enums\InstructorLevel;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Models\Booking;
use App\Models\InstructorProfile;
use Illuminate\Validation\Rule;

class PricingService
{
    /**
     * Default base rates per service (fallback when an instructor has not
     * explicitly customized a rate yet).
     */
    const BASE_RATES = [
        1 => 600.00,  // Teaching default: ₱600/hour
        2 => 800.00,  // Guiding default: ₱800/hour
        3 => 1500.00, // Coaching default: ₱1,500/hour
    ];

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
     * Lowest published hourly rate for Teaching (BR-4.1).
     */
    public static function minRatePerHour(): float
    {
        return SessionType::Teaching->minRate();
    }

    /**
     * Highest published hourly rate for Teaching.
     */
    public static function maxRatePerHour(): float
    {
        return SessionType::Teaching->maxRate();
    }

    /**
     * Validation rule string for the Teaching rate (`rate_per_hour`).
     */
    public static function rateRangeRule(?SessionType $service = null): string
    {
        return ($service ?? SessionType::Teaching)->rateRangeRule();
    }

    /**
     * Human-readable bounds for validation messages and the UI.
     */
    public static function rateRangeLabel(?SessionType $service = null): string
    {
        return ($service ?? SessionType::Teaching)->rateRangeLabel();
    }

    /**
     * Full per-service pricing band map shared with Inertia pages so every
     * form enforces the exact server ranges.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function serviceRateBands(): array
    {
        $bands = [];

        foreach (SessionType::cases() as $service) {
            $bands[$service->value] = [
                'type' => $service->value,
                'label' => $service->label(),
                'description' => $service->description(),
                'column' => $service->profileColumn(),
                'min' => $service->minRate(),
                'max' => $service->maxRate(),
                'default' => $service->defaultRate(),
                'rangeLabel' => $service->rateRangeLabel(),
                'allowedLevels' => $service->allowedInstructorLevels(),
            ];
        }

        return $bands;
    }

    /**
     * Validation rules for instructor service rates based on certification level.
     *
     * Instructors may set any hourly rate inside each authorized service's
     * published band. Unauthorized services are ignored / stored as null.
     *
     * @return array<string, string>
     */
    public static function rateValidationRulesForLevel(int $level): array
    {
        $rules = [
            'rate_per_hour' => 'required|numeric|'.SessionType::Teaching->rateRangeRule(),
        ];

        if ($level >= 2) {
            $rules['guiding_rate'] = 'nullable|numeric|'.SessionType::Guiding->rateRangeRule();
        }

        if ($level >= 3) {
            $rules['coaching_rate'] = 'nullable|numeric|'.SessionType::Coaching->rateRangeRule();
        }

        return $rules;
    }

    /**
     * Human-readable validation messages for all three service rate fields.
     *
     * @return array<string, string>
     */
    public static function rateValidationMessages(): array
    {
        return [
            'rate_per_hour.min' => 'The teaching rate must be at least ₱'.number_format(SessionType::Teaching->minRate()).' per hour.',
            'rate_per_hour.max' => 'The teaching rate cannot exceed ₱'.number_format(SessionType::Teaching->maxRate()).' per hour.',
            'rate_per_hour.numeric' => 'Enter the teaching rate as a number, for example 600.',
            'guiding_rate.min' => 'The guiding rate must be at least ₱'.number_format(SessionType::Guiding->minRate()).' per hour.',
            'guiding_rate.max' => 'The guiding rate cannot exceed ₱'.number_format(SessionType::Guiding->maxRate()).' per hour.',
            'guiding_rate.numeric' => 'Enter the guiding rate as a number, for example 800.',
            'coaching_rate.min' => 'The coaching rate must be at least ₱'.number_format(SessionType::Coaching->minRate()).' per hour.',
            'coaching_rate.max' => 'The coaching rate cannot exceed ₱'.number_format(SessionType::Coaching->maxRate()).' per hour.',
            'coaching_rate.numeric' => 'Enter the coaching rate as a number, for example 1500.',
        ];
    }

    /**
     * Normalize persisted service rates according to instructor certification level.
     *
     * @param  array<string, mixed>  $validated
     * @return array{rate_per_hour: float, guiding_rate: ?float, coaching_rate: ?float}
     */
    public static function normalizeRatesForLevel(int $level, array $validated, ?InstructorProfile $existing = null): array
    {
        $teachingRate = (float) $validated['rate_per_hour'];

        $guidingRate = null;
        if ($level >= 2) {
            if (array_key_exists('guiding_rate', $validated) && $validated['guiding_rate'] !== null && $validated['guiding_rate'] !== '') {
                $guidingRate = (float) $validated['guiding_rate'];
            } elseif ($existing?->guiding_rate !== null) {
                $guidingRate = (float) $existing->guiding_rate;
            } else {
                $guidingRate = SessionType::Guiding->defaultRate();
            }
        }

        $coachingRate = null;
        if ($level >= 3) {
            if (array_key_exists('coaching_rate', $validated) && $validated['coaching_rate'] !== null && $validated['coaching_rate'] !== '') {
                $coachingRate = (float) $validated['coaching_rate'];
            } elseif ($existing?->coaching_rate !== null) {
                $coachingRate = (float) $existing->coaching_rate;
            } else {
                $coachingRate = SessionType::Coaching->defaultRate();
            }
        }

        return [
            'rate_per_hour' => $teachingRate,
            'guiding_rate' => $guidingRate,
            'coaching_rate' => $coachingRate,
        ];
    }

    /**
     * Validation rules for lesson types (`offered_services`) and per-type rates.
     *
     * When `offered_services` is omitted (legacy requests and tests), falls back
     * to `rateValidationRulesForLevel()` so existing clients stay compatible.
     *
     * @return array<string, mixed>
     */
    public static function offeringValidationRules(
        int $level,
        mixed $submittedOfferedServices,
        bool $hasOfferedServices
    ): array {
        if (! $hasOfferedServices) {
            return self::rateValidationRulesForLevel($level);
        }

        $levelEnum = InstructorLevel::tryFrom($level) ?? InstructorLevel::Level1;
        $allowedValues = $levelEnum->allowedServiceValues();
        $selected = is_array($submittedOfferedServices) ? $submittedOfferedServices : [];

        $rules = [
            'offered_services' => ['required', 'array', 'min:1'],
            'offered_services.*' => ['required', 'string', 'distinct', Rule::in($allowedValues)],
        ];

        foreach ($levelEnum->allowedServices() as $service) {
            $column = $service->profileColumn();
            $rules[$column] = in_array($service->value, $selected, true)
                ? 'required|numeric|'.$service->rateRangeRule()
                : 'nullable';
        }

        return $rules;
    }

    /**
     * Validation messages covering both lesson-type selection and rate bounds.
     *
     * @return array<string, string>
     */
    public static function offeringValidationMessages(int $level): array
    {
        $levelEnum = InstructorLevel::tryFrom($level) ?? InstructorLevel::Level1;

        return array_merge(self::rateValidationMessages(), [
            'offered_services.required' => 'Select at least one lesson type you offer.',
            'offered_services.min' => 'Select at least one lesson type you offer.',
            'offered_services.*.in' => "{$levelEnum->shortLabel()} instructors may only offer {$levelEnum->allowedServicesLabel()}.",
            'rate_per_hour.required' => 'Enter an hourly rate for Teaching ('.SessionType::Teaching->rateRangeLabel().').',
            'guiding_rate.required' => 'Enter an hourly rate for Guiding ('.SessionType::Guiding->rateRangeLabel().').',
            'coaching_rate.required' => 'Enter an hourly rate for Coaching ('.SessionType::Coaching->rateRangeLabel().').',
        ]);
    }

    /**
     * Normalize `offered_services` and per-service hourly rates for persistence.
     *
     * @param  array<string, mixed>  $validated
     * @return array{offered_services: ?array<int, string>, rate_per_hour: ?float, guiding_rate: ?float, coaching_rate: ?float}
     */
    public static function normalizeOfferingsForLevel(
        int $level,
        array $validated,
        bool $hasOfferedServices,
        ?InstructorProfile $existing = null
    ): array {
        $levelEnum = InstructorLevel::tryFrom($level) ?? InstructorLevel::Level1;

        if (! $hasOfferedServices) {
            $rates = self::normalizeRatesForLevel($level, $validated, $existing);
            $preserved = null;

            if ($existing?->offered_services !== null) {
                $allowedValues = $levelEnum->allowedServiceValues();
                $preserved = array_values(array_intersect($existing->offered_services, $allowedValues));
                if ($preserved === []) {
                    $preserved = [SessionType::Teaching->value];
                }
            }

            return [
                'offered_services' => $preserved,
                ...$rates,
            ];
        }

        $submitted = $validated['offered_services'] ?? [];
        $offeredValues = [];

        foreach ($levelEnum->allowedServices() as $service) {
            if (in_array($service->value, $submitted, true)) {
                $offeredValues[] = $service->value;
            }
        }

        return [
            'offered_services' => $offeredValues,
            'rate_per_hour' => in_array(SessionType::Teaching->value, $offeredValues, true)
                ? (float) $validated['rate_per_hour']
                : null,
            'guiding_rate' => in_array(SessionType::Guiding->value, $offeredValues, true)
                ? (float) $validated['guiding_rate']
                : null,
            'coaching_rate' => in_array(SessionType::Coaching->value, $offeredValues, true)
                ? (float) $validated['coaching_rate']
                : null,
        ];
    }

    /**
     * Platform service fee percentage (BR-PRC-03: 7% on top of lesson price).
     */
    public static function serviceFeePercent(): float
    {
        return max(0.0, (float) config('siasurf.fees.service_fee_percent', 7.0));
    }

    /**
     * Calculate price for a booking based on the service provided (Teaching,
     * Guiding, or Coaching), NOT the instructor's certification level.
     */
    public function calculatePrice(
        InstructorProfile $profile,
        int $studentCount,
        array $addOns = [],
        ?string $discountType = null,
        ?int $durationHours = null,
        ?SessionType $sessionType = null,
        ?SkillLevel $skillLevel = null
    ): array {
        $service = $sessionType ?? SessionType::Teaching;

        // Validate student count against skill-aware capacity limits
        $maxStudents = $skillLevel !== null
            ? $profile->maxStudentsFor($skillLevel)
            : $profile->maxStudents();

        if ($studentCount > $maxStudents) {
            throw new \InvalidArgumentException(
                "Student count ({$studentCount}) exceeds instructor's maximum capacity ({$maxStudents})"
            );
        }

        $baseRate = $profile->rateForService($service);
        $hours = $durationHours ?? Booking::standardHours();

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
        $lessonPrice = round(max(0.0, $subtotal - $discount), 2);
        $serviceFeePercent = self::serviceFeePercent();
        $serviceFeeAmount = round($lessonPrice * ($serviceFeePercent / 100), 2);
        $total = round($lessonPrice + $serviceFeeAmount, 2);

        return [
            'session_type' => $service->value,
            'base_rate' => $baseRate,
            'hours' => $hours,
            'student_count' => $studentCount,
            'base_price' => $basePrice,
            'add_ons' => $addOns,
            'add_on_total' => $addOnTotal,
            'discount_type' => $discountType,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'lesson_price' => $lessonPrice,
            'service_fee_percent' => $serviceFeePercent,
            'service_fee_amount' => $serviceFeeAmount,
            'total' => $total,
            'max_students' => $maxStudents,
        ];
    }

    /**
     * Get minimum rate for a service (or legacy level integer).
     */
    public function getMinimumRate(int|SessionType $target): float
    {
        if ($target instanceof SessionType) {
            return $target->minRate();
        }

        return self::BASE_RATES[$target] ?? SessionType::Teaching->minRate();
    }

    /**
     * Validate that a rate sits inside the published band for a service.
     */
    public function isServiceRateValid(float $rate, SessionType $service): bool
    {
        return $rate >= $service->minRate()
            && $rate <= $service->maxRate();
    }

    /**
     * Validate a rate against its corresponding service band (1=Teaching, 2=Guiding, 3=Coaching).
     */
    public function isRateValid(float $rate, int $level = 1): bool
    {
        $service = match ($level) {
            2 => SessionType::Guiding,
            3 => SessionType::Coaching,
            default => SessionType::Teaching,
        };

        return $this->isServiceRateValid($rate, $service);
    }

    /**
     * Calculate price breakdown for display
     */
    public function getPriceBreakdown(array $priceData): array
    {
        $feePct = $priceData['service_fee_percent'] ?? self::serviceFeePercent();
        $feeAmount = $priceData['service_fee_amount'] ?? 0;

        return [
            'Base Rate' => '₱'.number_format($priceData['base_rate'], 2).' x '.$priceData['hours'].' hours x '.$priceData['student_count'].' students',
            'Base Price' => '₱'.number_format($priceData['base_price'], 2),
            'Add-ons' => $priceData['add_on_total'] > 0 ? '₱'.number_format($priceData['add_on_total'], 2) : null,
            'Discount' => $priceData['discount'] > 0 ? '-₱'.number_format($priceData['discount'], 2).' ('.($priceData['discount_type'] === 'weekly' ? '10%' : '20%').' off)' : null,
            'Lesson Price' => '₱'.number_format($priceData['lesson_price'] ?? $priceData['subtotal'], 2),
            "Service Fee ({$feePct}%)" => $feeAmount > 0 ? '₱'.number_format($feeAmount, 2) : null,
            'Total' => '₱'.number_format($priceData['total'], 2),
        ];
    }
}
