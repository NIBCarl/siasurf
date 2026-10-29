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
        'max_rate_per_hour' => env('SIASURF_MAX_RATE', 1500),
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
    | Slot capacity per instructor level
    |--------------------------------------------------------------------------
    |
    | How many students may share ONE time slot. A single booking can never
    | exceed the instructor's certified group size (BR-3.1/3.2/3.3), but this
    | value decides how many separate bookings may overlap the same hours.
    |
    | Defaults follow the methodology:
    |   Level 1 -> 2 (beginners only, slot held from the first request)
    |   Level 2 -> 1 (1-on-1 only)
    |   Level 3 -> 5 (group sessions)
    |
    | Raise Level 2/3 here only if SISA changes the policy. SafetyService still
    | enforces the per-booking ceiling, so a Level 2 instructor can never run a
    | multi-student booking even if the slot allows several separate ones.
    |
    */

    'instructor_capacity' => [
        1 => (int) env('SIASURF_LEVEL1_CAPACITY', 2),
        2 => (int) env('SIASURF_LEVEL2_CAPACITY', 1),
        3 => (int) env('SIASURF_LEVEL3_CAPACITY', 5),
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
        'threshold' => env('SIASURF_STRIKE_THRESHOLD', 3),
        'suspension_days' => env('SIASURF_SUSPENSION_DAYS', 30),
    ],

];
