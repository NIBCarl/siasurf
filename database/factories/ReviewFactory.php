<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Review>
 */
class ReviewFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Review::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rating = fake()->numberBetween(3, 5);
        
        return [
            'booking_id' => Booking::factory(),
            'student_id' => User::factory(['role' => 'student']),
            'instructor_id' => User::factory(['role' => 'instructor']),
            'rating' => $rating,
            'comment' => fake()->paragraphs(2, true),
            'instructor_response' => null,
            'photo_path' => null,
            'edited_at' => null,
            'is_hidden' => false,
        ];
    }

    /**
     * Configure the review to have a specific rating.
     */
    public function rating(int $rating): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
        ]);
    }

    /**
     * Configure the review to have a photo.
     */
    public function withPhoto(): static
    {
        return $this->state(fn (array $attributes) => [
            'photo_path' => 'reviews/' . fake()->uuid() . '.jpg',
        ]);
    }

    /**
     * Configure the review to have an instructor response.
     */
    public function withResponse(): static
    {
        return $this->state(fn (array $attributes) => [
            'instructor_response' => fake()->paragraph(),
        ]);
    }

    /**
     * Configure the review to have been edited.
     */
    public function edited(): static
    {
        return $this->state(fn (array $attributes) => [
            'edited_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    /**
     * Configure the review to be hidden.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_hidden' => true,
            'moderation_reason' => fake()->sentence(),
            'moderated_at' => now(),
            'moderated_by' => User::factory(['role' => 'admin']),
        ]);
    }

    /**
     * Configure the review to be positive (4-5 stars).
     */
    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => fake()->numberBetween(4, 5),
        ]);
    }

    /**
     * Configure the review to be negative (1-2 stars).
     */
    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => fake()->numberBetween(1, 2),
        ]);
    }
}
