<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Parental Consent Form - {{ $waiver_id }}</title>
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
        .minor-notice {
            background: #DBEAFE;
            border: 2px solid #3B82F6;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .minor-notice h3 {
            color: #1E40AF;
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
        .info-section {
            background: #f9fafb;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
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
        .emergency-box {
            background: #FEF2F2;
            border: 2px solid #EF4444;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .emergency-box h3 {
            color: #991B1B;
            margin-top: 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company_name }} - PARENTAL CONSENT FORM</h1>
        <p style="font-size: 14px; color: #666;">Consent ID: {{ $waiver_id }}</p>
    </div>

    <div class="minor-notice">
        <h3>⚠️ MINOR PARTICIPANT</h3>
        <p>This consent form is required because the participant is under 18 years of age. A parent or legal guardian must sign this document to authorize the minor's participation in surfing activities.</p>
    </div>

    <div class="info-section">
        <h2>PARTICIPANT INFORMATION</h2>
        <div class="info-grid">
            <div>
                <div class="info-item">
                    <strong>Minor's Name:</strong> {{ $booking->student->name }}
                </div>
                <div class="info-item">
                    <strong>Minor's Age:</strong> {{ $booking->student_age }} years old
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
                    <strong>Skill Level:</strong> {{ ucfirst($booking->skill_level instanceof \App\Enums\SkillLevel ? $booking->skill_level->value : $booking->skill_level) }}
                </div>
            </div>
        </div>
    </div>

    <div class="emergency-box">
        <h3>🚨 EMERGENCY CONTACT INFORMATION</h3>
        <div class="info-grid">
            <div>
                <div class="info-item">
                    <strong>Emergency Contact Name:</strong> {{ $emergency_contact }}
                </div>
                <div class="info-item">
                    <strong>Emergency Phone:</strong> {{ $emergency_phone }}
                </div>
            </div>
        </div>
        @if($medical_info)
        <div style="margin-top: 15px;">
            <strong>Medical Information/Allergies:</strong><br>
            {{ $medical_info }}
        </div>
        @endif
    </div>

    <div class="content">
        <h2>PARENT/GUARDIAN CONSENT</h2>
        
        <p>I, {{ $parent_name }}, being the parent or legal guardian of {{ $booking->student->name }}, hereby grant permission for my minor child to participate in surfing instruction and related water activities provided by {{ $company_name }}.</p>

        <h2>ACKNOWLEDGMENT OF RISKS</h2>
        
        <p>I understand and acknowledge that surfing involves significant risks and hazards including, but not limited to:</p>
        
        <ul>
            <li>Drowning or near-drowning incidents</li>
            <li>Impact injuries from surfboards</li>
            <li>Collisions with other surfers or marine life</li>
            <li>Injuries from underwater obstacles</li>
            <li>Wave-related injuries</li>
            <li>Sunburn and dehydration</li>
            <li>Weather and ocean condition changes</li>
        </ul>

        <p>I understand these risks are particularly significant for minors and I have explained these risks to my child in an age-appropriate manner.</p>

        <h2>RELEASE AND INDEMNIFICATION</h2>
        
        <p>In consideration for allowing my minor child to participate in surfing activities:</p>

        <p><strong>1.</strong> I voluntarily assume all risks associated with my child's participation, whether or not described above, even if arising from the negligence of {{ $company_name }}, its instructors, or agents.</p>

        <p><strong>2.</strong> I release, waive, discharge, and covenant not to sue {{ $company_name }}, its instructors, agents, employees, officers, directors, and volunteers from any and all liability, claims, demands, actions, and causes of action arising out of or related to any loss, damage, injury, or death sustained by my minor child.</p>

        <p><strong>3.</strong> I agree to indemnify, defend, and hold harmless {{ $company_name }} from any loss, liability, damage, or cost they may incur due to my child's participation.</p>

        <p><strong>4.</strong> I authorize {{ $company_name }} instructors to act on my behalf in any emergency requiring medical attention for my child, including but not limited to calling emergency services, administering first aid, or transporting my child to a medical facility.</p>

        <h2>MEDICAL AUTHORIZATION</h2>
        
        <p>I certify that my child is physically and mentally able to participate in surfing activities. I consent to emergency medical treatment for my child if necessary. I understand that I will be responsible for all medical expenses incurred.</p>

        @if($medical_info)
        <p>I have disclosed the following medical information that instructors should be aware of: {{ $medical_info }}</p>
        @endif

        <h2>PHOTO/VIDEO RELEASE</h2>
        
        <p>I grant {{ $company_name }} permission to use photographs or video recordings of my child taken during surfing activities for promotional purposes.</p>

        <h2>ACKNOWLEDGMENT</h2>
        
        <p>I have read this Parental Consent Form carefully and fully understand its contents. I am aware that this is a release of liability and a contract between myself and {{ $company_name }}, and I sign it of my own free will.</p>
    </div>

    <div class="signature-box">
        <div class="info-grid">
            <div>
                <p><strong>Parent/Guardian Signature:</strong></p>
                <div class="signature-line">
                    {{ $parent_name }}
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
        <p>This Parental Consent Form is legally binding and governed by the laws of the Philippines.</p>
        <p>Retention Period: 7 years from signing date (until {{ now()->addYears(7)->format('F d, Y') }})</p>
        <p style="margin-top: 10px;">Questions? Contact us at {{ config('app.admin_email', 'admin@siasurf.com') }}</p>
    </div>
</body>
</html>
