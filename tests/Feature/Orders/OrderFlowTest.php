<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Orders\OrderService;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Filament\Resources\Sales\Enquiries\Pages\ViewEnquiry;
use App\Filament\Resources\Sales\Orders\Pages\ListOrders;
use App\Filament\Resources\Sales\Orders\Pages\ViewOrder;
use App\Filament\Resources\Sales\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Sales\Orders\Widgets\OrderMoneyOverview;
use App\Filament\Resources\Sales\Payments\Pages\ListPayments;
use App\Filament\Resources\Sales\Quotes\Pages\EditQuote;
use App\Models\Artwork;
use App\Models\Customer;
use App\Models\Frame;
use App\Models\Medium;
use App\Models\Order;
use App\Models\Quote;
use App\Models\State;
use App\Models\Substrate;
use App\Models\User;
use App\Notifications\OrderUpdated;
use App\Settings\CompanySettings;
use App\Settings\PricingSettings;
use App\Support\Money;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

/*
 * Orders: placed from an approved quotation, paid in two parts — a fixed token amount now, the
 * full & final balance on delivery. Payments are offline: the customer sends the reference, staff
 * confirm it. Staff can change the order status at any time.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    Pdf::fake();
    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);

    $company = app(CompanySettings::class);
    $company->state_code = '27';
    $company->save();

    $rules = app(PricingSettings::class);
    $rules->order_token_amount = 500_000; // ₹5,000
    $rules->save();

    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole(Role::SuperAdmin->value);

    $this->customer = app(CustomerAccounts::class)->create([
        'name' => 'Order Buyer', 'email' => 'order@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '98765 43210',
    ], verified: true);
});

/** A sent quotation for one artwork, with a margin, not yet answered. */
function sentQuoteFor(Customer $customer, User $admin): Quote
{
    $address = $customer->addresses()->create([
        'kind' => 'delivery', 'line1' => '1 MG Road', 'city' => 'Pune', 'pin_code' => '411001',
        'state_id' => State::where('gst_code', '27')->value('id'), 'is_default' => true,
    ]);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Studio', 'delivery_address_id' => $address->id]);

    test()->actingAs($customer->user)->post(route('portal.enquiries.lines.store', $enquiry), [
        'source_type' => 'catalog',
        'artwork_id' => Artwork::where('code', 'ART-0004')->value('id'),
        'unit' => 'cm', 'size_reference' => 'image', 'width_mm' => 600, 'height_mm' => 400, 'quantity' => 1,
        'medium_id' => Medium::where('name', 'Giclée print')->value('id'),
        'substrate_id' => Substrate::where('name', 'Fine-art paper 310 gsm')->value('id'),
        'frame_id' => Frame::where('code', 'FR-OAK-25')->value('id'),
        'wall_colour' => '#EDE8E1',
    ])->assertSessionHasNoErrors();
    test()->post(route('portal.enquiries.submit', $enquiry))->assertSessionHasNoErrors();

    $builder = app(QuoteBuilder::class);
    $quote = withMargin($builder->createFromEnquiry($enquiry->fresh(), $admin), 1_000_000);
    $builder->send($quote, $admin, ['portal']);

    return $quote->fresh();
}

/** The customer approves the quotation in the portal. */
function approvedQuoteFor(Customer $customer, User $admin): Quote
{
    $quote = sentQuoteFor($customer, $admin);
    test()->actingAs($customer->user)
        ->post(route('portal.enquiries.quotes.approve', [$quote->enquiry_id, $quote]), ['name' => 'Order Buyer', 'accept_terms' => true])
        ->assertSessionHasNoErrors();

    return $quote->fresh();
}

