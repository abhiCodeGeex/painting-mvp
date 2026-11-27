<?php

namespace App\Domain\Orders;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Domain\Support\DocumentNumber;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\OrderUpdated;
use App\Settings\PricingSettings;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Orders placed from approved quotations, paid in two parts: a token amount chosen when the order is
 * placed (never less than the minimum in Pricing rules), then the full & final balance on delivery. Payments are offline: the customer
 * enters the reference, staff confirm it. Every step leaves a note in the enquiry thread.
 */
class OrderService
{
    public function __construct(
        private readonly EnquiryWorkflow $workflow,
        private readonly PricingSettings $rules,
    ) {}

    /**
     * Places the order for an approved quotation (by the customer, or by staff for them) with the token
     * amount they chose; without one, the minimum.
     */
    public function place(Quote $quote, User $by, ?int $token = null): Order
    {
        if ($quote->status !== QuoteStatus::Approved) {
            throw ValidationException::withMessages(['order' => 'Only an approved quotation can be ordered.']);
        }

        $total = (int) $quote->grand_total;
        $token ??= $this->minimumToken($total);
        $this->ensureTokenAllowed($token, $total);

        try {
            $order = DB::transaction(function () use ($quote, $by, $total, $token): Order {
                // Lock the quotation so two clicks (or two people) cannot both order it.
                Quote::query()->whereKey($quote->id)->lockForUpdate()->first();
                if (Order::where('quote_id', $quote->id)->exists()) {
                    throw ValidationException::withMessages(['order' => 'This quotation has already been ordered.']);
                }

                $order = Order::create([
                    'number' => DocumentNumber::next('ORD'),
                    'enquiry_id' => $quote->enquiry_id,
                    'quote_id' => $quote->id,
                    'customer_id' => $quote->enquiry->customer_id,
                    'status' => $token > 0 ? OrderStatus::Placed : OrderStatus::Confirmed,
                    'grand_total' => $total,
                    'token_amount' => $token,
                    'placed_by' => $by->id,
                    'placed_at' => now(),
                ]);
                $this->syncPayments($order);

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['order' => 'This quotation has already been ordered.']);
        }

        $balance = $order->grand_total - $order->token_amount;
        $this->event($order, $by, 'placed', "Order {$order->number} placed for ".Money::format($order->grand_total).'. '
            .($order->token_amount > 0 ? 'Token amount '.Money::format($order->token_amount).' due now' : 'No token amount')
            .($balance > 0 ? '; full & final '.Money::format($balance).' on delivery.' : '.'), staff: true, customer: true);

        return $order->fresh('payments');
    }

    /** Staff set any status at any time. */
    public function setStatus(Order $order, OrderStatus $status, User $by): Order
    {
        if ($order->status === $status) {
            return $order;
        }
        $order->status = $status;
        if ($status === OrderStatus::Delivered && ! $order->delivered_at) {
            $order->delivered_at = now();
        }
        // Moved back before delivery: it has not been delivered (yet).
        if (in_array($status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::InProduction, OrderStatus::Dispatched], true)) {
            $order->delivered_at = null;
        }
        $order->save();

        $note = "Order {$order->number}: {$status->customerLabel()}.";
        if ($status === OrderStatus::Delivered) {
            $order->load('payments');
            $unpaid = $order->payments->reject(fn (Payment $p) => $p->status === PaymentStatus::Received);
            if ($unpaid->isEmpty()) {
                // Everything was paid before delivery: nothing left to wait for.
                $order->update(['status' => OrderStatus::Completed]);
                $note .= ' Everything is paid, so the order is now completed. Thank you!';
            } elseif (($final = $unpaid->firstWhere('kind', PaymentKind::Final)) !== null) {
                $note .= ' The full & final payment of '.Money::format($final->amount).' is now due.';
            }
        }
        $this->event($order, $by, 'status', $note, customer: true);

        return $order;
    }

