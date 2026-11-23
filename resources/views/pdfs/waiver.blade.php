<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $waiver_title }} - {{ $waiver_id ?? ('SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)) }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 9mm 12mm;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            line-height: 1.28;
            color: #111827;
            margin: 0;
            padding: 0;
        }
        .sheet {
            width: 100%;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            border-bottom: 1.5px solid #111827;
        }
        .header-logo-cell {
            width: 40px;
            vertical-align: middle;
            padding-bottom: 4px;
        }
        .header-logo {
            width: 34px;
            height: 34px;
            object-fit: contain;
        }
        .header-title-cell {
            vertical-align: middle;
            text-align: center;
            padding-bottom: 4px;
            padding-right: 40px;
        }
        .doc-title {
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.3px;
            line-height: 1.2;
            margin: 0;
            color: #111827;
        }
        .fields-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
        }
        .fields-table td {
            vertical-align: bottom;
            padding: 2px 4px 2px 0;
            font-size: 8.3pt;
        }
        .field-label {
            width: 1%;
            font-weight: bold;
            white-space: nowrap;
            padding-right: 4px;
        }
        .field-value {
            border-bottom: 1px solid #111827;
            padding: 0 4px 1px 4px;
            font-weight: normal;
            color: #111827;
        }
        .intro-block {
            border-top: 1px solid #111827;
            padding-top: 5px;
            margin-top: 5px;
            margin-bottom: 6px;
            font-size: 8.3pt;
            line-height: 1.32;
        }
        .inline-blank {
            display: inline-block;
            border-bottom: 1px solid #111827;
            padding: 0 10px 1px 10px;
            font-weight: bold;
            min-width: 110px;
            text-align: center;
        }
        .clauses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 7px;
        }
        .clauses-table td {
            vertical-align: top;
            padding-bottom: 3px;
            font-size: 7.9pt;
            line-height: 1.26;
            text-align: justify;
        }
        .clause-num {
            width: 16px;
            font-weight: bold;
            text-align: left !important;
        }
        .clause-heading {
            font-weight: bold;
        }
        .medical-info-line {
            margin-top: 2px;
            padding: 2px 5px;
            border-bottom: 1px solid #111827;
            background-color: #f9fafb;
            font-size: 7.8pt;
            text-align: left;
        }
        .sig-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .sig-table td {
            vertical-align: bottom;
            padding: 3px 6px 2px 0;
            font-size: 8.3pt;
        }
        .sig-label {
            white-space: nowrap;
            font-weight: bold;
            width: 1%;
        }
        .sig-line {
            border-bottom: 1px solid #111827;
            text-align: center;
            padding: 0 6px 2px 6px;
        }
        .sig-image-wrap {
            height: 36px;
            vertical-align: bottom;
            text-align: center;
        }
        .sig-image {
            max-height: 34px;
            max-width: 190px;
            vertical-align: bottom;
        }
        .sig-subnote {
            font-size: 7.3pt;
            color: #374151;
            text-align: center;
            padding-top: 1px;
        }
        .sig-note {
            white-space: nowrap;
            font-size: 7.6pt;
            color: #374151;
            width: 1%;
            padding-left: 4px;
        }
        .meta-footer {
            margin-top: 7px;
            padding-top: 3px;
            border-top: 1px solid #d1d5db;
            font-size: 7.2pt;
            color: #6b7280;
            width: 100%;
        }
        .meta-footer td {
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="sheet">
        <!-- Header -->
        <table class="header-table">
            <tr>
                <td class="header-logo-cell">
                    @if(!empty($logo_data_uri))
                        <img src="{{ $logo_data_uri }}" alt="Logo" class="header-logo">
                    @endif
                </td>
                <td class="header-title-cell">
                    <div class="doc-title">{{ $waiver_title }}</div>
                </td>
            </tr>
        </table>

        <!-- Participant Info Row 1: Name, Birthday, Age, Email -->
        <table class="fields-table">
            <tr>
                <td class="field-label">Name:</td>
                <td class="field-value" style="width: 27%;">{{ !empty($participant_name) ? $participant_name : (!empty($is_preview) ? "\u{00A0}" : 'N/A') }}</td>
                <td class="field-label" style="padding-left: 8px;">Birthday:</td>
                <td class="field-value" style="width: 23%;">{{ !empty($birthday_formatted) ? $birthday_formatted : (!empty($is_preview) ? "\u{00A0}" : 'N/A') }}</td>
                <td class="field-label" style="padding-left: 8px;">Age:</td>
                <td class="field-value" style="width: 7%; text-align: center;">{{ ($age !== null && $age !== '') ? $age : (!empty($is_preview) ? "\u{00A0}" : 'N/A') }}</td>
                <td class="field-label" style="padding-left: 8px;">Email:</td>
                <td class="field-value" style="width: 37%;">{{ !empty($email) ? $email : (!empty($is_preview) ? "\u{00A0}" : 'N/A') }}</td>
            </tr>
        </table>

        <!-- Participant Info Row 2: Country, Conditional Hotel, Emergency Contact -->
        @if($needs_hotel_pickup)
            <table class="fields-table">
                <tr>
                    <td class="field-label">Country:</td>
                    <td class="field-value" style="width: 40%;">{{ !empty($country) ? $country : (!empty($is_preview) ? "\u{00A0}" : 'N/A') }}</td>
                    <td class="field-label" style="padding-left: 10px;">Hotel:</td>
                    <td class="field-value" style="width: 55%;">{{ !empty($hotel_name) ? $hotel_name : "\u{00A0}" }}</td>
                </tr>
            </table>
            <table class="fields-table">
                <tr>
                    <td class="field-label">In Case Of Emergency Name &amp; Number:</td>
                    <td class="field-value">{{ !empty($emergency_contact) ? $emergency_contact : "\u{00A0}" }}</td>
                </tr>
            </table>
        @else
            <table class="fields-table">
                <tr>
                    <td class="field-label">Country:</td>
                    <td class="field-value" style="width: 28%;">{{ !empty($country) ? $country : (!empty($is_preview) ? "\u{00A0}" : 'N/A') }}</td>
                    <td class="field-label" style="padding-left: 10px;">In Case Of Emergency Name &amp; Number:</td>
                    <td class="field-value">{{ !empty($emergency_contact) ? $emergency_contact : "\u{00A0}" }}</td>
                </tr>
            </table>
        @endif

        <!-- Intro Undertaking -->
        <div class="intro-block">
            By fully volunteering myself to take a surf lesson from (surf shop / resort)
            <span class="inline-blank">{{ $surf_shop }}</span>
            with SISA certified instructor
            <span class="inline-blank">{{ $instructor_name }}</span>,
            I have read, understood, approved, and agree to the following terms and conditions:
        </div>

        <!-- Numbered Legal Clauses 1-7 -->
        <table class="clauses-table">
            @foreach($clauses as $clause)
                <tr>
                    <td class="clause-num">{{ $clause['number'] }}.</td>
                    <td>
                        <span class="clause-heading">{{ $clause['heading'] }}</span>
                        {{ $clause['body'] }}
                        @if($clause['number'] === 1)
                            <div class="medical-info-line">
                                <span class="clause-heading">Medical Info / Allergies or Conditions:</span>
                                @if(!empty($medical_info))
                                    {{ $medical_info }}
                                @elseif(!empty($is_preview))
                                    &nbsp;
                                @else
                                    None declared
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>

        <!-- Bottom Signature Block -->
        <table class="sig-table">
            <tr>
                <td class="sig-label">Printed Name</td>
                <td class="sig-line" style="width: 36%;">{{ !empty($signed_by) ? $signed_by : "\u{00A0}" }}</td>
                <td class="sig-label" style="padding-left: 14px;">Signature</td>
                <td class="sig-line sig-image-wrap" style="width: 46%;">
                    @if(!empty($signature_image))
                        <img src="{{ $signature_image }}" alt="Signature" class="sig-image">
                    @else
                        &nbsp;
                    @endif
                </td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td class="sig-subnote" style="padding: 2px 0 0 0;">(Parent's / Guardian's Signature if under 18)</td>
            </tr>
            <tr>
                <td class="sig-label" style="padding-top: 8px;">Date</td>
                <td class="sig-line" style="padding-top: 8px;">{{ $signed_at }}</td>
                <td class="sig-label" style="padding-left: 14px; padding-top: 8px;">In the presence of</td>
                <td style="padding: 8px 0 0 0;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td class="sig-line" style="padding-bottom: 2px;">{{ $witness_name }}</td>
                            <td class="sig-note" style="padding-bottom: 2px;">(Receptionist)/Staff</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Audit / Retention Footer -->
        <table class="meta-footer">
            <tr>
                @if(!empty($is_preview))
                    <td style="text-align: left;">
                        Booking #SURF-{{ str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT) }} &bull; Spot: {{ $booking->surfSpot?->name ?? 'TBD' }}@if(!empty($lesson_date)) &bull; {{ $lesson_date }}@endif
                    </td>
                    <td style="text-align: right;">
                        SiaSurf / SISA Official Waiver
                    </td>
                @else
                    <td style="text-align: left;">
                        Waiver ID: {{ $waiver_id }} &bull; Booking #SURF-{{ str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT) }} &bull; Signed: {{ $signed_at_datetime }}
                    </td>
                    <td style="text-align: right;">
                        Retained electronically for 7 years (until {{ $retention_until }})
                    </td>
                @endif
            </tr>
        </table>
    </div>
</body>
</html>
