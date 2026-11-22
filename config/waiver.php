<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SISA Surf Lesson Waiver, Liability, and Indemnity Agreement
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the official SISA waiver wording and defaults
    | shared by both the Inertia Waiver signing page and the generated DomPDF
    | document so they can never drift out of sync.
    |
    */

    'title' => 'SURF LESSON WAIVER, LIABILITY, AND INDEMNITY AGREEMENT',

    'surf_shop' => env('SIASURF_WAIVER_SHOP', 'SiaSurf'),

    'logo_path' => 'images/logo.png',

    'clauses' => [
        [
            'number' => 1,
            'heading' => 'PERSONAL HEALTH:',
            'body' => 'I am in good physical and mental health. I am not under the influence of any drugs, alcohol, or other substances which may impair my ability to participate in this surf lesson. None of the following medical conditions apply to me: asthma - epilepsy - spinal / skeletal defect - pregnancy - heart conditions - a previous injury that may reoccur or be triggered by surfing, swimming, & all activities related to taking a surf lesson.',
        ],
        [
            'number' => 2,
            'heading' => 'RISKS AND DANGERS TO PERSON & PROPERTY:',
            'body' => 'I fully understand and accept that surfing is an outdoor water activity in an uncontrolled natural environment with inherent risks and hazards. Serious accidents can occur, participants can sustain property damage, injuries, illness, and / or even die. Knowing the risks involved, I still wish to register for this surf lesson / lessons. I acknowledge & willingly assume all risks and hazards in surf / beach related activities in all phases of this surf lesson from the training sessions until the conclusion of the class, lesson, or equipment rental period, including, but not limited to those involving, (1) loss of control of the surfboard or other equipment, falls from the surfboard, collision with other participants, equipment, other surfboards, rocks, and any other man-made or natural obstacles, whether obvious or not; (2) submersion in water, drowning; (3) encounters with animals, marine life, plant life, rocks and insects; (4) exposure to outdoor / ocean environment, bacteria or microbes, extreme temperatures, sun, and inclement weather, including travel by foot or vehicle in any way related to this activity; (5) assistance in lifting and/or carrying surfing equipment; (6) rescue-related injuries; and (7) unavailability of immediate and appropriate medical attention in case of injury. I understand and acknowledge that the above list is not meant to be complete, & other unforeseen and unanticipated risks, known or unknown, may also exist. I release from all liabilities and waive my right to sue SISA, my surf instructor, this surf school / resort, its officers, agents and/or employees from all claims, losses, damages or expenses during or in conjunction with my participation in this surf lesson.',
        ],
        [
            'number' => 3,
            'heading' => 'RULES AND SURF ETIQUETTE.',
            'body' => 'I have read and familiarized myself with the surf rules, etiquette, and places where I may participate in surf lessons. I release, waive and hold harmless to all liabilities SISA, this surf school, its officers, agents and/or employees from all claims, losses, damages or expenses during or in conjunction with my participation in this surf lesson including any claims for damage caused by my own negligence of such rules.',
        ],
        [
            'number' => 4,
            'heading' => 'RESPONSIBILITY OF SURFBOARD:',
            'body' => 'I accept responsibility for the equipment rented to me, including surfboard and rashguard. I hereby agree to pay for all repairs or replacement in such case of my own negligence.',
        ],
        [
            'number' => 5,
            'heading' => 'PERMISSION TO OBTAIN MEDICAL ASSISTANCE:',
            'body' => 'In the event of any injury, illness, emergency, accident or other harm sustained by me (or by the minor on whose behalf I am signing this document), I hereby give this surf shop / resort / surf instructor permission to obtain Medical Assistance from paramedics, hospitals, first aid, and other qualified health care providers.',
        ],
        [
            'number' => 6,
            'heading' => 'ALL ENCOMPASSING.',
            'body' => 'I agree that this agreement shall be governed in all respect by and in accordance with the laws of the Philippines. If I am not a resident of the Philippines, I declare that I will not avoid Conditions 1 and 2 by commencing any legal action in another country.',
        ],
        [
            'number' => 7,
            'heading' => 'LEGALITY.',
            'body' => 'I HAVE READ AND UNDERSTAND THIS "Acknowledgment of Risks and Release, Waiver of Liability and Indemnity Agreement" and sign this document voluntarily. Furthermore, this is a legal document. My signature is my acknowledgment of the contents of this contract.',
        ],
    ],

];
