<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'rating' => 'required|integer|between:1,5',
            'comment' => 'required|string|min:20|max:1000',
            'photo' => 'nullable|image|max:5120', // Max 5MB
            'remove_photo' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Please select a star rating.',
            'rating.between' => 'Rating must be between 1 and 5 stars.',
            'comment.required' => 'Please write a review comment.',
            'comment.min' => 'Your review must be at least 20 characters long.',
            'comment.max' => 'Your review cannot exceed 1000 characters.',
            'photo.image' => 'The uploaded file must be an image.',
            'photo.max' => 'Photo size cannot exceed 5MB.',
        ];
    }
}
