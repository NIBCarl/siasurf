<?php

namespace Tests\Unit\Enums;

use App\Enums\InstructorLevel;
use App\Enums\SkillLevel;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping tests: no database, no framework boot.
 */
class SkillLevelMatchingTest extends TestCase
{
    public function test_beginner_students_match_every_instructor_level(): void
    {
        $this->assertSame([1, 2, 3], SkillLevel::Beginner->instructorLevels());
    }

    public function test_intermediate_students_match_level_two_and_three(): void
    {
        $this->assertSame([2, 3], SkillLevel::Intermediate->instructorLevels());
    }

    public function test_advanced_students_match_level_three_only(): void
    {
        $this->assertSame([3], SkillLevel::Advanced->instructorLevels());
    }

    public function test_can_be_taught_by_uses_the_same_mapping(): void
    {
        $this->assertTrue(SkillLevel::Beginner->canBeTaughtBy(1));
        $this->assertFalse(SkillLevel::Intermediate->canBeTaughtBy(1));
        $this->assertTrue(SkillLevel::Intermediate->canBeTaughtBy(2));
        $this->assertFalse(SkillLevel::Advanced->canBeTaughtBy(2));
        $this->assertTrue(SkillLevel::Advanced->canBeTaughtBy(3));
    }

    public function test_slot_capacity_matches_the_hard_safety_ceiling(): void
    {
        $this->assertSame(2, InstructorLevel::Level1->slotCapacity());
        $this->assertSame(1, InstructorLevel::Level2->slotCapacity());
        $this->assertSame(5, InstructorLevel::Level3->slotCapacity());
    }

    public function test_only_level_one_holds_the_slot_while_pending(): void
    {
        $this->assertTrue(InstructorLevel::Level1->holdsSlotWhilePending());
        $this->assertFalse(InstructorLevel::Level2->holdsSlotWhilePending());
        $this->assertFalse(InstructorLevel::Level3->holdsSlotWhilePending());
    }

    public function test_parallel_bookings_are_only_possible_for_group_instructors(): void
    {
        $this->assertFalse(InstructorLevel::Level1->allowsParallelBookings());
        $this->assertFalse(InstructorLevel::Level2->allowsParallelBookings());
        $this->assertTrue(InstructorLevel::Level3->allowsParallelBookings());
    }

    public function test_surf_spot_difficulty_mapping(): void
    {
        $this->assertSame(['beginner'], SkillLevel::Beginner->allowedSurfSpotDifficulties());
        $this->assertSame(
            ['beginner', 'intermediate'],
            SkillLevel::Intermediate->allowedSurfSpotDifficulties()
        );
        $this->assertSame(
            ['beginner', 'intermediate', 'advanced'],
            SkillLevel::Advanced->allowedSurfSpotDifficulties()
        );
    }

    public function test_only_advanced_surfers_may_choose_advanced_breaks(): void
    {
        $this->assertFalse(SkillLevel::Beginner->canSurfDifficulty('intermediate'));
        $this->assertFalse(SkillLevel::Beginner->canSurfDifficulty('advanced'));
        $this->assertTrue(SkillLevel::Beginner->canSurfDifficulty('beginner'));

        $this->assertTrue(SkillLevel::Intermediate->canSurfDifficulty('beginner'));
        $this->assertTrue(SkillLevel::Intermediate->canSurfDifficulty('intermediate'));
        $this->assertFalse(SkillLevel::Intermediate->canSurfDifficulty('advanced'));

        $this->assertTrue(SkillLevel::Advanced->canSurfDifficulty('advanced'));
    }

    public function test_most_restrictive_picks_the_least_experienced_surfer(): void
    {
        $this->assertSame(
            SkillLevel::Beginner,
            SkillLevel::mostRestrictive(['advanced', 'beginner', 'intermediate'])
        );
        $this->assertSame(
            SkillLevel::Intermediate,
            SkillLevel::mostRestrictive([SkillLevel::Advanced, SkillLevel::Intermediate])
        );
        // Empty groups fail safe.
        $this->assertSame(SkillLevel::Beginner, SkillLevel::mostRestrictive([]));
    }
}
