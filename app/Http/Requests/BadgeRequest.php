<?php

namespace App\Http\Requests;

use App\Models\Badge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BadgeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. PATCH updates only the fields sent.
     *
     * The code is set once: customers' earned badges refer to it, so an update cannot change it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'code' => $this->route('badge')
                ? ['prohibited']
                : ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('badges', 'code')],
            'name' => [$presence, 'string', 'max:100'],
            'name_kh' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', Rule::in(Badge::ICONS)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'metric' => [$presence, Rule::in(Badge::METRICS)],
            'threshold' => [$presence, 'integer', 'min:1', 'max:2000000000'],
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
            'code.regex' => 'The code may only use capital letters, numbers and underscores, such as FIRST_PURCHASE.',
            'code.unique' => 'A badge with this code already exists.',
            'code.prohibited' => 'A badge code cannot be changed once created.',
            'color.regex' => 'The color must be a hex color such as #F59E0B.',
            'metric.in' => 'Choose a condition: orders, spend, referrals, qualifying points or membership days.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['code', 'name', 'name_kh', 'description', 'icon', 'color']),
        ));
    }
}