test('the customer places an order with the token they choose: at least the minimum, the rest on delivery', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    $total = $quote->grand_total;

    // Before ordering, the quotation offers "Place order" with the two parts.
    $this->get(route('portal.enquiries.show', $quote->enquiry_id))
        ->assertInertia(fn ($page) => $page->where('quotes.0.can_order', true)->where('quotes.0.order_token_amount', 500_000)->where('orders', []));

    // Less than the minimum, more than the total, or nothing: refused, no order.
    $order = fn (array $data) => $this->post(route('portal.enquiries.quotes.order', [$quote->enquiry_id, $quote]), $data);
    $order([])->assertSessionHasErrors('token_amount');
    $order(['token_amount' => 4999])->assertSessionHasErrors(['token_amount' => 'The token amount must be at least ₹5,000.00.']);
    $order(['token_amount' => $total / 100 + 1])->assertSessionHasErrors('token_amount');
    expect(Order::count())->toBe(0);

    // Any amount from the minimum up: here ₹20,000.
    $order(['token_amount' => 20000])->assertSessionHasNoErrors();

    $order = Order::sole();
    expect($order->number)->toBe('ORD-'.now()->year.'-0001')
        ->and($order->status)->toBe(OrderStatus::Placed)
        ->and($order->grand_total)->toBe($total)
        ->and($order->token_amount)->toBe(2_000_000)
        ->and($order->customer_id)->toBe($this->customer->id)
        ->and($order->tokenPayment->amount)->toBe(2_000_000)
        ->and($order->tokenPayment->status)->toBe(PaymentStatus::Due)
        ->and($order->finalPayment->amount)->toBe($total - 2_000_000)
        ->and($order->balanceDue())->toBe($total);

    // The customer sees the order; the quotation can no longer be ordered again.
    $this->get(route('portal.enquiries.show', $quote->enquiry_id))
        ->assertInertia(fn ($page) => $page
            ->where('quotes.0.can_order', false)
            ->where('orders.0.number', $order->number)
            ->where('orders.0.payments.0.kind', 'token')
            ->where('orders.0.payments.0.can_pay', true)
            ->where('orders.0.payments.1.kind', 'final')
            ->where('orders.0.payments.1.can_pay', false) // only once delivered
            ->has('paymentMethods', 5));
    $this->post(route('portal.enquiries.quotes.order', [$quote->enquiry_id, $quote]), ['token_amount' => 5000])->assertSessionHasErrors('order');
    expect(Order::count())->toBe(1);

    // Both sides see it in the enquiry thread.
    expect($quote->enquiry->messages()->where('body', 'like', "Order {$order->number} placed%")->exists())->toBeTrue();
});

test('only an approved quotation can be ordered, and only by its customer', function () {
    $quote = sentQuoteFor($this->customer, $this->admin);
    expect($quote->status)->toBe(QuoteStatus::Sent);

    $this->post(route('portal.enquiries.quotes.order', [$quote->enquiry_id, $quote]), ['token_amount' => 5000])->assertSessionHasErrors('order');

    $other = app(CustomerAccounts::class)->create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password', 'type' => 'individual'], verified: true);
    $this->actingAs($other->user)->post(route('portal.enquiries.quotes.order', [$quote->enquiry_id, $quote]), ['token_amount' => 5000])->assertForbidden();
    expect(Order::count())->toBe(0);
});

test('the token never exceeds the total; with no token the order is confirmed straight away', function () {
    $rules = app(PricingSettings::class);
    $quote = approvedQuoteFor($this->customer, $this->admin);

    $rules->order_token_amount = $quote->grand_total + 100_000;
    $rules->save();
    $order = app(OrderService::class)->place($quote, $this->customer->user);
    expect($order->token_amount)->toBe($quote->grand_total) // the minimum is the whole total here
        ->and($order->payments)->toHaveCount(1)
        ->and($order->payments->first()->kind)->toBe(PaymentKind::Token);

    $order->payments()->delete();
    $order->delete();
    $rules->order_token_amount = 0;
    $rules->save();
    $order = app(OrderService::class)->place($quote->fresh(), $this->customer->user);
    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->payments)->toHaveCount(1)
        ->and($order->payments->first()->kind)->toBe(PaymentKind::Final);
});

test('the customer sends the token payment; the full & final waits for delivery', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    $order = app(OrderService::class)->place($quote, $this->customer->user);

    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->tokenPayment]), [])
        ->assertSessionHasErrors(['method', 'reference', 'paid_on']);
    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->tokenPayment]), [
        'method' => 'upi', 'reference' => 'UPI-1234', 'paid_on' => today()->addDay()->toDateString(),
    ])->assertSessionHasErrors('paid_on'); // not in the future

    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->tokenPayment]), [
        'method' => 'upi', 'reference' => 'UPI-1234', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    $token = $order->tokenPayment->fresh();
    expect($token->status)->toBe(PaymentStatus::Submitted)
        ->and($token->reference)->toBe('UPI-1234')
        ->and($order->fresh()->status)->toBe(OrderStatus::Placed); // staff confirm it first

    // Sent once; and the full & final cannot be sent before delivery.
    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $token]), [
        'method' => 'upi', 'reference' => 'again', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasErrors('payment');
    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->finalPayment]), [
        'method' => 'upi', 'reference' => 'early', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasErrors(['payment' => 'The full & final payment is due once your order is delivered.']);

    // Another customer cannot touch it.
    $other = app(CustomerAccounts::class)->create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password', 'type' => 'individual'], verified: true);
    $this->actingAs($other->user)->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->finalPayment]), [
        'method' => 'upi', 'reference' => 'x', 'paid_on' => today()->toDateString(),
    ])->assertForbidden();
});

