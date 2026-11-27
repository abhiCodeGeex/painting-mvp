<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Orders\OrderService;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\Payment;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The customer places an order from their approved quotation and tells us about their payments.
 */
class OrderController extends Controller
{
    public function store(Request $request, Enquiry $enquiry, Quote $quote, OrderService $orders): RedirectResponse
    {
        Gate::authorize('view', $enquiry);
        abort_unless($quote->enquiry_id === $enquiry->id && $quote->sent_at, 404);

        $data = $request->validate(
            ['token_amount' => ['required', 'numeric', 'min:0']],
            ['token_amount.required' => 'Enter how much you will pay now as the token amount.'],
        );

        // Rupees as typed → paise; OrderService checks the minimum and the order total.
        $order = $orders->place($quote, $request->user(), Money::toPaise((string) $data['token_amount']));

        return back()->with('success', "Order {$order->number} placed. Please pay the token amount to confirm it.");
    }

    public function pay(Request $request, Enquiry $enquiry, Payment $payment, OrderService $orders): RedirectResponse
    {
        Gate::authorize('view', $enquiry);
        abort_unless($payment->order->enquiry_id === $enquiry->id, 404);

        $data = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            // Cash has no transaction number; every other way of paying does.
            'reference' => ['required_unless:method,cash', 'nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$payment->order->placed_at->subDays(30)->toDateString()],
        ], [
            'method.required' => 'Choose how you paid.',
            'reference.required_unless' => 'Add the transaction / UTR / cheque number so we can find your payment.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
        ]);

        $orders->submitPayment($payment, $request->user(), $data);

        return back()->with('success', 'Thank you! We will confirm your payment shortly.');
    }
}
