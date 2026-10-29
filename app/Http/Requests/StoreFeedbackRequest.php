<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'submit_review' => ['boolean'],
            'submit_concern' => ['boolean'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'required_if:submit_review,true', 'string', 'min:20', 'max:1000'],
            'feedback_tags' => ['nullable', 'array'],
            'feedback_tags.*' => ['string', Rule::enum(FeedbackCategory::class)],
            'photo' => ['nullable', 'image', 'max:5120'],
            'concern_category' => ['nullable', 'required_if:submit_concern,true', 'string', Rule::enum(FeedbackCategory::class)],
            'concern_details' => ['nullable', 'required_if:submit_concern,true', 'string', 'min:30', 'max:2000'],
            'injury_occurred' => ['boolean'],
            'near_miss' => ['boolean'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'truth_declaration' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $wantsReview = $this->boolean('submit_review');
                $wantsConcern = $this->boolean('submit_concern');

                if (! $wantsReview && ! $wantsConcern) {
                    $validator->errors()->add('feedback', 'Choose a public review, a private concern, or both.');
                }

                $category = FeedbackCategory::tryFrom((string) $this->input('concern_category'));

                if ($wantsConcern && (! $category || ! $category->isConcern())) {
                    $validator->errors()->add('concern_category', 'Choose a valid concern category.');
                }

                if ($wantsConcern && ! $this->boolean('truth_declaration')) {
                    $validator->errors()->add('truth_declaration', 'Please confirm that the concern is truthful.');
                }

                foreach ((array) $this->input('feedback_tags', []) as $tag) {
                    $enum = FeedbackCategory::tryFrom((string) $tag);

                    if (! $enum || ! $enum->isPositive()) {
                        $validator->errors()->add('feedback_tags', 'Only positive review tags can be used in a public review.');
                        break;
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required_if' => 'Please write a public review comment.',
            'comment.min' => 'Your public review must be at least 20 characters.',
            'concern_details.required_if' => 'Please describe what happened.',
            'concern_details.min' => 'Please provide at least 30 characters so SISA can review the concern.',
            'truth_declaration.accepted_if' => 'Please confirm that the concern is truthful.',
            'evidence.mimes' => 'Evidence must be a PDF, JPG, or PNG file.',
            'evidence.max' => 'Evidence must be 10 MB or smaller.',
        ];
    }
}
