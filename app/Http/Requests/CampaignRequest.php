<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CampaignRequest extends FormRequest
{
    /**
     * The largest banner upload accepted, in kilobytes (4 MB).
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
     * The banner image is either an uploaded file (`image_file`) or an external address (`image`); a file wins.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $campaign = $this->route('campaign');
        // A PATCH without a start date checks the end date against the stored one.
        $startDate = $this->input('start_date', $campaign?->start_date?->format('Y-m-d'));

        return [
            'name' => [$presence, 'string', 'max:100'],
            'name_kh' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'multiplier' => [$presence, 'numeric', 'between:1,10'],
            'start_date' => [$presence, 'date'],
            'end_date' => ['nullable', 'date', ...($startDate ? ['after_or_equal:'.$startDate] : [])],
            'is_active' => ['sometimes', 'boolean'],
            'show_on_home' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'banner_title' => ['nullable', 'string', 'max:120'],
            'banner_subtitle' => ['nullable', 'string', 'max:255'],
            'button_label' => ['nullable', 'string', 'max:40'],
            'link_url' => ['nullable', 'string', 'max:2048', 'regex:#^(/|https?://)#'],
            'image' => ['nullable', 'url:http,https', 'max:2048'],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KILOBYTES],
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
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
            'link_url.regex' => 'The link must be a page path starting with / (such as /products) or an http(s) address.',
            'image.url' => 'The image URL must start with http:// or https://.',
            'image_file.image' => 'The file must be an image.',
            'image_file.mimes' => 'Images must be JPG, PNG or WebP.',
            'image_file.max' => 'The image must be 4 MB or smaller.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['name', 'name_kh', 'description', 'banner_title', 'banner_subtitle', 'button_label', 'link_url', 'image', 'end_date']),
        ));
    }
}
