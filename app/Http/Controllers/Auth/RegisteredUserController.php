<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Customers\CustomerAccounts;
use App\Enums\CustomerType;
use App\Http\Controllers\Controller;
use App\Models\EnquirySource;
use App\Models\User;
use App\Support\IndianIds;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Creates the customer account + profile (spec §5.1).
     *
     * @throws ValidationException
     */
    public function store(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        // Accept GSTIN typed in lower case.
        $request->merge(['gstin' => $request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null]);

        $data = $request->validate([
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => [Rule::requiredIf($request->input('type') === CustomerType::Company->value), 'nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'regex:'.IndianIds::GSTIN_REGEX],
            'phone' => ['required', 'string', 'regex:'.IndianIds::PHONE_REGEX],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->whereNull('deleted_at')],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'terms' => ['accepted'],
            'marketing' => ['boolean'],
        ], [
            'terms.accepted' => 'Please accept the terms and privacy policy.',
            'gstin.regex' => 'Enter a valid 15-character GSTIN, or leave it empty.',
            'phone.regex' => 'Enter a valid phone number.',
            'company_name.required' => 'Enter your company name.',
        ]);

        $customer = $accounts->create([
            ...$data,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'source_id' => EnquirySource::where('name', 'Website')->value('id'),
            'terms_accepted_at' => now(),
            'marketing_consent_at' => $request->boolean('marketing') ? now() : null,
        ]);

        $user = $customer->user;
        event(new Registered($user));
        Auth::login($user);

        return to_route('dashboard');
    }
}
