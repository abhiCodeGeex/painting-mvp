<?php

namespace App\Domain\Customers;

use App\Enums\Role;
use App\Enums\UserType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates and updates customer accounts (user + customer profile) in one place, for both
 * self-registration and staff-created customers.
 */
class CustomerAccounts
{
    /**
     * @param  array<string, mixed>  $data  name, email, password?, plus customer fields
     */
    public function create(array $data, bool $verified = false, bool $sendInvitation = false): Customer
    {
        $customer = DB::transaction(function () use ($data, $verified): Customer {
            $user = User::create([
                'type' => UserType::Customer,
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'] ?? Str::password(32),
            ]);

            if ($verified) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->assignRole(Role::Customer->value);

            return $user->customer()->create($this->customerFields($data));
        });

        if ($sendInvitation) {
            // A password-reset link doubles as the invitation to set a first password.
            Password::broker()->sendResetLink(['email' => $customer->user->email]);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        DB::transaction(function () use ($customer, $data): void {
            $userFields = array_filter([
                'name' => $data['name'] ?? null,
                'email' => isset($data['email']) ? strtolower($data['email']) : null,
            ]);
            if (array_key_exists('is_active', $data)) {
                $userFields['is_active'] = (bool) $data['is_active'];
            }
            $customer->user->update($userFields);
            $customer->update($this->customerFields($data));
        });

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function customerFields(array $data): array
    {
        return collect($data)->only([
            'type', 'company_name', 'phone', 'gstin', 'pan', 'website', 'source_id',
            'tax_exempt', 'tax_exempt_note', 'internal_notes', 'terms_accepted_at', 'marketing_consent_at',
        ])->all();
    }
}
