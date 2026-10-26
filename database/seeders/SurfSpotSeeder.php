<?php

namespace Database\Seeders;

use App\Enums\SkillLevel;
use App\Models\SurfSpot;
use Illuminate\Database\Seeder;

/**
 * Canonical Siargao surf-break registry.
 *
 * `area` is the municipality the break actually belongs to, using the eight
 * real Siargao LGUs (Burgos, Dapa, Del Carmen, General Luna, San Benito, Pilar,
 * San Isidro, Santa Monica) so the map never invents a location.
 *
 * Coordinates were compiled from public surf/island references and are accurate
 * to roughly a hundred metres — enough to put a pin on the correct break. SISA
 * can correct any pin through Admin → Surf Spots without a deploy; that screen,
 * not this file, is the production source of truth.
 *
 * `is_safe_zone` drives FR 3.5: only breaks flagged here can be offered to
 * beginners by the safety engine.
 */
class SurfSpotSeeder extends Seeder
{
    public function run(): void
    {
        $spots = $this->spots();

        foreach ($spots as $index => $spot) {
            SurfSpot::updateOrCreate(
                ['name' => $spot['name']],
                $spot + ['sort_order' => $index]
            );
        }

        // Retire anything from an older seed run that is not in the canonical
        // list. Deactivated rather than deleted so booking history keeps its
        // surf_spot_id.
        SurfSpot::query()
            ->whereNotIn('name', array_column($spots, 'name'))
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function spots(): array
    {
        return [
            // ── General Luna — Cloud 9 / Catangnan cluster ────────────────
            [
                'name' => 'Cloud 9',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'The break that made Siargao famous. A shallow coral reef throwing thick, hollow right-hand barrels. Host of the annual Siargao International Surfing Cup.',
                'difficulty' => SkillLevel::Advanced->value,
                'break_type' => 'Reef break (right)',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Very shallow reef at low tide. Sharp coral, strong currents and a heavy barrel. Not a learning wave.',
                'latitude' => 9.8119,
                'longitude' => 126.1672,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'Quicksilver',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'The classic Siargao learner wave. A soft, forgiving reef-and-sand break a short paddle from Cloud 9, with long mellow rollers for first-timers.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Reef / sand break',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Reef patches inside. Wear booties and follow the instructor line-up.',
                'latitude' => 9.8080,
                'longitude' => 126.1611,
                'is_safe_zone' => true,
            ],
            [
                'name' => 'Jacking Horse',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Short, punchy reef peak sitting between Quicksilver and Cloud 9. The natural step up once a beginner can paddle, take off and trim.',
                'difficulty' => SkillLevel::Intermediate->value,
                'break_type' => 'Reef break',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Fast take-off over live coral. Not suitable for first lessons.',
                'latitude' => 9.8089,
                'longitude' => 126.1594,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'Cemetery',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Long, forgiving right-hander beside the old cemetery. One of the most consistent beginner-to-intermediate waves on the island.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Reef break (right)',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Coral in the inside section; keep the board between you and the reef.',
                'latitude' => 9.7883,
                'longitude' => 126.1547,
                'is_safe_zone' => true,
            ],
            [
                'name' => "Stimpy's",
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'A steep, hollow reef wave for experienced surfers. A shorter ride than Cloud 9 but just as serious once the swell is up.',
                'difficulty' => SkillLevel::Advanced->value,
                'break_type' => 'Reef break',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Shallow coral heads and a tight take-off zone.',
                'latitude' => 9.8062,
                'longitude' => 126.1539,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'Tuazon Point',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Consistent reef point with long, workable walls. A solid venue for intermediate surfers building confidence on reef.',
                'difficulty' => SkillLevel::Intermediate->value,
                'break_type' => 'Reef point',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Reef shelf on the inside. Watch the current on a dropping tide.',
                'latitude' => 9.7922,
                'longitude' => 126.1525,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'Rock Island',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Offshore reef breaks reached by boat. Powerful, fast and exposed — an advanced-only option on a clean swell.',
                'difficulty' => SkillLevel::Advanced->value,
                'break_type' => 'Offshore reef',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Boat access only, no easy exit, strong currents and shallow reef.',
                'latitude' => 9.7861,
                'longitude' => 126.1653,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'Guiwan',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Sheltered reef break on the northern side of the Cloud 9 headland, usually quieter than the main Cloud 9 line-up.',
                'difficulty' => SkillLevel::Intermediate->value,
                'break_type' => 'Reef break',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Coral bottom and a rocky shoreline entry.',
                'latitude' => 9.8086,
                'longitude' => 126.1697,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'General Luna Beach Break',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Sandy beach break right in front of General Luna town. Small, mellow and ideal for a first-ever surf lesson.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Beach break',
                'best_tide' => 'Any',
                'hazard_notes' => 'Boat traffic close to shore. Keep clear of the pier and moorings.',
                'latitude' => 9.7792,
                'longitude' => 126.1583,
                'is_safe_zone' => true,
            ],

            // ── Offshore islands (boat lessons) ──────────────────────────
            [
                'name' => 'Daku Island',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'The largest of the island trio off General Luna. Reef peaks on the eastern side give long, workable rides for intermediates.',
                'difficulty' => SkillLevel::Intermediate->value,
                'break_type' => 'Island reef',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Boat access only. Reef and current around the point; no shore rescue.',
                'latitude' => 9.7583,
                'longitude' => 126.1667,
                'is_safe_zone' => false,
            ],
            [
                'name' => 'Guyam Island',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'The tiny island in the postcard. Small, easy rollers around a sandy islet make it a favourite shallow-water teaching spot.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Island beach break',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Coral fringe around the islet and heavy boat traffic in season.',
                'latitude' => 9.7708,
                'longitude' => 126.1731,
                'is_safe_zone' => true,
            ],

            // ── San Isidro ───────────────────────────────────────────────
            [
                'name' => 'Pacifico Beach',
                'area' => 'San Isidro',
                'region' => 'Siargao Island',
                'description' => 'Long, open stretch of beach north of General Luna with several peaks. Mellow enough for lessons on small days, punchy on a solid swell.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Beach break',
                'best_tide' => 'Any',
                'hazard_notes' => 'Exposed to swell and wind. Watch for rips on bigger days.',
                'latitude' => 9.8675,
                'longitude' => 126.1289,
                'is_safe_zone' => true,
            ],
            [
                'name' => 'La Januza',
                'area' => 'San Isidro',
                'region' => 'Siargao Island',
                'description' => 'A quiet stretch of the north-east coast with soft peaks and very few crowds. A good alternative when General Luna is packed.',
                'difficulty' => SkillLevel::Intermediate->value,
                'break_type' => 'Beach break',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Remote — no lifeguard and limited phone signal. Carry a first-aid kit.',
                'latitude' => 9.8333,
                'longitude' => 126.1333,
                'is_safe_zone' => false,
            ],

            // ── Pilar ────────────────────────────────────────────────────
            [
                'name' => 'Pilar',
                'area' => 'Pilar',
                'region' => 'Siargao Island',
                'description' => 'Calm, sheltered water inside Port Pilar. Ideal flat-water and small-wave coaching away from the General Luna line-up.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Bay / beach break',
                'best_tide' => 'Any',
                'hazard_notes' => 'Mangrove shallows and boat traffic. Best at high tide.',
                'latitude' => 9.9431,
                'longitude' => 126.0750,
                'is_safe_zone' => true,
            ],

            // ── Santa Monica ─────────────────────────────────────────────
            [
                'name' => 'Portillo',
                'area' => 'Santa Monica',
                'region' => 'Siargao Island',
                'description' => 'Heavy, powerful left on the north coast near Santa Monica. A serious wave for experienced surfers chasing uncrowded swell.',
                'difficulty' => SkillLevel::Advanced->value,
                'break_type' => 'Reef point (left)',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Shallow reef and strong currents. No rescue cover — advanced surfers only.',
                'latitude' => 9.9942,
                'longitude' => 126.0431,
                'is_safe_zone' => false,
            ],

            // ── Burgos ───────────────────────────────────────────────────
            [
                'name' => 'Burgos',
                'area' => 'Burgos',
                'region' => 'Siargao Island',
                'description' => 'North-island reef and beach peaks that pick up more swell than General Luna, with plenty of room and few surfers.',
                'difficulty' => SkillLevel::Intermediate->value,
                'break_type' => 'Reef / beach break',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Exposed reef sections. Remote location with limited emergency access.',
                'latitude' => 10.0197,
                'longitude' => 126.0736,
                'is_safe_zone' => false,
            ],

            // ── General Luna — southern beginner beaches ─────────────────
            [
                'name' => 'Malinao',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Sheltered cove south of General Luna with small, clean lines — a reliable fallback for beginner lessons when Cloud 9 is too big.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Beach break',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Reef patches at the points. Keep inside the sandy channel.',
                'latitude' => 9.8000,
                'longitude' => 126.1400,
                'is_safe_zone' => true,
            ],
            [
                'name' => 'Doot Beach',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Shallow, sandy bay popular for first-timers and SUP. Small waves and a soft bottom make it very forgiving.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Beach break',
                'best_tide' => 'High',
                'hazard_notes' => 'Shallow at low tide with scattered coral. Boat traffic near the channel.',
                'latitude' => 9.7833,
                'longitude' => 126.1500,
                'is_safe_zone' => true,
            ],
            [
                'name' => 'Union Beach',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Quiet west-coast beach with gentle whitewater. A good option for absolute beginners and family lessons.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Beach break',
                'best_tide' => 'Mid to high',
                'hazard_notes' => 'Rocky sections at the ends of the beach. Swim between the markers.',
                'latitude' => 9.7900,
                'longitude' => 126.1300,
                'is_safe_zone' => true,
            ],
            [
                'name' => 'Alegria Beach',
                'area' => 'General Luna',
                'region' => 'Siargao Island',
                'description' => 'Wide, sandy beach south of General Luna with soft rolling whitewater. Another dependable beginner venue.',
                'difficulty' => SkillLevel::Beginner->value,
                'break_type' => 'Beach break',
                'best_tide' => 'Mid',
                'hazard_notes' => 'Strong shorebreak on bigger swells. Watch the rip near the southern point.',
                'latitude' => 9.7681,
                'longitude' => 126.1331,
                'is_safe_zone' => true,
            ],
        ];
    }
}
