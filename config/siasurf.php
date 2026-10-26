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

];
