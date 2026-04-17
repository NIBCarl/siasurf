<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Liability Waiver - {{ $waiver_id }}</title>
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
            font-size: 28px;
        }
        .warning-box {
            background: #FEF3C7;
            border: 2px solid #F59E0B;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .warning-box h3 {
            color: #92400E;
            margin-top: 0;
        }
        .content {
            text-align: justify;
        }
        .content h2 {
            color: #0E7490;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        .signature-box {
            margin-top: 40px;
            padding: 20px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 60px;
            padding-top: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-item strong {
            color: #0E7490;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        li {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company_name }} - LIABILITY WAIVER</h1>
        <p style="font-size: 14px; color: #666;">Waiver ID: {{ $waiver_id }}</p>
    </div>

    <div class="warning-box">
        <h3>⚠️ IMPORTANT NOTICE</h3>
        <p>Surfing is an inherently dangerous activity that involves significant risks including, but not limited to, drowning, collision with surfboards, marine life encounters, and injuries from waves and underwater obstacles. By signing this waiver, you acknowledge and accept these risks.</p>
    </div>

    <div class="info-grid">
        <div>
            <div class="info-item">
                <strong>Student Name:</strong> {{ $booking->student->name }}
            </div>
            <div class="info-item">
                <strong>Student Age:</strong> {{ $booking->student_age }}
            </div>
            <div class="info-item">
                <strong>Booking Date:</strong> {{ $booking->date->format('F d, Y') }}
            </div>
        </div>
        <div>
            <div class="info-item">
                <strong>Instructor:</strong> {{ $booking->instructor->name }}
            </div>
            <div class="info-item">
                <strong>Location:</strong> {{ $booking->surfSpot->name }}
            </div>
            <div class="info-item">
                <strong>Signed Date:</strong> {{ $signed_at_datetime }}
            </div>
        </div>
    </div>

    <div class="content">
        <h2>ASSUMPTION OF RISK</h2>
        <p>I, {{ $signed_by }}, understand and acknowledge that surfing and related water activities involve inherent risks, dangers, and hazards that can result in serious injury or death. These risks include, but are not limited to:</p>
        
        <ul>
            <li>Drowning or near-drowning incidents</li>
            <li>Impact injuries from surfboards (my own or others)</li>
            <li>Collisions with other surfers, swimmers, or marine life</li>
            <li>Injuries from underwater rocks, reefs, or sandbars</li>
            <li>Wave-related injuries including hold-downs and impact with the ocean floor</li>
            <li>Marine life encounters (jellyfish stings, sea urchins, etc.)</li>
            <li>Sunburn, dehydration, and exhaustion</li>
            <li>Weather and ocean condition changes</li>
        </ul>

        <h2>RELEASE OF LIABILITY</h2>
        
        <p>In consideration of being permitted to participate in surfing instruction and related activities provided by {{ $company_name }} and its instructors, I hereby:</p>
        
        <p><strong>1.</strong> Assume all risks associated with surfing activities, whether or not described above, even if arising from the negligence of the instructors, {{ $company_name }}, or their respective agents, employees, or representatives.</p>
        
        <p><strong>2.</strong> Release, waive, discharge, and covenant not to sue {{ $company_name }}, its instructors, agents, employees, officers, directors, and volunteers from any and all liability, claims, demands, actions, and causes of action whatsoever, directly or indirectly arising out of or related to any loss, damage, injury, or death, that may be sustained by me while participating in surfing activities.</p>
        
        <p><strong>3.</strong> Agree to indemnify, defend, and hold harmless {{ $company_name }} and its instructors from any loss, liability, damage, or cost they may incur due to my participation in surfing activities, whether caused by negligence or otherwise.</p>

        <h2>MEDICAL CONSENT</h2>
        
        <p>I certify that I am physically fit and have not been advised otherwise by a qualified medical professional. I have no physical or medical conditions that would endanger myself or others during surfing activities. I consent to receive emergency medical treatment if necessary and agree to pay all associated costs.</p>

        <h2>PHOTO/VIDEO RELEASE</h2>
        
        <p>I grant {{ $company_name }} permission to use photographs or video recordings of me taken during surfing activities for promotional purposes, including but not limited to social media, website content, and marketing materials.</p>

        <h2>ACKNOWLEDGMENT</h2>
        
        <p>I have read this waiver carefully, fully understand its contents, and sign it voluntarily. I understand that by signing this document, I am giving up substantial rights, including my right to sue. I acknowledge that I am signing this agreement with the intention of affecting a legal and binding change in my legal rights.</p>
    </div>

    <div class="signature-box">
        <div class="info-grid">
            <div>
                <p><strong>Participant Signature:</strong></p>
                <div class="signature-line">
                    {{ $signed_by }}
                </div>
            </div>
            <div>
                <p><strong>Date Signed:</strong></p>
                <div class="signature-line">
                    {{ $signed_at }}
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>This waiver is legally binding and shall be governed by the laws of the Philippines.</p>
        <p>Retention Period: 7 years from signing date (until {{ now()->addYears(7)->format('F d, Y') }})</p>
        <p style="margin-top: 10px;">If you have any questions about this waiver, please contact us before signing.</p>
    </div>
</body>
</html>
