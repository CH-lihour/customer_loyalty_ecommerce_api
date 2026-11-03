<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RewardRequest extends FormRequest
{
    /**
     * The largest image upload accepted, in kilobytes (4 MB).
     */
    private const MAX_IMAGE_KILOBYTES = 4096;

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
     * The image is either an uploaded file (`image_file`) or an external address (`image`); a file wins.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        // An already expired reward can still be edited without moving its date.
        $reward = $this->route('reward');
        $keepsExpiry = $reward && $this->input('expires_at') === $reward->expires_at?->format('Y-m-d');

        return [
            'min_tier_id' => [$presence, 'integer', Rule::exists('tiers', 'id')],
            'name' => [$presence, 'string', 'max:255'],
            'name_kh' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:50'],
            'points_cost' => [$presence, 'integer', 'min:1', 'max:2000000000'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:2000000000'],
            'image' => ['nullable', 'url:http,https', 'max:2048'],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KILOBYTES],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', ...($keepsExpiry ? [] : ['after_or_equal:today'])],
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
            'min_tier_id.exists' => 'Choose a minimum tier that exists.',
            'image.url' => 'The image URL must start with http:// or https://.',
            'image_file.image' => 'The file must be an image.',
            'image_file.mimes' => 'Images must be JPG, PNG or WebP.',
            'image_file.max' => 'The image must be 4 MB or smaller.',
            'expires_at.after_or_equal' => 'The expiry date cannot be in the past.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['name', 'name_kh', 'description', 'category', 'image', 'expires_at']),
        ));
    }
}
