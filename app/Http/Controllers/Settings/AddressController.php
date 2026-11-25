<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AddressKind;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Presenters\CatalogOptions;
use App\Http\Presenters\EnquiryPresenter;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Support\IndianIds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer's own billing and delivery addresses.
 */
class AddressController extends PortalController
{
    public function index(Request $request): Response
    {
        $addresses = $this->customer($request)->addresses()->with('state')->orderBy('kind')->orderByDesc('is_default')->get();

        return Inertia::render('settings/addresses', [
            'addresses' => $addresses->map(fn (Address $a) => EnquiryPresenter::address($a))->values(),
            'states' => CatalogOptions::states(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->createFor($this->customer($request), $request);
    }

    /**
     * "Add a delivery address" inside the enquiry editor: the address belongs to the enquiry's
     * customer, also when staff are editing the enquiry for them.
     */
    public function storeForEnquiry(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $this->authorizeEditor($request, $enquiry);

        return $this->createFor($enquiry->customer, $request);
    }

    private function createFor(Customer $customer, Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // The first address of each kind becomes the default.
        if (! $customer->addresses()->where('kind', $data['kind'])->exists()) {
            $data['is_default'] = true;
        }

        $address = $customer->addresses()->create($data);

        return back()->with('success', 'Address saved.')->with('created_address_id', $address->id);
    }

    public function update(Request $request, Address $address): RedirectResponse
    {
        abort_unless($address->customer_id === $this->customer($request)->id, 403);

        $address->update($this->validated($request));

        return back()->with('success', 'Address updated.');
    }

    public function destroy(Request $request, Address $address): RedirectResponse
    {
        abort_unless($address->customer_id === $this->customer($request)->id, 403);

        $address->delete();

        return back()->with('success', 'Address removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(AddressKind::class)],
            'label' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:'.IndianIds::PHONE_REGEX],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'pin_code' => ['required', 'regex:'.IndianIds::PIN_REGEX],
            'state_id' => ['required', Rule::exists('states', 'id')->where('is_active', true)],
            'access_notes' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
        ], [
            'pin_code.regex' => 'Enter a valid 6-digit PIN code.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        return $data;
    }
}