test('admin: whole order from token to full & final, with any status at any time', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    $order = app(OrderService::class)->place($quote, $this->customer->user);
    $total = $order->grand_total;
    $this->actingAs($this->admin);

    // Token changed before it is paid (never below the minimum); the balance follows.
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertOk()
        ->assertSee($order->number)
        ->callAction('token', data: ['token_amount' => '4000'])
        ->assertHasActionErrors(['token_amount']);
    // A refused amount keeps the dialog open: try again on a fresh page.
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->callAction('token', data: ['token_amount' => '7500'])
        ->assertHasNoActionErrors();
    expect(fn () => app(OrderService::class)->setTokenAmount($order->fresh(), 100_000, $this->admin))
        ->toThrow(ValidationException::class, 'The token amount must be at least ₹5,000.00.');
    $order->refresh();
    expect($order->token_amount)->toBe(750_000)
        ->and($order->tokenPayment->amount)->toBe(750_000)
        ->and($order->finalPayment->amount)->toBe($total - 750_000);

    // Customer sends the token; staff confirm it → the order is confirmed.
    app(OrderService::class)->submitPayment($order->tokenPayment, $this->customer->user, ['method' => 'bank_transfer', 'reference' => 'UTR-77', 'paid_on' => today()->toDateString()]);
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->assertCanSeeTableRecords($order->payments)
        ->callAction(TestAction::make('receive')->table($order->tokenPayment), data: ['method' => 'bank_transfer', 'reference' => 'UTR-77', 'paid_on' => today()->toDateString()])
        ->assertHasNoActionErrors();
    $order->refresh();
    expect($order->tokenPayment->status)->toBe(PaymentStatus::Received)
        ->and($order->tokenPayment->received_by)->toBe($this->admin->id)
        ->and($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->amountPaid())->toBe(750_000)
        ->and($order->balanceDue())->toBe($total - 750_000);

    // The token can no longer be changed once paid.
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertActionHidden('token');

    // Any status, any time — even backwards.
    foreach ([OrderStatus::Dispatched, OrderStatus::InProduction, OrderStatus::Delivered] as $status) {
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('status', data: ['status' => $status->value])
            ->assertHasNoActionErrors();
        expect($order->fresh()->status)->toBe($status);
    }
    expect($order->fresh()->delivered_at)->not->toBeNull();

    // Delivered: the customer can now send the full & final; staff could not find it the first time.
    $final = $order->finalPayment;
    $this->actingAs($this->customer->user)->post(route('portal.enquiries.payments.pay', [$order->enquiry_id, $final]), [
        'method' => 'cheque', 'reference' => 'CHQ-001', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin);
    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('notReceived')->table($final), data: ['reason' => 'Cheque bounced.'])
        ->assertHasNoActionErrors();
    expect($final->fresh())->status->toBe(PaymentStatus::Rejected)->note->toBe('Cheque bounced.');

    $this->actingAs($this->customer->user)
        ->get(route('portal.enquiries.show', $order->enquiry_id))
        ->assertInertia(fn ($page) => $page->where('orders.0.payments.1.status', 'rejected')->where('orders.0.payments.1.can_pay', true)->where('orders.0.payments.1.note', 'Cheque bounced.'));
    $this->post(route('portal.enquiries.payments.pay', [$order->enquiry_id, $final]), [
        'method' => 'bank_transfer', 'reference' => 'UTR-99', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    // Confirmed → everything paid after delivery → completed.
    $this->actingAs($this->admin);
    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('receive')->table($final->fresh()), data: ['method' => 'bank_transfer', 'reference' => 'UTR-99', 'paid_on' => today()->toDateString()])
        ->assertHasNoActionErrors();
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->amountPaid())->toBe($total)
        ->and($order->balanceDue())->toBe(0);

    // Staff can still change the status afterwards, e.g. cancel it; a cancelled order takes no payments.
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->callAction('status', data: ['status' => 'cancelled']);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('a cancelled order takes no payments from the customer', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    $order = app(OrderService::class)->place($quote, $this->customer->user);
    app(OrderService::class)->setStatus($order, OrderStatus::Cancelled, $this->admin);

    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->tokenPayment]), [
        'method' => 'upi', 'reference' => 'UPI-1', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasErrors(['payment' => 'This order is cancelled.']);
});

