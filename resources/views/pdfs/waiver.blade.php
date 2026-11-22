<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $waiver_title }} - {{ $waiver_id }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 12mm;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1.32;
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
            margin-bottom: 8px;
            border-bottom: 1.5px solid #111827;
        }
        .header-logo-cell {
            width: 48px;
            vertical-align: middle;
            padding-bottom: 6px;
        }
        .header-logo {
            width: 38px;
            height: 38px;
            object-fit: contain;
        }
        .header-title-cell {
            vertical-align: bottom;
            text-align: center;
            padding-bottom: 6px;
            padding-right: 48px;
        }
        .doc-title {
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 0.4px;
            margin: 0;
            color: #111827;
        }
        .fields-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        .fields-table td {
            vertical-align: bottom;
            padding: 3px 4px 3px 0;
            font-size: 8.8pt;
        }
        .field-label {
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
            padding-top: 7px;
            margin-top: 6px;
            margin-bottom: 8px;
            font-size: 8.8pt;
            line-height: 1.4;
        }
        .inline-blank {
            display: inline-block;
            border-bottom: 1px solid #111827;
            padding: 0 12px 1px 12px;
            font-weight: bold;
            min-width: 130px;
            text-align: center;
        }
        .clauses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .clauses-table td {
            vertical-align: top;
            padding-bottom: 4px;
            font-size: 8.2pt;
            line-height: 1.3;
            text-align: justify;
        }
        .clause-num {
            width: 18px;
            font-weight: bold;
            text-align: left !important;
        }
        .clause-heading {
            font-weight: bold;
        }
        .medical-info-line {
            margin-top: 3px;
            padding: 2px 5px;
            border-bottom: 1px solid #111827;
            background-color: #f9fafb;
            font-size: 8pt;
            text-align: left;
        }
        .sig-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .sig-table td {
            vertical-align: bottom;
            padding: 4px 6px 2px 0;
            font-size: 8.8pt;
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
            height: 44px;
            vertical-align: bottom;
            text-align: center;
        }
        .sig-image {
            max-height: 40px;
            max-width: 210px;
            vertical-align: bottom;
        }
        .sig-note {
            white-space: nowrap;
            font-size: 8.2pt;
            color: #374151;
            width: 1%;
            padding-left: 4px;
        }
        .meta-footer {
            margin-top: 10px;
            padding-top: 4px;
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
                <td class="field-label" style="width: 5%;">Name:</td>
                <td class="field-value" style="width: 31%;">{{ $participant_name }}</td>
                <td class="field-label" style="width: 7%; padding-left: 10px;">Birthday:</td>
                <td class="field-value" style="width: 17%;">{{ $birthday_formatted ?: 'N/A' }}</td>
                <td class="field-label" style="width: 4%; padding-left: 10px;">Age:</td>
                <td class="field-value" style="width: 6%; text-align: center;">{{ $age }}</td>
                <td class="field-label" style="width: 5%; padding-left: 10px;">Email:</td>
                <td class="field-value" style="width: 25%;">{{ $email ?: 'N/A' }}</td>
            </tr>
        </table>

        <!-- Participant Info Row 2: Country, Conditional Hotel, Emergency Contact -->
        <table class="fields-table">
            <tr>
                <td class="field-label" style="width: 7%;">Country:</td>
                @if($needs_hotel_pickup)
                    <td class="field-value" style="width: 18%;">{{ $country ?: 'N/A' }}</td>
                    <td class="field-label" style="width: 5%; padding-left: 10px;">Hotel:</td>
                    <td class="field-value" style="width: 22%;">{{ $hotel_name }}</td>
                    <td class="field-label" style="width: 25%; padding-left: 10px;">In Case Of Emergency Name &amp; Number:</td>
                    <td class="field-value" style="width: 23%;">{{ $emergency_contact }}</td>
                @else
                    <td class="field-value" style="width: 28%;">{{ $country ?: 'N/A' }}</td>
                    <td class="field-label" style="width: 26%; padding-left: 14px;">In Case Of Emergency Name &amp; Number:</td>
                    <td class="field-value" style="width: 39%;">{{ $emergency_contact }}</td>
                @endif
            </tr>
        </table>

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
                                {{ !empty($medical_info) ? $medical_info : 'None declared' }}
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
                <td class="sig-line" style="width: 28%;">{{ $signed_by }}</td>
                <td class="sig-label" style="padding-left: 18px;">Signature</td>
                <td class="sig-line sig-image-wrap" style="width: 30%;">
                    @if(!empty($signature_image))
                        <img src="{{ $signature_image }}" alt="Signature" class="sig-image">
                    @endif
                </td>
                <td class="sig-note">(Parent's / Guardian's Signature if under 18)</td>
            </tr>
            <tr>
                <td class="sig-label" style="padding-top: 12px;">Date</td>
                <td class="sig-line" style="padding-top: 12px;">{{ $signed_at }}</td>
                <td class="sig-label" style="padding-left: 18px; padding-top: 12px;">In the presence of</td>
                <td class="sig-line" style="padding-top: 12px;">{{ $witness_name }}</td>
                <td class="sig-note" style="padding-top: 12px;">(Receptionist)/Staff</td>
            </tr>
        </table>

        <!-- Audit / Retention Footer -->
        <table class="meta-footer">
            <tr>
                <td style="text-align: left;">
                    Waiver ID: {{ $waiver_id }} &bull; Booking #SURF-{{ str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT) }} &bull; Signed: {{ $signed_at_datetime }}
                </td>
                <td style="text-align: right;">
                    Retained electronically for 7 years (until {{ $retention_until }})
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
