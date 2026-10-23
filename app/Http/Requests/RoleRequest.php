<?php

namespace App\Http\Requests;

use App\Models\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'label' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'min:2', 'max:50', $this->uniqueLabel($role)],
            'pages' => ['sometimes', 'array', $this->hasSection(...)],
            'pages.*' => ['string', 'distinct', Rule::in(array_diff(Role::PAGES, Role::RESERVED_PAGES))],
            'stock_authority' => ['sometimes', 'string', Rule::in(['clerk', 'manager'])],
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
            'pages.*.in' => 'Exchange Rate, User Management, and Role Permissions are reserved for Super Admin.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => preg_replace('/\s+/', ' ', trim($this->input('label')))]);
        }
    }

    /**
     * A role needs at least one section besides My Profile, which every role keeps.
     *
     * @param  list<string>  $value
     */
    private function hasSection(string $attribute, mixed $value, Closure $fail): void
    {
        if (! array_diff((array) $value, [Role::PROFILE_PAGE])) {
            $fail('Select at least one section for this role.');
        }
    }

    /**
     * Role names are unique regardless of letter case.
     */
    private function uniqueLabel(?Role $role): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($role): void {
            $taken = Role::query()
                ->whereRaw('lower(label) = ?', [mb_strtolower((string) $value)])
                ->when($role, fn ($query) => $query->whereKeyNot($role->id))
                ->exists();

            if ($taken) {
                $fail('A role with that name already exists.');
            }
        };
    }
}
