<?php

namespace App\Http\Requests;

use App\Services\Onboarding\OnboardingQuestion;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmOnboardingAnswersRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['required'],
        ];

        foreach (OnboardingQuestion::cases() as $question) {
            $rules["answers.{$question->value}"] = ['sometimes', ...$question->valueRules()];

            if ($itemRules = $question->itemRules()) {
                $rules["answers.{$question->value}.*"] = $itemRules;
            }
        }

        return $rules;
    }

    /**
     * Unknown question keys are rejected, so the stored JSON only ever holds known questions.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $known = array_column(OnboardingQuestion::cases(), 'value');

                foreach (array_diff(array_keys($this->input('answers', [])), $known) as $unknown) {
                    $validator->errors()->add("answers.{$unknown}", "Unknown onboarding question [{$unknown}].");
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return array_intersect_key(
            $this->validated('answers'),
            array_flip(array_column(OnboardingQuestion::cases(), 'value')),
        );
    }
}
