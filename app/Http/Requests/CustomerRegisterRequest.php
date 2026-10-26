<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerRegisterRequest extends FormRequest
{
    /**
     * The largest avatar upload accepted, in kilobytes (2 MB).
     */
    public const MAX_AVATAR_KILOBYTES = 2048;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. `referral_code` is the code of the customer who referred them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_kh' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('customers', 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'phone' => ['nullable', 'string', 'max:32'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_AVATAR_KILOBYTES],
            'referral_code' => ['nullable', 'string', Rule::exists('customers', 'referral_code')->where('is_active', true)],
            'province' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
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
            'email.unique' => 'An account already exists with that email address.',
            'avatar.mimes' => 'The avatar must be a JPG, PNG or WebP image.',
            'avatar.max' => 'The avatar must be 2 MB or smaller.',
            'referral_code.exists' => 'That referral code is not valid.',
        ];
    }

    /**
     * Prepare the data for validation. Blank optional fields are stored as null.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['name', 'name_kh', 'phone', 'province', 'referral_code']),
        ));

        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('referral_code'))) {
            $this->merge(['referral_code' => mb_strtoupper($this->input('referral_code'))]);
        }
    }
}
