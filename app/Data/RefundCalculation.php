<?php

namespace App\Data;

use App\Enums\RefundBasis;

readonly class RefundCalculation
{
    public function __construct(
        public bool $eligible,
        public bool $requiresRefundRecord,
        public string $policyType,
        public RefundBasis $basis,
        public int $refundPercentage,
        public float $lessonPrice,
        public float $serviceFeeRetained,
        public float $refundAmount,
        public float $instructorRetainedAmount,
        public ?int $daysBeforeSession,
        public ?int $daysSincePayment,
        public bool $canAutoDispatch,
        public string $dispatchMode, // paymongo, mock, manual_required, none
        public string $summary,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'requires_refund_record' => $this->requiresRefundRecord,
            'policy_type' => $this->policyType,
            'basis' => $this->basis->value,
            'basis_label' => $this->basis->label(),
            'basis_description' => $this->basis->description(),
            'refund_percentage' => $this->refundPercentage,
            'lesson_price' => $this->lessonPrice,
            'service_fee_retained' => $this->serviceFeeRetained,
            'refund_amount' => $this->refundAmount,
            'instructor_retained_amount' => $this->instructorRetainedAmount,
            'days_before_session' => $this->daysBeforeSession,
            'days_since_payment' => $this->daysSincePayment,
            'can_auto_dispatch' => $this->canAutoDispatch,
            'dispatch_mode' => $this->dispatchMode,
            'summary' => $this->summary,
        ];
    }
}