test('the payments list filters by status, part, method, date and searches order and reference', function () {
    $a = app(OrderService::class)->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user);
    $other = app(CustomerAccounts::class)->create(['name' => 'Second Buyer', 'email' => 'second@example.com', 'password' => 'password', 'type' => 'individual'], verified: true);
    $b = app(OrderService::class)->place(approvedQuoteFor($other, $this->admin), $other->user);

    $service = app(OrderService::class);
    $service->submitPayment($a->tokenPayment, $this->customer->user, ['method' => 'upi', 'reference' => 'UPI-A', 'paid_on' => today()->subDays(5)->toDateString()]);
    $service->markReceived($b->tokenPayment, $this->admin, ['method' => 'cash', 'reference' => 'CASH-B', 'paid_on' => today()->toDateString()]);

    $aToken = $a->tokenPayment->fresh();
    $bToken = $b->tokenPayment->fresh();
    $this->actingAs($this->admin);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$aToken, $bToken, $a->finalPayment, $b->finalPayment])
        ->filterTable('status', [PaymentStatus::Submitted->value])
        ->assertCanSeeTableRecords([$aToken])
        ->assertCanNotSeeTableRecords([$bToken, $a->finalPayment]);

    Livewire::test(ListPayments::class)
        ->filterTable('kind', PaymentKind::Final->value)
        ->assertCanSeeTableRecords([$a->finalPayment, $b->finalPayment])
        ->assertCanNotSeeTableRecords([$aToken, $bToken]);

    Livewire::test(ListPayments::class)
        ->filterTable('method', ['cash'])
        ->assertCanSeeTableRecords([$bToken])
        ->assertCanNotSeeTableRecords([$aToken]);

    Livewire::test(ListPayments::class)
        ->filterTable('paid_on', ['from' => today()->subDays(6)->toDateString(), 'until' => today()->subDays(4)->toDateString()])
        ->assertCanSeeTableRecords([$aToken])
        ->assertCanNotSeeTableRecords([$bToken]);

    Livewire::test(ListPayments::class)->searchTable($b->number)
        ->assertCanSeeTableRecords([$bToken, $b->finalPayment])
        ->assertCanNotSeeTableRecords([$aToken]);
    Livewire::test(ListPayments::class)->searchTable('UPI-A')->assertCanSeeTableRecords([$aToken])->assertCanNotSeeTableRecords([$bToken]);
    Livewire::test(ListPayments::class)->searchTable('Second Buyer')->assertCanSeeTableRecords([$bToken])->assertCanNotSeeTableRecords([$aToken]);

    // Orders list with its status filter.
    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->filterTable('status', [OrderStatus::Confirmed->value])
        ->assertCanSeeTableRecords([$b->fresh()])
        ->assertCanNotSeeTableRecords([$a]);
});

test('staff can place the order from the approved quotation page', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    $this->actingAs($this->admin);

    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->assertActionVisible('placeOrder')
        ->callAction('placeOrder', data: ['token_amount' => '1000'])
        ->assertHasActionErrors(['token_amount']); // below the ₹5,000 minimum
    expect(Order::count())->toBe(0);
    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->callAction('placeOrder', data: ['token_amount' => '6000'])
        ->assertHasNoActionErrors();

    $order = Order::sole();
    expect($order->quote_id)->toBe($quote->id)->and($order->placed_by)->toBe($this->admin->id)
        ->and($order->token_amount)->toBe(600_000);

    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->assertActionHidden('placeOrder')
        ->assertActionVisible('openOrder');

    expect(fn () => app(OrderService::class)->place($quote->fresh(), $this->admin))->toThrow(ValidationException::class);
});

