<?php

namespace App\Http\Requests\Settings;

use App\Enums\CustomerType;
use App\Models\User;
use App\Support\IndianIds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($this->user()->id)->whereNull('deleted_at'),
            ],
            'type' => ['sometimes', 'required', Rule::enum(CustomerType::class)],
            'company_name' => [Rule::requiredIf($this->input('type') === CustomerType::Company->value), 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:'.IndianIds::PHONE_REGEX],
            'gstin' => ['sometimes', 'nullable', 'string', 'regex:'.IndianIds::GSTIN_REGEX],
            'pan' => ['sometimes', 'nullable', 'string', 'regex:'.IndianIds::PAN_REGEX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 27AAPFU0939F1ZV.',
            'pan.regex' => 'Enter a valid PAN, e.g. AAPFU0939F.',
            'phone.regex' => 'Enter a valid phone number.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gstin' => $this->filled('gstin') ? strtoupper((string) $this->input('gstin')) : null,
            'pan' => $this->filled('pan') ? strtoupper((string) $this->input('pan')) : null,
        ]);
    }
}
