<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'instructor_response' => $this->instructor_response,
            'photo_url' => $this->photo_path ? asset('storage/' . $this->photo_path) : null,
            'has_response' => $this->hasResponse(),
            'is_edited' => $this->isEdited(),
            'edited_at' => $this->edited_at,
            'created_at' => $this->created_at->format('M d, Y'),
            'created_at_full' => $this->created_at->format('F d, Y'),
            'student' => [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'avatar' => $this->student->avatar,
            ],
            'instructor' => [
                'id' => $this->instructor->id,
                'name' => $this->instructor->name,
            ],
            'booking' => [
                'id' => $this->booking->id,
                'date' => $this->booking->date,
                'surf_spot' => $this->booking->surfSpot->name ?? null,
            ],
            'is_hidden' => $this->is_hidden ?? false,
        ];
    }
}
