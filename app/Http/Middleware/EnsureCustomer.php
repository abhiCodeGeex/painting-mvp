<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer portal is for customers only. Staff are sent to the admin panel, except on
 * the enquiry editor, which staff with admin access use to raise and edit enquiries for a
 * customer (the controllers and EnquiryPolicy decide which enquiries).
 */
class EnsureCustomer
{
    /** Portal routes staff may use (the enquiry editor and what it calls). */
    public const STAFF_ROUTES = [
        'portal.enquiries.show',
        'portal.enquiries.edit',
        'portal.enquiries.update',
        'portal.enquiries.destroy',
        'portal.enquiries.submit',
        'portal.enquiries.estimate',
        'portal.enquiries.addresses.store',
        'portal.enquiries.lines.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isStaff()) {
            $allowed = $request->routeIs(...self::STAFF_ROUTES)
                && $user->canAccessPanel(Filament::getPanel('admin'));

            if (! $allowed) {
                // A full page load also when it comes from an Inertia link in the editor's sidebar.
                return Inertia::location(url('/admin'));
            }
        }

        return $next($request);
    }
}
