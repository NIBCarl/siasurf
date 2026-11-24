<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | SiaSurf publishes a standard hourly rate band for surf instruction. Both
    | bounds are enforced server-side on every write path (instructor onboarding
    | and the two SISA instructor forms) and mirrored into the UI so the browser
    | can warn before a request is even sent.
    |
    | Without a ceiling, an input like 10000000000 passed validation (there was
    | only a `min`) and then hit `decimal(10,2)`, producing a raw
    | SQLSTATE[22003] 500 error instead of a validation message.
    |
    | Raise `max_rate_per_hour` here if a Level 3 / ISA instructor needs a custom
    | premium rate — it is the single knob, and the UI follows automatically.
    |
    */

    'pricing' => [
        'min_rate_per_hour' => env('SIASURF_MIN_RATE', 600),
        'max_rate_per_hour' => env('SIASURF_MAX_RATE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service-Based Pricing Bands
    |--------------------------------------------------------------------------
    |
    | Rates are governed by the service provided (Teaching, Guiding, Coaching)
    | rather than instructor level, so a beginner booking a Level 3 instructor
    | for Teaching still pays the Teaching rate. Instructors may choose any
    | hourly rate within each authorized service's published range.
    |
    */

    'services' => [
        'teaching' => [
            'min_rate' => (float) env('SIASURF_TEACHING_MIN_RATE', 600),
            'max_rate' => (float) env('SIASURF_TEACHING_MAX_RATE', 1000),
            'default_rate' => (float) env('SIASURF_TEACHING_DEFAULT_RATE', 600),
        ],
        'guiding' => [
            'min_rate' => (float) env('SIASURF_GUIDING_MIN_RATE', 800),
            'max_rate' => (float) env('SIASURF_GUIDING_MAX_RATE', 1500),
            'default_rate' => (float) env('SIASURF_GUIDING_DEFAULT_RATE', 800),
        ],
        'coaching' => [
            'min_rate' => (float) env('SIASURF_COACHING_MIN_RATE', 1500),
            'max_rate' => (float) env('SIASURF_COACHING_MAX_RATE', 2000),
            'default_rate' => (float) env('SIASURF_COACHING_DEFAULT_RATE', 1500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Length
    |--------------------------------------------------------------------------
    |
    | The methodology defines a standard surf lesson of 2 hours ("default 2
    | hours", "₱1,200 for standard 2-hour package"). This is the single source
    | of truth for how long a standard booking runs; pricing, the created
    | booking's end time, the live session timer and the UI estimate all read
    | from here. Instructors can still publish shorter/longer slot durations on
    | their availability, and the booking follows the slot it was booked into.
    |
    */

    'session' => [
        'standard_hours' => env('SIASURF_STANDARD_SESSION_HOURS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking Horizon
    |--------------------------------------------------------------------------
    |
    | How far ahead a student may browse and book. Without a bound the booking
    | calendar would happily render availability indefinitely into the future
    | against a weekly pattern that is only meaningful for the near term.
    |
    */

    'booking' => [
        'horizon_days' => (int) env('SIASURF_BOOKING_HORIZON_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Service Fee (BR-PRC-03)
    |--------------------------------------------------------------------------
    |
    | Charged on top of the instructor's lesson price (7% by default). Never
    | deducted from the instructor's earnings and never refunded on
    | cancellation.
    |
    */

    'fees' => [
        'service_fee_percent' => (float) env('SIASURF_SERVICE_FEE_PERCENT', 7.0),
        'cash_remittance_days' => (int) env('SIASURF_CASH_REMITTANCE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cancellation & Tiered Refund Policy (BR-CXL-01 to BR-CXL-10)
    |--------------------------------------------------------------------------
    |
    | Long-term bookings (> 30 days ahead at booking time) allow free 100%
    | lesson-price cancellation within 30 days of payment; after 30 days they
    | fall through to the short-term sliding window (7+ days: 100%, 3-6 days:
    | 50%, < 3 days / same day: 0%). Refunds beyond PayMongo's 30-day window
    | are routed to manual SISA completion.
    |
    */

    'refunds' => [
        'long_term_threshold_days' => (int) env('SIASURF_REFUND_LONG_TERM_THRESHOLD_DAYS', 30),
        'long_term_window_days' => (int) env('SIASURF_REFUND_LONG_TERM_WINDOW_DAYS', 30),
        'max_days_from_payment' => (int) env('SIASURF_REFUND_MAX_DAYS_FROM_PAYMENT', 30),
        'short_term_full_days' => (int) env('SIASURF_REFUND_SHORT_TERM_FULL_DAYS', 7),
        'short_term_half_days' => (int) env('SIASURF_REFUND_SHORT_TERM_HALF_DAYS', 3),
        'short_term_half_percent' => (int) env('SIASURF_REFUND_SHORT_TERM_HALF_PERCENT', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slot capacity per instructor level
    |--------------------------------------------------------------------------
    |
    | How many students may share ONE time slot. A single booking can never
    | exceed the instructor's certified group size (BR-3.1/3.2/3.3), but this
    | value decides how many separate bookings may overlap the same hours.
    |
    | Defaults follow the methodology:
    |   Level 1 -> 1 (beginners only, 1-on-1, slot held from the first request)
    |   Level 2 -> 2 (up to 2 students)
    |   Level 3 -> 5 (group sessions up to 5 students)
    |
    | Raise Level 2/3 here only if SISA changes the policy. SafetyService still
    | enforces the per-booking ceiling.
    |
    */

    'instructor_capacity' => [
        1 => (int) env('SIASURF_LEVEL1_CAPACITY', 1),
        2 => (int) env('SIASURF_LEVEL2_CAPACITY', 2),
        3 => (int) env('SIASURF_LEVEL3_CAPACITY', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feedback, Strikes, and Suspensions
    |--------------------------------------------------------------------------
    |
    | Student concerns start pending and require SISA confirmation. These
    | values keep the threshold and penalty in one place instead of scattering
    | magic numbers through services and tests.
    |
    */

    'feedback' => [
        'auto_confirm_reports' => env('SIASURF_AUTO_CONFIRM_REPORTS', false),
        'standard_review_hours' => env('SIASURF_STANDARD_REVIEW_HOURS', 24),
        'critical_review_hours' => env('SIASURF_CRITICAL_REVIEW_HOURS', 4),
    ],

    'strikes' => [
        'fine_amount' => (float) env('SIASURF_STRIKE_FINE_AMOUNT', 500),
        'tier2_threshold' => 2,
        'tier2_suspension_days' => (int) env('SIASURF_TIER2_SUSPENSION_DAYS', 7),
        'tier3_threshold' => (int) env('SIASURF_STRIKE_THRESHOLD', 3),
        'tier3_suspension_days' => (int) env('SIASURF_SUSPENSION_DAYS', 30),
        'threshold' => env('SIASURF_STRIKE_THRESHOLD', 3),
        'suspension_days' => env('SIASURF_SUSPENSION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | QR Codes
    |--------------------------------------------------------------------------
    |
    | Instructor QR codes encode an absolute URL, so the host baked into the
    | image must be reachable from a phone on the beach — not the developer's
    | localhost. Set SIASURF_PUBLIC_URL to the LAN address or the real domain.
    |
    | Falls back to route() (i.e. APP_URL) when unset, which preserves the old
    | behaviour rather than breaking environments that are already reachable.
    |
    */

    'qr' => [
        'public_url' => env('SIASURF_PUBLIC_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Student Skill Pre-Assessment
    |--------------------------------------------------------------------------
    |
    | Initial student level is derived from this pre-assessment rather than an
    | unvalidated dropdown at signup. Answer keys stay strictly server-side;
    | SkillAssessmentService strips `correct_index` before sharing the form
    | definition with the frontend.
    |
    */

    'skill_assessment' => [
        'thresholds' => [
            'intermediate_skills_required' => 4,
            'advanced_skills_required' => 4,
            'intermediate_knowledge_required' => 3,
            'advanced_knowledge_required' => 4,
        ],
        'skills' => [
            'basic' => [
                'I can paddle a board on my own.',
                'I can stand up on small, broken (white-water) waves.',
            ],
            'intermediate' => [
                'I can paddle out past the breaking waves on my own.',
                'I can catch unbroken (green) waves.',
                'I can stand up in one motion and ride along the wave.',
                'I can turn left and right while riding.',
                'I can surf for about an hour without a long rest.',
            ],
            'advanced' => [
                'I surf regularly without an instructor.',
                'I can surf waves taller than me with control.',
                'I can make controlled turns and change direction quickly.',
                'I can surf reef breaks and crowded lineups.',
                'I can surf for two hours or more.',
            ],
        ],
        'questions' => [
            [
                'key' => 'water_safety',
                'topic' => 'Water safety',
                'question' => 'You’re caught in a rip current. What should you do?',
                'options' => [
                    'Swim straight back to shore as fast as you can',
                    'Stay calm and swim parallel to the shore',
                    'Stay still and wait for it to stop',
                    'Swim against the current until it weakens',
                ],
                'correct_index' => 1,
                'required' => true,
            ],
            [
                'key' => 'surf_etiquette',
                'topic' => 'Surf etiquette',
                'question' => 'Two surfers are riding the same wave. Who has priority?',
                'options' => [
                    'The surfer who paddled out first',
                    'The surfer closest to the breaking part of the wave',
                    'The surfer with the longer board',
                    'The surfer closer to the shore',
                ],
                'correct_index' => 1,
                'required' => false,
            ],
            [
                'key' => 'equipment',
                'topic' => 'Equipment',
                'question' => 'What is a leash for?',
                'options' => [
                    'It keeps the board attached to you',
                    'It helps you paddle faster',
                    'It protects the fins',
                    'It keeps the board waxed',
                ],
                'correct_index' => 0,
                'required' => false,
            ],
            [
                'key' => 'surfing_spots',
                'topic' => 'Surfing spots',
                'question' => 'Why does the tide matter at a reef break?',
                'options' => [
                    'It changes the water color',
                    'The reef can be shallow at low tide',
                    'Waves stop at high tide',
                    'It changes the wind direction',
                ],
                'correct_index' => 1,
                'required' => false,
            ],
        ],
    ],

];
