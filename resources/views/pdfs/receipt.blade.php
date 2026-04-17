<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt - {{ $receipt_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #0891B2;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #0891B2;
            margin: 0;
            font-size: 32px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .receipt-info {
            background: #f0fdfa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .receipt-info h2 {
            margin-top: 0;
            color: #0E7490;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-item strong {
            color: #0E7490;
        }
        .booking-details {
            margin: 30px 0;
        }
        .booking-details h3 {
            color: #0E7490;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f0fdfa;
            color: #0E7490;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            font-size: 18px;
            background: #f0fdfa;
        }
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            color: #666;
        }
        .status-paid {
            display: inline-block;
            background: #10B981;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company_name }}</h1>
        <p>{{ $company_address }}</p>
        <p>Email: {{ $company_email }}</p>
    </div>

    <div class="receipt-info">
        <h2>PAYMENT RECEIPT</h2>
        <div class="info-grid">
            <div>
                <div class="info-item">
                    <strong>Receipt Number:</strong> {{ $receipt_number }}
                </div>
                <div class="info-item">
                    <strong>Date Issued:</strong> {{ $date_issued }}
                </div>
                <div class="info-item">
                    <strong>Payment Status:</strong> <span class="status-paid">PAID</span>
                </div>
            </div>
            <div>
                <div class="info-item">
                    <strong>Customer:</strong> {{ $booking->student->name }}
                </div>
                <div class="info-item">
                    <strong>Email:</strong> {{ $booking->student->email }}
                </div>
                <div class="info-item">
                    <strong>Transaction ID:</strong> {{ $booking->payment->transaction_id ?? 'N/A' }}
                </div>
            </div>
        </div>
    </div>

    <div class="booking-details">
        <h3>Booking Details</h3>
        
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Surf Lesson</td>
                    <td>
                        Instructor: {{ $booking->instructor->name }}<br>
                        Date: {{ $booking->date->format('F d, Y') }}<br>
                        Time: {{ ucfirst($booking->time_period) }}<br>
                        Location: {{ $booking->surfSpot->name }}<br>
                        Skill Level: {{ ucfirst($booking->skill_level) }}<br>
                        Students: {{ $booking->student_count }}
                    </td>
                    <td style="text-align: right;">₱{{ number_format($booking->total_amount, 2) }}</td>
                </tr>
                @if($booking->notes)
                <tr>
                    <td colspan="3" style="font-size: 12px; color: #666;">
                        <strong>Notes:</strong> {{ $booking->notes }}
                    </td>
                </tr>
                @endif
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2" style="text-align: right;">TOTAL PAID:</td>
                    <td style="text-align: right;">₱{{ number_format($booking->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="footer">
        <p><strong>Thank you for choosing {{ $company_name }}!</strong></p>
        <p>Please arrive 15 minutes before your scheduled lesson time.</p>
        <p style="font-size: 12px; margin-top: 20px;">
            This is a computer-generated receipt and does not require a signature.<br>
            For questions or concerns, please contact us at {{ $company_email }}
        </p>
    </div>
</body>
</html>
