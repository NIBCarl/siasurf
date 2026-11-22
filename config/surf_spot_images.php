<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Siargao Surf Spot Image Gallery
    |--------------------------------------------------------------------------
    |
    | Curated, verified surf-spot imagery keyed by normalized location slug
    | (see SurfSpot::imageSlug()). Paired Inner/Outer reef breaks share one
    | location gallery so students can preview the coastline and wave profile
    | while selecting a spot on the booking map.
    |
    */

    'burgos' => [
        [
            'url' => '/images/surf-spots/burgos/burgos-01-surfforecast.jpg',
            'caption' => 'Breaking reef barrel at Burgos Bay',
            'accuracy' => 'exact_spot',
            'width' => 784,
            'height' => 582,
        ],
        [
            'url' => '/images/surf-spots/burgos/burgos-02-coast.jpg',
            'caption' => 'Aerial view of Burgos reef and shoreline',
            'accuracy' => 'exact_spot',
            'width' => 1040,
            'height' => 780,
        ],
        [
            'url' => '/images/surf-spots/burgos/burgos-03-bay.jpg',
            'caption' => 'Surfers on the gentle inside peak at Burgos',
            'accuracy' => 'exact_spot',
            'width' => 800,
            'height' => 450,
        ],
    ],

    'pacifico' => [
        [
            'url' => '/images/surf-spots/pacifico/pacifico-01-wave.webp',
            'caption' => 'Outer wave wall and surfer at Pacifico',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1650,
        ],
        [
            'url' => '/images/surf-spots/pacifico/pacifico-02-beach-surf.webp',
            'caption' => 'Surfer carving the inside shoulder at Pacifico Beach',
            'accuracy' => 'exact_spot',
            'width' => 1080,
            'height' => 720,
        ],
        [
            'url' => '/images/surf-spots/pacifico/pacifico-04-stormrider-wave.jpg',
            'caption' => 'Pacifico outer reef barrel viewed from the beachfront',
            'accuracy' => 'exact_spot',
            'width' => 1671,
            'height' => 1080,
        ],
        [
            'url' => '/images/surf-spots/pacifico/pacifico-05-stormrider-barrel.jpg',
            'caption' => 'Clean Pacifico reef wall and barrel section',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1080,
        ],
    ],

    'jacking-horse' => [
        [
            'url' => '/images/surf-spots/jacking-horse/jacking-horse-01-wave.webp',
            'caption' => 'Beginner riding whitewater at Jacking Horse',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/jacking-horse/jacking-horse-02-beginners.jpg',
            'caption' => 'Shallow beginner lineup off the Catangnan shore',
            'accuracy' => 'exact_spot',
            'width' => 2000,
            'height' => 1333,
        ],
        [
            'url' => '/images/surf-spots/jacking-horse/jacking-horse-03-lineup.jpg',
            'caption' => 'Aerial overview of Jacking Horse inner reef beside Cloud 9 pier',
            'accuracy' => 'exact_spot',
            'width' => 2000,
            'height' => 1125,
        ],
        [
            'url' => '/images/surf-spots/jacking-horse/jacking-horse-04-coach-lesson.jpg',
            'caption' => 'Instructor guiding a student into a beginner wave at Jacking Horse',
            'accuracy' => 'exact_spot',
            'width' => 1000,
            'height' => 1778,
        ],
        [
            'url' => '/images/surf-spots/jacking-horse/jacking-horse-05-wave.jpg',
            'caption' => 'Surfers wading into the Jacking Horse beach and reef channel',
            'accuracy' => 'exact_spot',
            'width' => 1300,
            'height' => 865,
        ],
    ],

    'little-tahiti' => [
        [
            'url' => '/images/surf-spots/little-tahiti/little-tahiti-03-catangnan-aerial.webp',
            'caption' => 'Aerial reef lineup showing Little Tahiti between Jacking Horse and Quick Silver',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1404,
        ],
        [
            'url' => '/images/surf-spots/little-tahiti/little-tahiti-04-catangnan-coast.jpg',
            'caption' => 'Catangnan boardwalk and shallow reef shelf adjacent to Little Tahiti',
            'accuracy' => 'nearby_coastline',
            'width' => 1300,
            'height' => 865,
        ],
    ],

    'quick-silver' => [
        [
            'url' => '/images/surf-spots/quick-silver/quick-silver-01-wave.webp',
            'caption' => 'Fast Quick Silver barrel looking back toward the Cloud 9 tower',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1406,
        ],
        [
            'url' => '/images/surf-spots/quick-silver/quick-silver-02-break.jpg',
            'caption' => 'Quick Silver reef break and shallow inside channel viewed from the tower',
            'accuracy' => 'exact_spot',
            'width' => 2000,
            'height' => 1333,
        ],
        [
            'url' => '/images/surf-spots/quick-silver/quick-silver-03-lineup.webp',
            'caption' => 'Surfer riding the inside section at Quick Silver',
            'accuracy' => 'exact_spot',
            'width' => 1080,
            'height' => 1350,
        ],
    ],

    'secret' => [
        [
            'url' => '/images/surf-spots/secret/secret-04-reef-coast.jpg',
            'caption' => 'Hollow reef break along the Catangnan coastline near Cloud 9',
            'accuracy' => 'nearby_coastline',
            'width' => 1024,
            'height' => 555,
        ],
        [
            'url' => '/images/surf-spots/secret/secret-06-reef-lineup.jpg',
            'caption' => 'Catangnan boardwalk entry and east-coast reef lineup',
            'accuracy' => 'nearby_coastline',
            'width' => 1300,
            'height' => 865,
        ],
        [
            'url' => '/images/surf-spots/secret/secret-07-catangnan-break.webp',
            'caption' => 'Quiet palm-lined surf break along the Siargao coast',
            'accuracy' => 'nearby_coastline',
            'width' => 2222,
            'height' => 3333,
        ],
    ],

    'cloud-9' => [
        [
            'url' => '/images/surf-spots/cloud-9/cloud-9-01-boardwalk.webp',
            'caption' => 'Cloud 9 boardwalk tower and inside reform zone',
            'accuracy' => 'exact_spot',
            'width' => 1600,
            'height' => 901,
        ],
        [
            'url' => '/images/surf-spots/cloud-9/cloud-9-02-aerial-boardwalk.jpg',
            'caption' => 'Aerial view looking over Cloud 9 surf sets toward the boardwalk',
            'accuracy' => 'exact_spot',
            'width' => 1600,
            'height' => 1067,
        ],
        [
            'url' => '/images/surf-spots/cloud-9/cloud-9-04-outer-wave.webp',
            'caption' => 'Hollow right-hand barrel on Cloud 9 Outer',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/cloud-9/cloud-9-06-stormrider-barrel.jpg',
            'caption' => 'Cloud 9 lineup looking past the pier toward the main peak',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1080,
        ],
    ],

    'cemetery' => [
        [
            'url' => '/images/surf-spots/cemetery/cemetery-01-wave.webp',
            'caption' => 'Cemetery outer peak looking back toward the Siargao highlands',
            'accuracy' => 'exact_spot',
            'width' => 1280,
            'height' => 852,
        ],
        [
            'url' => '/images/surf-spots/cemetery/cemetery-02-lineup.webp',
            'caption' => 'Morning set peaking over Cemetery reef',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/cemetery/cemetery-04-boat-water.jpg',
            'caption' => 'Outrigger boat waiting over clear water at Cemetery reef',
            'accuracy' => 'exact_spot',
            'width' => 640,
            'height' => 1138,
        ],
        [
            'url' => '/images/surf-spots/cemetery/cemetery-05-reef-peaks.jpg',
            'caption' => 'Long forgiving reef wave at Cemetery',
            'accuracy' => 'exact_spot',
            'width' => 1024,
            'height' => 683,
        ],
    ],

    'matanjak' => [
        [
            'url' => '/images/surf-spots/matanjak/matanjak-03-east-coast-aerial.jpg',
            'caption' => 'Aerial view of surfers over clear coral reef along the General Luna coast',
            'accuracy' => 'nearby_coastline',
            'width' => 1200,
            'height' => 630,
        ],
        [
            'url' => '/images/surf-spots/matanjak/matanjak-04-tourism-road-surf.jpg',
            'caption' => 'Golden-hour reef barrel off the Tawin-tawin coastline',
            'accuracy' => 'nearby_coastline',
            'width' => 1500,
            'height' => 996,
        ],
        [
            'url' => '/images/surf-spots/matanjak/matanjak-01-van-inner.png',
            'caption' => 'Satellite map pin showing Matanjak break off Tawin-tawin',
            'accuracy' => 'satellite_reference',
            'width' => 500,
            'height' => 380,
        ],
    ],

    'daku-island' => [
        [
            'url' => '/images/surf-spots/daku-island/daku-island-01-wave.webp',
            'caption' => 'Surfer riding across the Daku Island reef',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/daku-island/daku-island-02-beach.jpg',
            'caption' => 'Aerial view of Daku Island white-sand beach, outrigger boats, and outer reef',
            'accuracy' => 'exact_spot',
            'width' => 1200,
            'height' => 675,
        ],
        [
            'url' => '/images/surf-spots/daku-island/daku-island-04-surf.webp',
            'caption' => 'Longboarder gliding along a clean Daku Island shoulder with palms behind',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1280,
        ],
    ],

    'tuason-point' => [
        [
            'url' => '/images/surf-spots/tuason-point/tuason-point-01-wave.webp',
            'caption' => 'Deep turquoise left-hand barrel at Tuason Point',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/tuason-point/tuason-point-06-catangnan.jpg',
            'caption' => 'Hollow reef section at Tuason Point, Catangnan',
            'accuracy' => 'exact_spot',
            'width' => 4107,
            'height' => 2738,
        ],
        [
            'url' => '/images/surf-spots/tuason-point/tuason-point-07-clean-left.jpg',
            'caption' => 'Surfer carving a clean left wave at Tuason Point',
            'accuracy' => 'exact_spot',
            'width' => 1024,
            'height' => 683,
        ],
    ],

    'philippine-deep' => [
        [
            'url' => '/images/surf-spots/philippine-deep/philippine-deep-03-wave.webp',
            'caption' => 'Playful long shoulder ride at Philippine Deep',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1536,
        ],
        [
            'url' => '/images/surf-spots/philippine-deep/philippine-deep-04-lineup.webp',
            'caption' => 'Sunset session out in the deep channel at Philippine Deep',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1536,
        ],
        [
            'url' => '/images/surf-spots/philippine-deep/philippine-deep-05-waves.webp',
            'caption' => 'Paddling out from the boat toward the Santa Fe coast lineup',
            'accuracy' => 'exact_spot',
            'width' => 1536,
            'height' => 1920,
        ],
        [
            'url' => '/images/surf-spots/philippine-deep/philippine-deep-06-reef.webp',
            'caption' => 'Crystal hollow wave breaking over Philippine Deep offshore reef',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1282,
        ],
    ],

    'giwan' => [
        [
            'url' => '/images/surf-spots/giwan/giwan-01-wave.webp',
            'caption' => 'Clean shoulder wave at Giwan',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/giwan/giwan-02-beach.jpg',
            'caption' => 'Palm-fringed beach and rocky cove entry at Giwan',
            'accuracy' => 'exact_spot',
            'width' => 1024,
            'height' => 682,
        ],
        [
            'url' => '/images/surf-spots/giwan/giwan-03-surfers.jpg',
            'caption' => 'Gentle beginner waves breaking inside Giwan bay',
            'accuracy' => 'exact_spot',
            'width' => 1024,
            'height' => 682,
        ],
    ],

    'salvacion' => [
        [
            'url' => '/images/surf-spots/salvacion/salvacion-02-break.webp',
            'caption' => 'Clean right-hand wave breaking over the reef at Salvacion',
            'accuracy' => 'exact_spot',
            'width' => 1414,
            'height' => 944,
        ],
        [
            'url' => '/images/surf-spots/salvacion/salvacion-03-bangka-surfers.jpg',
            'caption' => 'Outrigger boat launch heading out to Salvacion reef',
            'accuracy' => 'exact_spot',
            'width' => 640,
            'height' => 853,
        ],
        [
            'url' => '/images/surf-spots/salvacion/salvacion-05-pilar-wave.jpg',
            'caption' => 'Heavy reef barrel along the nearby Pilar coastline',
            'accuracy' => 'nearby_coastline',
            'width' => 1920,
            'height' => 1080,
        ],
    ],

    'stimpys' => [
        [
            'url' => '/images/surf-spots/stimpys/stimpys-01-wave.webp',
            'caption' => "Steep left wave wall at Stimpy's",
            'accuracy' => 'exact_spot',
            'width' => 2400,
            'height' => 1600,
        ],
        [
            'url' => '/images/surf-spots/stimpys/stimpys-03-barrel.jpg',
            'caption' => "Surfer dropping into Stimpy's with the rocky islet behind",
            'accuracy' => 'exact_spot',
            'width' => 1080,
            'height' => 697,
        ],
        [
            'url' => '/images/surf-spots/stimpys/stimpys-04-lineup.jpg',
            'caption' => "Turquoise left barrel at Stimpy's off the offshore island",
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1281,
        ],
        [
            'url' => '/images/surf-spots/stimpys/stimpys-05-left-clean.jpg',
            'caption' => "Clean left wave at Stimpy's framed by offshore rock islets",
            'accuracy' => 'exact_spot',
            'width' => 1300,
            'height' => 865,
        ],
    ],

    'rock-island' => [
        [
            'url' => '/images/surf-spots/rock-island/rock-island-01-wave.webp',
            'caption' => 'Thick pitching barrel on Rock Island reef',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/rock-island/rock-island-04-right.jpg',
            'caption' => 'Surfer driving right beneath the iconic Rock Island cliff',
            'accuracy' => 'exact_spot',
            'width' => 1024,
            'height' => 682,
        ],
        [
            'url' => '/images/surf-spots/rock-island/rock-island-05-rock-silhouette.webp',
            'caption' => 'Golden-hour wave silhouetted in front of Rock Island',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1282,
        ],
        [
            'url' => '/images/surf-spots/rock-island/rock-island-06-stormrider.jpg',
            'caption' => 'Offshore reef break beside Rock Island',
            'accuracy' => 'exact_spot',
            'width' => 1920,
            'height' => 1080,
        ],
    ],

    'bomi' => [
        [
            'url' => '/images/surf-spots/bomi/bomi-01-wave.webp',
            'caption' => 'Steep left-hand wave peeling across Bomi reef',
            'accuracy' => 'exact_spot',
            'width' => 2500,
            'height' => 1667,
        ],
        [
            'url' => '/images/surf-spots/bomi/bomi-03-outer-swell.jpg',
            'caption' => 'View past Cloud 9 pier toward the outer Bomi and Catangnan swells',
            'accuracy' => 'nearby_coastline',
            'width' => 1300,
            'height' => 865,
        ],
        [
            'url' => '/images/surf-spots/bomi/bomi-02-diagram-or-shot.webp',
            'caption' => 'Location reference map showing Bomi offshore from Cloud 9',
            'accuracy' => 'satellite_reference',
            'width' => 1450,
            'height' => 1407,
        ],
        [
            'url' => '/images/surf-spots/bomi/bomi-04-van-outer.png',
            'caption' => 'Satellite map pin showing Bomi outer reef',
            'accuracy' => 'satellite_reference',
            'width' => 500,
            'height' => 380,
        ],
    ],

    'back-door' => [
        [
            'url' => '/images/surf-spots/back-door/back-door-01-wave.jpg',
            'caption' => 'Back Door outer reef swell viewed from the boat looking toward Siargao',
            'accuracy' => 'exact_spot',
            'width' => 800,
            'height' => 533,
        ],
        [
            'url' => '/images/surf-spots/back-door/back-door-04-reef-barrel.jpg',
            'caption' => 'Hollow east-coast reef barrel near the Back Door zone',
            'accuracy' => 'nearby_coastline',
            'width' => 1024,
            'height' => 683,
        ],
        [
            'url' => '/images/surf-spots/back-door/back-door-03-van-outer.png',
            'caption' => 'Satellite map pin showing Back Door outer reef ledge',
            'accuracy' => 'satellite_reference',
            'width' => 500,
            'height' => 380,
        ],
    ],

];
