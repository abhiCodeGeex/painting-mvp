<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Enquiry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Base for customer-portal controllers.
 */
abstract class PortalController extends Controller
{
    use AuthorizesRequests;

    /**
     * The signed-in customer's profile. Accounts created before profiles existed get an
     * empty profile on first use.
     */
    protected function customer(Request $request): Customer
    {
        $user = $request->user();

        return $user->customer ?? $user->customer()->create(['type' => 'individual']);
    }

    /**
     * The signed-in user may change this enquiry in the editor: its customer while it is a
     * draft, or staff (with permission) working on it for the customer.
     */
    protected function authorizeEditor(Request $request, Enquiry $enquiry): void
    {
        abort_unless($enquiry->isEditableBy($request->user()), 403);
    }
}