test('both sides are told: staff when an order or payment comes in, the customer when we act on it', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    Notification::fake(); // ignore the quote notifications above

    $this->post(route('portal.enquiries.quotes.order', [$quote->enquiry_id, $quote]), ['token_amount' => 5000])->assertSessionHasNoErrors();
    $order = Order::sole();
    Notification::assertSentTo($this->admin, OrderUpdated::class, fn (OrderUpdated $n) => $n->event === 'placed' && $n->order->is($order));
    Notification::assertNotSentTo($this->customer->user, OrderUpdated::class); // they placed it themselves

    $this->post(route('portal.enquiries.payments.pay', [$quote->enquiry_id, $order->tokenPayment]), [
        'method' => 'upi', 'reference' => 'UPI-9', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();
    Notification::assertSentTo($this->admin, OrderUpdated::class, fn (OrderUpdated $n) => $n->event === 'payment_submitted');

    $service = app(OrderService::class);
    $service->markReceived($order->tokenPayment->fresh(), $this->admin);
    Notification::assertSentTo($this->customer->user, OrderUpdated::class, fn (OrderUpdated $n) => $n->event === 'payment_received');

    $service->setStatus($order->fresh(), OrderStatus::Dispatched, $this->admin);
    Notification::assertSentTo($this->customer->user, OrderUpdated::class, fn (OrderUpdated $n) => $n->event === 'status'
        && str_contains($n->summary, 'Dispatched'));
});

test('an ordered enquiry cannot get a new quotation version until the order is cancelled', function () {
    $quote = approvedQuoteFor($this->customer, $this->admin);
    $order = app(OrderService::class)->place($quote, $this->customer->user);
    $builder = app(QuoteBuilder::class);
    $this->actingAs($this->admin);

    expect(fn () => $builder->newVersion($quote->fresh(), $this->admin))
        ->toThrow(ValidationException::class, 'This enquiry has an order. Cancel the order before revising the quotation.');
    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])->assertActionHidden('newVersion')->assertActionVisible('openOrder');
    Livewire::test(ViewEnquiry::class, ['record' => $quote->enquiry_id])
        ->assertActionHidden('createQuote')
        ->assertActionVisible('openOrder');

    app(OrderService::class)->setStatus($order, OrderStatus::Cancelled, $this->admin);
    expect($builder->newVersion($quote->fresh(), $this->admin)->version)->toBe(2);
});

test('token edge cases: ₹0 confirms the order, and it cannot change once the balance is sent', function () {
    $rules = app(PricingSettings::class);
    $rules->order_token_amount = 0;
    $rules->save();
    $service = app(OrderService::class);

    $order = $service->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user, 300_000);
    expect($order->status)->toBe(OrderStatus::Placed);
    $order = $service->setTokenAmount($order, 0, $this->admin);
    expect($order->status)->toBe(OrderStatus::Confirmed) // nothing left to wait for
        ->and($order->payments)->toHaveCount(1)
        ->and($order->payments->first()->kind)->toBe(PaymentKind::Final);

    // Token back on, delivered, the customer sends the balance: the token is now fixed.
    $order = $service->setTokenAmount($order, 200_000, $this->admin);
    $service->setStatus($order, OrderStatus::Delivered, $this->admin);
    $service->submitPayment($order->fresh()->finalPayment, $this->customer->user, ['method' => 'upi', 'reference' => 'F-1', 'paid_on' => today()->toDateString()]);
    expect(fn () => $service->setTokenAmount($order->fresh(), 250_000, $this->admin))
        ->toThrow(ValidationException::class, 'The full & final payment has already been paid; the token can no longer be changed.');
});

test('paid before delivery completes on delivery; moving back before delivery clears the delivery date', function () {
    $service = app(OrderService::class);
    $order = $service->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user);
    foreach ($order->payments as $payment) {
        $service->markReceived($payment, $this->admin, ['method' => 'cash']);
    }
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

    $service->setStatus($order->fresh(), OrderStatus::Delivered, $this->admin);
    expect($order->fresh())->status->toBe(OrderStatus::Completed)->delivered_at->not->toBeNull();

    $service->setStatus($order->fresh(), OrderStatus::Dispatched, $this->admin);
    expect($order->fresh())->status->toBe(OrderStatus::Dispatched)->delivered_at->toBeNull();
});

test('a payment confirmed by mistake can be undone; it is due again', function () {
    $service = app(OrderService::class);
    $order = $service->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user);
    $token = $service->markReceived($order->tokenPayment, $this->admin, ['method' => 'cash']);
    $this->actingAs($this->admin);

    Livewire::test(ListPayments::class)
        ->assertSee('Still to come')
        ->callAction(TestAction::make('undoReceived')->table($token), data: ['reason' => 'Wrong order'])
        ->assertHasNoActionErrors();
    expect($token->fresh())->status->toBe(PaymentStatus::Due)->received_at->toBeNull()->note->toBe('Wrong order')
        ->and($order->fresh()->amountPaid())->toBe(0);
});

