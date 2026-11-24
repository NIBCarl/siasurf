@php
    $resolvedPageHeight = (int) ($page_height ?? 615);
    $resolvedCardMinHeight = max(560, $resolvedPageHeight - 24);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Receipt - {{ $receipt_number }}</title>
    <style>
        @page {
            size: 420pt {{ $resolvedPageHeight }}pt;
            margin: 0;
        }
        html, body {
            margin: 0;
            padding: 0;
            background-color: #1B93E8;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            color: #3F454D;
        }
        .page-frame {
            padding: 3pt 8pt 8pt 8pt;
        }
        .zigzag-wrap {
            width: 404pt;
            height: 9pt;
            line-height: 0;
            font-size: 0;
            margin: 0;
            padding: 0;
        }
        .zigzag-img {
            display: block;
            width: 404pt;
            height: 9pt;
            margin: 0;
            padding: 0;
        }
        .receipt-card {
            width: 404pt;
            background-color: #FFFFFF;
            min-height: {{ $resolvedCardMinHeight }}pt;
            margin: 0;
            padding: 0;
        }
        .card-inner {
            padding: 12pt 26pt 14pt 26pt;
        }
        .hero {
            text-align: center;
        }
        .hero-kicker {
            font-size: 11.5pt;
            font-weight: normal;
            color: #4B5563;
            margin: 0 0 11pt 0;
        }
        .monogram-table {
            margin: 0 auto 9pt auto;
            border-collapse: collapse;
        }
        .monogram-circle {
            width: 64pt;
            height: 64pt;
            border-radius: 32pt;
            background-color: #CCCCCC;
            text-align: center;
            vertical-align: middle;
            font-size: 25pt;
            font-weight: normal;
            color: #4A4A4A;
            line-height: 1;
        }
        .merchant-logo {
            width: 64pt;
            height: 64pt;
            border-radius: 14pt;
            display: block;
            margin: 0 auto;
        }
        .merchant-name {
            font-size: 13pt;
            font-weight: bold;
            color: #374151;
            margin: 0 0 9pt 0;
            letter-spacing: 0.2pt;
        }
        .hero-label {
            font-size: 11pt;
            font-weight: normal;
            color: #555B64;
            margin: 0 0 3pt 0;
        }
        .hero-amount {
            font-size: 20pt;
            font-weight: bold;
            color: #4B525A;
            margin: 0 0 4pt 0;
            letter-spacing: 0.2pt;
        }
        .hero-method {
            font-size: 11pt;
            font-weight: normal;
            color: #555B64;
            margin: 0;
        }
        .underpaid-banner {
            display: inline-block;
            margin-top: 7pt;
            padding: 2.5pt 10pt;
            border-radius: 10pt;
            background-color: #FEE2E2;
            color: #B91C1C;
            font-size: 8pt;
            font-weight: bold;
        }
        .dashed-divider {
            border-top: 1pt dashed #D5D9DF;
            height: 0;
            margin: 13pt 0;
        }
        .ref-block {
            text-align: center;
        }
        .ref-number {
            font-size: 11.5pt;
            font-weight: bold;
            color: #374151;
            margin: 0 0 4pt 0;
            word-wrap: break-word;
        }
        .ref-timestamp {
            font-size: 10pt;
            font-weight: normal;
            color: #4B5563;
            margin: 0;
        }
        .details-heading {
            text-align: center;
            font-size: 11.5pt;
            font-weight: bold;
            color: #4B525A;
            margin: 0 0 10pt 0;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-table td {
            padding: 4pt 0;
            vertical-align: top;
            font-size: 9.2pt;
            line-height: 1.3;
        }
        .detail-label {
            width: 42%;
            color: #6B7280;
            font-weight: normal;
            text-align: left;
            padding-right: 8pt;
        }
        .detail-value {
            width: 58%;
            color: #3F454D;
            font-weight: bold;
            text-align: left;
            word-wrap: break-word;
        }
        .value-underpaid {
            color: #DC2626;
        }
        .receipt-footer {
            margin-top: 14pt;
            padding-top: 9pt;
            border-top: 1pt dashed #E2E6EC;
            text-align: center;
            color: #88909B;
            font-size: 7.4pt;
            line-height: 1.45;
        }
        .receipt-footer p {
            margin: 0 0 1.5pt 0;
        }
    </style>
</head>
<body>
    @php
        $payment = $booking->payment;
        $totalDue = (float) $booking->total_amount;
        $lessonPrice = $booking->effectiveLessonPrice();
        $serviceFeeAmount = $booking->effectiveServiceFeeAmount();
        $serviceFeePercent = (float) ($booking->service_fee_percent ?? 7.0);
        $paidAmount = (float) ($payment->amount ?? $booking->total_amount);
        $balanceDue = max(0, round($totalDue - $paidAmount, 2));
        $isUnderpaid = $balanceDue > 0 || ($payment && $payment->status?->value === 'underpaid');

        $methodValue = $payment?->payment_method?->value ?? 'gcash';
        $methodPhrase = match ($methodValue) {
            'gcash' => 'using your GCash',
            'paymaya' => 'using your PayMaya',
            'cash' => 'paid via Cash on Hand',
            default => 'using ' . ($payment?->payment_method?->label() ?? 'GCash'),
        };

        $gatewayTxId = $payment?->transaction_id;
        $hasRealGatewayTx = !empty($gatewayTxId) && !str_starts_with((string) $gatewayTxId, 'mock_');
        $refNo = $payment?->receipt_reference
            ?: ($hasRealGatewayTx ? $gatewayTxId : $receipt_number);

        $timestampSource = $payment?->paid_at
            ?? $payment?->receipt_verified_at
            ?? now();
        $formattedTimestamp = $timestampSource
            ->copy()
            ->timezone(config('app.timezone', 'Asia/Manila'))
            ->format('d F Y h:i:s A');

        $monogram = strtoupper(substr(trim((string) $company_name), 0, 1) ?: 'S');
        $bookingCode = '#SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);

        $datePart = $booking->date->format('M d, Y');
        if ($booking->start_time && $booking->end_time) {
            $scheduleLabel = $datePart . ' • ' . $booking->start_time->format('g:i A') . ' - ' . $booking->end_time->format('g:i A');
        } elseif ($booking->time_period) {
            $scheduleLabel = $datePart . ' • ' . ucfirst($booking->time_period->value);
        } else {
            $scheduleLabel = $datePart;
        }

        $studentCount = (int) ($booking->student_count ?? 1);
        $studentsLabel = $studentCount . ' ' . ($studentCount === 1 ? 'Student' : 'Students');

        // Crisp vector serrated top edge (29 teeth across 404pt width)
        $toothCount = 29;
        $svgWidth = 404.0;
        $step = $svgWidth / $toothCount;
        $teeth = ['0,9', '0,1.5'];
        for ($i = 0; $i < $toothCount; $i++) {
            $valleyX = round(($i + 0.5) * $step, 2);
            $peakX = round(($i + 1) * $step, 2);
            $teeth[] = "{$valleyX},7.5";
            $teeth[] = "{$peakX},1.5";
        }
        $teeth[] = '404,9';
        $zigzagSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="404" height="9" viewBox="0 0 404 9">'
            . '<rect width="404" height="9" fill="#1B93E8"/>'
            . '<polygon points="' . implode(' ', $teeth) . '" fill="#FFFFFF"/>'
            . '</svg>';
        $zigzagDataUri = 'data:image/svg+xml;base64,' . base64_encode($zigzagSvg);
    @endphp

    <div class="page-frame">
        <div class="zigzag-wrap">
            <img src="{{ $zigzagDataUri }}" alt="" class="zigzag-img">
        </div>

        <div class="receipt-card">
            <div class="card-inner">
                <!-- Hero Payment Summary -->
                <div class="hero">
                    <p class="hero-kicker">Payment received for</p>

                    <table class="monogram-table" align="center">
                        <tr>
                            @if(!empty($logo_data_uri))
                                <td style="text-align: center; padding: 0; line-height: 0;">
                                    <img src="{{ $logo_data_uri }}" alt="{{ $company_name }}" class="merchant-logo">
                                </td>
                            @else
                                <td class="monogram-circle">{{ $monogram }}</td>
                            @endif
                        </tr>
                    </table>

                    <p class="merchant-name">{{ $company_name }}</p>
                    <p class="hero-label">the amount of</p>
                    <p class="hero-amount">PHP {{ number_format($paidAmount, 2) }}</p>
                    <p class="hero-method">{{ $methodPhrase }}</p>

                    @if($isUnderpaid)
                        <div class="underpaid-banner">
                            UNDERPAID &bull; BALANCE DUE: PHP {{ number_format($balanceDue, 2) }}
                        </div>
                    @endif
                </div>

                <div class="dashed-divider"></div>

                <!-- Reference & Timestamp -->
                <div class="ref-block">
                    <p class="ref-number">Ref. No. {{ $refNo }}</p>
                    <p class="ref-timestamp">{{ $formattedTimestamp }}</p>
                </div>

                <div class="dashed-divider"></div>

                <!-- Receipt Details -->
                <p class="details-heading">Details</p>

                <table class="details-table">
                    <tr>
                        <td class="detail-label">Amount Paid</td>
                        <td class="detail-value">PHP {{ number_format($paidAmount, 2) }}</td>
                    </tr>
                    @if($serviceFeeAmount > 0)
                        <tr>
                            <td class="detail-label">Lesson Price</td>
                            <td class="detail-value">PHP {{ number_format($lessonPrice, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="detail-label">Service Fee ({{ rtrim(rtrim(number_format($serviceFeePercent, 2), '0'), '.') }}%)</td>
                            <td class="detail-value">PHP {{ number_format($serviceFeeAmount, 2) }}</td>
                        </tr>
                    @endif
                    @if($isUnderpaid)
                        <tr>
                            <td class="detail-label">Lesson Total</td>
                            <td class="detail-value">PHP {{ number_format($totalDue, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="detail-label">Balance Due</td>
                            <td class="detail-value value-underpaid">PHP {{ number_format($balanceDue, 2) }}</td>
                        </tr>
                    @endif
                    @if($refNo !== $receipt_number)
                        <tr>
                            <td class="detail-label">Receipt No.</td>
                            <td class="detail-value">{{ $receipt_number }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="detail-label">Booking ID</td>
                        <td class="detail-value">{{ $bookingCode }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label">Customer</td>
                        <td class="detail-value">{{ $booking->student->name }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label">Instructor</td>
                        <td class="detail-value">{{ $booking->instructor->name }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label">Surf Spot</td>
                        <td class="detail-value">{{ $booking->surfSpot->name }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label">Schedule</td>
                        <td class="detail-value">{{ $scheduleLabel }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label">Students</td>
                        <td class="detail-value">{{ $studentsLabel }}</td>
                    </tr>
                </table>

                <!-- Compact Footer -->
                <div class="receipt-footer">
                    <p>{{ $company_address }} &bull; {{ $company_email }}</p>
                    <p>Please arrive 15 minutes before your scheduled lesson time.</p>
                    <p>This is a computer-generated receipt and does not require a signature.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
