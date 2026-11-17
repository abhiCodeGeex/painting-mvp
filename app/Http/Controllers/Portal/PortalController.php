<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
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
}