    /** Staff change the token amount while it is still unpaid; the balance follows. */
    public function setTokenAmount(Order $order, int $amount, User $by): Order
    {
        $paidOrSent = [PaymentStatus::Received, PaymentStatus::Submitted];
        if (in_array($order->tokenPayment?->status, $paidOrSent, true)) {
            throw ValidationException::withMessages(['token_amount' => 'The token has already been paid; it can no longer be changed.']);
        }
        if (in_array($order->finalPayment?->status, $paidOrSent, true)) {
            throw ValidationException::withMessages(['token_amount' => 'The full & final payment has already been paid; the token can no longer be changed.']);
        }
        $this->ensureTokenAllowed($amount, $order->grand_total);

        DB::transaction(function () use ($order, $amount): void {
            $order->update(['token_amount' => $amount]);
            $this->syncPayments($order);
            // No token left to wait for.
            if ($amount === 0 && $order->status === OrderStatus::Placed) {
                $order->update(['status' => OrderStatus::Confirmed]);
            }
        });

        $this->event($order, $by, 'status', "Order {$order->number}: token amount changed to ".Money::format($amount)
            .'; full & final '.Money::format($order->grand_total - $amount).' on delivery.', customer: true);

        return $order->fresh('payments');
    }

    /**
     * The customer tells us they paid (offline): method, reference and date. The token can be paid
     * once the order is placed; the full & final payment once it is delivered.
     *
     * @param  array{method: string, reference?: ?string, paid_on: string}  $data
     */
    public function submitPayment(Payment $payment, User $by, array $data): Payment
    {
        $order = $payment->order;
        if (! $this->customerCanPay($payment)) {
            throw ValidationException::withMessages(['payment' => match (true) {
                $order->status === OrderStatus::Cancelled => 'This order is cancelled.',
                $payment->kind === PaymentKind::Final && ! $this->isDelivered($order) => 'The full & final payment is due once your order is delivered.',
                default => 'This payment has already been sent.',
            }]);
        }

        $payment->update([
            'status' => PaymentStatus::Submitted,
            'method' => self::method($data['method']),
            'reference' => filled($data['reference'] ?? null) ? $data['reference'] : null,
            'paid_on' => Carbon::parse($data['paid_on'])->toDateString(),
            'submitted_at' => now(),
            'note' => null,
        ]);

        $this->event($order, $by, 'payment_submitted', "Payment sent for order {$order->number}: {$payment->kind->getLabel()} "
            .Money::format($payment->amount).' by '.$payment->method->getLabel().($payment->reference ? " (ref. {$payment->reference})" : '').'. We will confirm it shortly.', staff: true);

        return $payment;
    }

    /**
     * Staff confirm the money arrived (or record a payment straight away), optionally with its details.
     *
     * @param  array{method?: ?string, reference?: ?string, paid_on?: ?string}  $data
     */
    public function markReceived(Payment $payment, User $by, array $data = []): Payment
    {
        if ($payment->status === PaymentStatus::Received) {
            return $payment;
        }

        DB::transaction(function () use ($payment, $by, $data): void {
            $payment->update([
                'status' => PaymentStatus::Received,
                'method' => filled($data['method'] ?? null) ? self::method($data['method']) : $payment->method,
                'reference' => filled($data['reference'] ?? null) ? $data['reference'] : $payment->reference,
                'paid_on' => filled($data['paid_on'] ?? null) ? Carbon::parse($data['paid_on'])->toDateString() : ($payment->paid_on ?? today()),
                'received_at' => now(),
                'received_by' => $by->id,
                'note' => null,
            ]);
            $this->advance($payment->order->fresh(['payments']));
        });

        $this->event($payment->order->fresh(), $by, 'payment_received', "Payment received for order {$payment->order->number}: "
            .$payment->kind->getLabel().' '.Money::format($payment->amount).'. Thank you!', customer: true);

        return $payment;
    }

    /** Staff could not find the payment the customer sent; the customer can send the details again. */
    public function markNotReceived(Payment $payment, User $by, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Submitted) {
            throw ValidationException::withMessages(['payment' => 'Only a payment waiting to be checked can be marked as not received.']);
        }
        $payment->update(['status' => PaymentStatus::Rejected, 'note' => $reason]);

        $this->event($payment->order, $by, 'payment_not_received', "We could not find the payment for order {$payment->order->number} "
            .'('.$payment->kind->getLabel().($payment->reference ? ", ref. {$payment->reference}" : '')."): {$reason} Please check and send the details again.", customer: true);

