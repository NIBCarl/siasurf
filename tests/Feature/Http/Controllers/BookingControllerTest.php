<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\SkillLevel;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Spatie\Permission\Models\Role::findOrCreate('student', 'web');
        \Spatie\Permission\Models\Role::findOrCreate('instructor', 'web');
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');

        $this->instructor->instructorProfile()->create([
            'level' => \App\Enums\InstructorLevel::Level2, 
            'status' => 'active',
            'rate_per_hour' => 1000
        ]);
        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    /** @test */
    public function student_can_view_booking_creation_form()
    {
        $response = $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor->id));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Create')
                ->has('instructor')
                ->has('surfSpots')
            );
    }

    /** @test */
    public function student_can_create_booking_with_valid_data()
    {
        $bookingData = [
            'date' => now()->addDay()->format('Y-m-d'),
            'time_period' => 'morning',
            'skill_level' => 'beginner',
            'student_age' => 25,
            'student_count' => 1,
            'surf_spot_id' => $this->surfSpot->id,
        ];

        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $bookingData);

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Waiver')
            );
        
        $response->assertSessionHas('booking_data');
    }

    /** @test */
    public function booking_fails_safety_validation_for_level1_with_group()
    {
        $level1Instructor = User::factory()->create(['role' => 'instructor']);
        $level1Instructor->assignRole('instructor');
        $level1Instructor->instructorProfile()->create([
            'level' => 1, 
            'status' => 'active',
            'rate_per_hour' => 800
        ]);

        $bookingData = [
            'date' => now()->addDay()->format('Y-m-d'),
            'time_period' => 'morning',
            'skill_level' => 'beginner',
            'student_age' => 25,
            'student_count' => 3, // Exceeds Level 1 max of 2
            'surf_spot_id' => $this->surfSpot->id,
        ];

        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $level1Instructor->id), $bookingData);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('bookings', 0);
    }

    /** @test */
    public function instructor_can_confirm_booking()
    {
        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingStatus::Pending,
        ]);

        $response = $this->actingAs($this->instructor)
            ->patch(route('instructor.bookings.confirm', $booking->id));

        $response->assertRedirect();
        
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Confirmed->value,
        ]);
    }

    /** @test */
    public function student_cannot_confirm_booking()
    {
        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingStatus::Pending,
        ]);

        $response = $this->actingAs($this->student)
            ->patch(route('instructor.bookings.confirm', $booking->id));

        $response->assertForbidden();
    }

    /** @test */
    public function student_can_cancel_pending_booking()
    {
        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingStatus::Pending,
        ]);

        $response = $this->actingAs($this->student)
            ->post(route('student.bookings.cancel', $booking->id), [
                'reason' => 'Changed my mind',
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Cancelled->value,
        ]);
    }

    /** @test */
    public function student_can_view_their_bookings()
    {
        Booking::factory()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $response = $this->actingAs($this->student)
            ->get(route('student.bookings.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Bookings/Index')
                ->has('bookings.data', 3)
            );
    }

    /** @test */
    public function student_cannot_view_others_bookings()
    {
        $otherStudent = User::factory()->create(['role' => 'student']);
        $otherStudent->assignRole('student');
        $booking = Booking::factory()->create([
            'student_id' => $otherStudent->id,
        ]);

        $response = $this->actingAs($this->student)
            ->get(route('student.bookings.show', $booking->id));

        $response->assertForbidden();
    }

    /** @test */
    public function booking_requires_valid_date_in_future()
    {
        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), [
                'date' => now()->subDay()->format('Y-m-d'), // Past date
                'time_period' => 'morning',
                'skill_level' => 'beginner',
                'student_age' => 25,
                'student_count' => 1,
                'surf_spot_id' => $this->surfSpot->id,
            ]);

        $response->assertSessionHasErrors(['date']);
    }

    /** @test */
    public function booking_requires_surf_spot_id()
    {
        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), [
                'date' => now()->addDay()->format('Y-m-d'),
                'time_period' => 'morning',
                'skill_level' => 'beginner',
                'student_age' => 25,
                'student_count' => 1,
                // Missing surf_spot_id
            ]);

        $response->assertSessionHasErrors(['surf_spot_id']);
    }
}