test('the customer sees the order progress in their enquiry list; a cancelled order asks for nothing', function () {
    $service = app(OrderService::class);
    $order = $service->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user);

    $this->get(route('portal.enquiries.index'))
        ->assertInertia(fn ($page) => $page->where('enquiries.0.status_label', "Order {$order->number}: Waiting for your token payment"));

    $service->setStatus($order, OrderStatus::Cancelled, $this->admin);
    $this->get(route('portal.enquiries.show', $order->enquiry_id))
        ->assertInertia(fn ($page) => $page->where('orders.0.status', 'cancelled')
            ->where('orders.0.payments.0.can_pay', false)
            ->where('orders.0.payments.1.can_pay', false));
});

test('orders and payments pages show value, expenses, money received and profit (cancelled left out)', function () {
    $service = app(OrderService::class);
    $a = $service->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user);
    $other = app(CustomerAccounts::class)->create(['name' => 'Second Buyer', 'email' => 'second@example.com', 'password' => 'password', 'type' => 'individual'], verified: true);
    $b = $service->place(approvedQuoteFor($other, $this->admin), $other->user);
    $service->markReceived($a->tokenPayment, $this->admin, ['method' => 'cash']);
    $service->submitPayment($b->tokenPayment, $other->user, ['method' => 'upi', 'reference' => 'U-1', 'paid_on' => today()->toDateString()]);

    $qa = $a->quote;
    $t = OrderMoneyOverview::totals();
    expect($t['orders'])->toBe(2)
        ->and($t['value'])->toBe($a->grand_total + $b->grand_total)
        ->and($t['expenses'])->toBe($qa->total_cost + $b->quote->total_cost)
        ->and($t['profit'])->toBe($qa->taxable + $b->quote->taxable - $qa->total_cost - $b->quote->total_cost)
        ->and($t['received'])->toBe(500_000)
        ->and($t['to_check'])->toBe(500_000)
        ->and($t['to_come'])->toBe($a->grand_total + $b->grand_total - 500_000);

    // A cancelled order counts for nothing.
    $service->setStatus($b, OrderStatus::Cancelled, $this->admin);
    expect(OrderMoneyOverview::totals())
        ->orders->toBe(1)
        ->value->toBe($a->grand_total)
        ->expenses->toBe($qa->total_cost)
        ->profit->toBe($qa->taxable - $qa->total_cost)
        ->to_check->toBe(0);

    $this->actingAs($this->admin);
    Livewire::test(OrderMoneyOverview::class)
        ->assertSee(['Orders value', 'Total expenses', 'Total received', 'Profit', Money::format($qa->taxable - $qa->total_cost)]);
    $this->get(ListOrders::getUrl())->assertOk()->assertSeeLivewire(OrderMoneyOverview::class);
    $this->get(ListPayments::getUrl())->assertOk()->assertSeeLivewire(OrderMoneyOverview::class);
});

test('payments waiting for approval open first under "To check"; cash needs no reference', function () {
    $service = app(OrderService::class);
    $a = $service->place(approvedQuoteFor($this->customer, $this->admin), $this->customer->user);
    $this->actingAs($this->admin);

    // Nothing to check yet: the list opens on All.
    Livewire::test(ListPayments::class)->assertSet('activeTab', 'all');

    // The customer paid cash: no transaction number needed.
    $this->actingAs($this->customer->user)->post(route('portal.enquiries.payments.pay', [$a->enquiry_id, $a->tokenPayment]), [
        'method' => 'cash', 'reference' => '', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();
    // Every other way of paying still needs one.
    $this->post(route('portal.enquiries.payments.pay', [$a->enquiry_id, $a->finalPayment]), [
        'method' => 'upi', 'reference' => '', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasErrors('reference');

    $token = $a->tokenPayment->fresh();
    expect($token->status)->toBe(PaymentStatus::Submitted)->and($token->reference)->toBeNull();
    expect($a->enquiry->messages()->latest('id')->value('body'))->toContain('by Cash. We will confirm it shortly.');

    $this->actingAs($this->admin);
    Livewire::test(ListPayments::class)
        ->assertSet('activeTab', 'to_check')
        ->assertCanSeeTableRecords([$token])
        ->assertCanNotSeeTableRecords([$a->finalPayment])
        ->callAction(TestAction::make('receive')->table($token), data: ['method' => 'cash', 'paid_on' => today()->toDateString()])
        ->assertHasNoActionErrors();
    expect($token->fresh()->status)->toBe(PaymentStatus::Received);
});