        return $payment;
    }

    /**
     * Staff undo a payment marked as received by mistake: it is due again. The order status is left as
     * it is (staff change it if needed).
     */
    public function undoReceived(Payment $payment, User $by, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Received) {
            throw ValidationException::withMessages(['payment' => 'Only a received payment can be undone.']);
        }
        $payment->update(['status' => PaymentStatus::Due, 'received_at' => null, 'received_by' => null, 'note' => $reason]);

        $this->event($payment->order, $by, 'status', "Order {$payment->order->number}: the {$payment->kind->getLabel()} of "
            .Money::format($payment->amount)." is due again ({$reason}).");

        return $payment;
    }

    /** A note in the enquiry thread for both sides, and mail / a bell notification for whoever should act. */
    private function event(Order $order, User $by, string $event, string $note, bool $staff = false, bool $customer = false): void
    {
        $this->workflow->recordOrderEvent($order->enquiry, $by, $note);

        if ($staff) {
            Notification::send($this->workflow->staffRecipients($order->enquiry)->reject(fn (User $u) => $u->is($by)), new OrderUpdated($order, $event, $note));
        }
        if ($customer && ($user = $order->customer->user) && ! $user->is($by)) {
            $user->notify(new OrderUpdated($order, $event, $note));
        }
    }

    /** The least token we accept: the minimum in Pricing rules, or the whole total on a smaller order. */
    public function minimumToken(int $total): int
    {
        return min(max(0, $this->rules->order_token_amount), max(0, $total));
    }

    /** No token below the minimum, and none above the order total. */
    private function ensureTokenAllowed(int $token, int $total): void
    {
        $minimum = $this->minimumToken($total);
        if ($token < $minimum) {
            throw ValidationException::withMessages(['token_amount' => 'The token amount must be at least '.Money::format($minimum).'.']);
        }
        if ($token > $total) {
            throw ValidationException::withMessages(['token_amount' => 'The token amount cannot be more than the order total ('.Money::format($total).').']);
        }
    }

    /** A form may hand over the enum itself or its value. */
    private static function method(PaymentMethod|string $method): PaymentMethod
    {
        return $method instanceof PaymentMethod ? $method : PaymentMethod::from($method);
    }

    public function customerCanPay(Payment $payment): bool
    {
        $order = $payment->order;

        return $order->status !== OrderStatus::Cancelled
            && in_array($payment->status, [PaymentStatus::Due, PaymentStatus::Rejected], true)
            && ($payment->kind === PaymentKind::Token || $this->isDelivered($order));
    }

    private function isDelivered(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed], true);
    }

    /** Token paid → confirmed; everything paid after delivery → completed. */
    private function advance(Order $order): void
    {
        $allPaid = $order->payments->every(fn (Payment $p) => $p->status === PaymentStatus::Received);

        if ($order->status === OrderStatus::Placed && $order->tokenPayment?->status === PaymentStatus::Received) {
            $order->update(['status' => OrderStatus::Confirmed]);
        }
        if ($order->status === OrderStatus::Delivered && $allPaid) {
            $order->update(['status' => OrderStatus::Completed]);
        }
    }

    /** Token and full & final payments that match the order's token amount (a part of ₹0 is left out). */
    private function syncPayments(Order $order): void
    {
        $parts = [
            PaymentKind::Token->value => $order->token_amount,
            PaymentKind::Final->value => $order->grand_total - $order->token_amount,
        ];
        foreach ($parts as $kind => $amount) {
            $payment = $order->payments()->where('kind', $kind)->first();
            if ($amount <= 0) {
                if ($payment && $payment->status !== PaymentStatus::Received) {
                    $payment->delete();
                }

                continue;
            }
            if (! $payment) {
                $order->payments()->create(['kind' => $kind, 'amount' => $amount, 'status' => PaymentStatus::Due]);
            } elseif ($payment->status !== PaymentStatus::Received) {
                $payment->update(['amount' => $amount]);
            }
        }
        $order->unsetRelation('payments')->unsetRelation('tokenPayment')->unsetRelation('finalPayment');
    }
}
