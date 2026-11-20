<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Filament\Resources\Sales\Enquiries\Pages\ViewEnquiry;
use App\Filament\Resources\Sales\Quotes\Pages\EditQuote;
use App\Models\Artwork;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Frame;
use App\Models\Medium;
use App\Models\Quote;
use App\Models\RejectionReason;
use App\Models\State;
use App\Models\Substrate;
use App\Models\User;
use App\Notifications\NewEnquiryMessage;
use App\Notifications\QuoteResponded;
use App\Settings\CompanySettings;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

/*
 * The whole loop, end to end: enquiry → question → reply → quote → customer asks for changes →
 * revised version → approval (or rejection), with messages on both sides.
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

    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole(Role::SuperAdmin->value);

    $this->customer = app(CustomerAccounts::class)->create([
        'name' => 'Nego Buyer', 'email' => 'nego@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '98765 43210',
    ], verified: true);
});

function negotiationEnquiry(Customer $customer): Enquiry
{
    $address = $customer->addresses()->create([
        'kind' => 'delivery', 'line1' => '1 MG Road', 'city' => 'Pune', 'pin_code' => '411001',
        'state_id' => State::where('gst_code', '27')->value('id'), 'is_default' => true,
    ]);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Living room', 'delivery_address_id' => $address->id]);

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

    return $enquiry->fresh();
}

function sentQuote(Enquiry $enquiry, User $admin): Quote
{
    $builder = app(QuoteBuilder::class);
    $quote = $builder->createFromEnquiry($enquiry->fresh(), $admin);
    $builder->send($quote, $admin, ['portal', 'email']);

    return $quote->fresh();
}

test('full negotiation: question, reply, quote, changes, revised quote, approval', function () {
    $enquiry = negotiationEnquiry($this->customer);
    expect($enquiry->status)->toBe(EnquiryStatus::Submitted);

    // Admin reviews and asks a question.
    $this->actingAs($this->admin);
    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getKey()])
        ->callAction('startReview')
        ->callAction('askQuestion', data: ['body' => 'Which wall will it hang on?'])
        ->assertHasNoActionErrors();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::NeedsClarification);
    Notification::assertSentTo($this->customer->user, NewEnquiryMessage::class);

    // Customer replies: back to review.
    $this->actingAs($this->customer->user)
        ->post(route('portal.enquiries.messages.store', $enquiry), ['body' => 'Above the sofa, 3 m wide wall.'])
        ->assertSessionHasNoErrors();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::UnderReview);
    Notification::assertSentTo($this->admin, NewEnquiryMessage::class);

    // Quote v1 sent.
    $v1 = sentQuote($enquiry, $this->admin);
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Quoted);

    // Customer sees the response buttons.
    $this->actingAs($this->customer->user)->get(route('portal.enquiries.show', $enquiry))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('quotes.0.can_respond', true)->has('rejectionReasons'));

    // Customer asks for changes.
    $this->post(route('portal.enquiries.quotes.changes', [$enquiry, $v1]), ['comment' => ''])->assertSessionHasErrors('comment');
    $this->post(route('portal.enquiries.quotes.changes', [$enquiry, $v1]), ['comment' => 'Can you do it in walnut and a bit cheaper?'])
        ->assertSessionHasNoErrors();

    $v1->refresh();
    expect($v1->status)->toBe(QuoteStatus::ChangesRequested)
        ->and($v1->response['comment'])->toContain('walnut')
        ->and($enquiry->fresh()->status)->toBe(EnquiryStatus::ChangesRequested)
        ->and($enquiry->messages()->where('body', 'like', '%walnut%')->where('from_staff', false)->exists())->toBeTrue();
    Notification::assertSentTo($this->admin, QuoteResponded::class, fn ($n) => $n->action === 'changes_requested');

    // Can't answer twice.
    $this->post(route('portal.enquiries.quotes.approve', [$enquiry, $v1]), ['name' => 'Nego Buyer', 'accept_terms' => true])
        ->assertSessionHasErrors('status');

    // Messages still flow both ways while negotiating.
    $this->post(route('portal.enquiries.messages.store', $enquiry), ['body' => 'Also please include installation.'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin);
    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getKey()])
        ->assertActionVisible('reply')
        ->assertActionVisible('createQuote')
        ->callAction('reply', data: ['body' => 'Sure, revised quote coming.'])
        ->assertHasNoActionErrors();

    // Admin opens v1, sees the response and makes v2 with a discount.
    Livewire::test(EditQuote::class, ['record' => $v1->getKey()])
        ->assertSee('Changes requested on')
        ->assertActionVisible('newVersion')
        ->callAction('newVersion');
    $v2 = $enquiry->quotes()->first();
    expect($v2->version)->toBe(2)->and($v2->status)->toBe(QuoteStatus::Draft);
    $v2->update(['discount_percent' => 10]);
    app(QuoteBuilder::class)->send(app(QuoteBuilder::class)->recalculate($v2), $this->admin, ['portal']);

    expect($v1->fresh()->status)->toBe(QuoteStatus::Superseded)
        ->and($v2->fresh()->status)->toBe(QuoteStatus::Sent)
        ->and($v2->fresh()->grand_total)->toBeLessThan($v1->grand_total)
        ->and($enquiry->fresh()->status)->toBe(EnquiryStatus::Quoted);

    // The superseded version can no longer be answered.
    $this->actingAs($this->customer->user);
    $this->post(route('portal.enquiries.quotes.reject', [$enquiry, $v1]), ['rejection_reason_id' => RejectionReason::value('id')])
        ->assertSessionHasErrors('status');

    // Approval needs a name and the terms.
    $this->post(route('portal.enquiries.quotes.approve', [$enquiry, $v2]), [])->assertSessionHasErrors(['name', 'accept_terms']);
    $this->post(route('portal.enquiries.quotes.approve', [$enquiry, $v2]), [
        'name' => 'Nego Buyer', 'po_number' => 'PO-77', 'accept_terms' => true, 'comment' => 'Go ahead!',
    ])->assertSessionHasNoErrors();

    $v2->refresh();
    expect($v2->status)->toBe(QuoteStatus::Approved)
        ->and($v2->response['name'])->toBe('Nego Buyer')
        ->and($v2->response['po_number'])->toBe('PO-77')
        ->and($v2->response['grand_total'])->toBe($v2->grand_total)
        ->and($v2->responded_by)->toBe($this->customer->user->id)
        ->and($v2->getFirstMedia('pdf')->file_name)->toContain('approved')
        ->and($enquiry->fresh()->status)->toBe(EnquiryStatus::Approved);
    Notification::assertSentTo($this->admin, QuoteResponded::class, fn ($n) => $n->action === 'approved');
    Notification::assertSentTo($this->customer->user, QuoteResponded::class);

    $this->get(route('portal.enquiries.show', $enquiry))
        ->assertInertia(fn ($page) => $page->where('quotes.0.status', 'approved')->where('quotes.0.can_respond', false)
            ->where('quotes.0.response.po_number', 'PO-77')->missing('quotes.0.response.ip'));

    // After approval the conversation stays open to arrange delivery and payment.
    $this->post(route('portal.enquiries.messages.store', $enquiry), ['body' => 'When can you deliver?'])->assertSessionHasNoErrors();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Approved);

    // The admin quote screen shows who approved.
    $this->actingAs($this->admin);
    Livewire::test(EditQuote::class, ['record' => $v2->getKey()])->assertSee('Approved by Nego Buyer');
});

test('approving with optional lines keeps only the ticked ones in the total', function () {
    $enquiry = negotiationEnquiry($this->customer);
    $builder = app(QuoteBuilder::class);
    $quote = $builder->createFromEnquiry($enquiry, $this->admin);
    $line = $quote->lines()->first();
    $extra = $line->replicate();
    $extra->fill(['title' => 'Matching second print', 'is_optional' => true, 'is_selected' => true, 'position' => 2])->save();
    $builder->send($quote, $this->admin, ['portal']);
    $withOptional = $quote->fresh()->grand_total;

    $this->actingAs($this->customer->user)
        ->post(route('portal.enquiries.quotes.approve', [$enquiry, $quote]), ['name' => 'Nego Buyer', 'accept_terms' => '1', 'optional_line_ids' => []])
        ->assertSessionHasNoErrors();

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Approved)
        ->and($quote->grand_total)->toBeLessThan($withOptional)
        ->and($extra->fresh()->is_selected)->toBeFalse();
});

test('a company customer must give a role; lines of another quote are refused', function () {
    $this->customer->update(['type' => 'company', 'company_name' => 'Acme Interiors']);
    $enquiry = negotiationEnquiry($this->customer);
    $quote = sentQuote($enquiry, $this->admin);

    $this->actingAs($this->customer->user)
        ->post(route('portal.enquiries.quotes.approve', [$enquiry, $quote]), ['name' => 'A Buyer', 'accept_terms' => true, 'optional_line_ids' => [999999]])
        ->assertSessionHasErrors(['role', 'optional_line_ids.0']);
});

test('rejecting needs a reason, and a comment when the reason asks for one', function () {
    $enquiry = negotiationEnquiry($this->customer);
    $quote = sentQuote($enquiry, $this->admin);
    $needsComment = RejectionReason::where('requires_comment', true)->first() ?? RejectionReason::create(['name' => 'Other', 'requires_comment' => true]);
    $plain = RejectionReason::where('requires_comment', false)->first();

    $this->actingAs($this->customer->user);
    $this->post(route('portal.enquiries.quotes.reject', [$enquiry, $quote]), [])->assertSessionHasErrors('rejection_reason_id');
    $this->post(route('portal.enquiries.quotes.reject', [$enquiry, $quote]), ['rejection_reason_id' => $needsComment->id])->assertSessionHasErrors('comment');
    $this->post(route('portal.enquiries.quotes.reject', [$enquiry, $quote]), ['rejection_reason_id' => $plain->id])->assertSessionHasNoErrors();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Rejected)
        ->and($quote->fresh()->rejection_reason_id)->toBe($plain->id)
        ->and($enquiry->fresh()->status)->toBe(EnquiryStatus::Rejected);
    Notification::assertSentTo($this->admin, QuoteResponded::class, fn ($n) => $n->action === 'rejected');

    // Closed now: no more messages from the customer.
    $this->post(route('portal.enquiries.messages.store', $enquiry), ['body' => 'Hello?'])->assertForbidden();
});

test('an expired quote cannot be approved but a re-quote can be requested', function () {
    $enquiry = negotiationEnquiry($this->customer);
    $quote = sentQuote($enquiry, $this->admin);
    $quote->update(['valid_until' => today()->subDay()]);

    $this->actingAs($this->customer->user);
    $this->get(route('portal.enquiries.show', $enquiry))
        ->assertInertia(fn ($page) => $page->where('quotes.0.can_respond', false)->where('quotes.0.is_expired', true));
    $this->post(route('portal.enquiries.quotes.approve', [$enquiry, $quote]), ['name' => 'Nego Buyer', 'accept_terms' => true])
        ->assertSessionHasErrors('status');

    $this->post(route('portal.enquiries.quotes.requote', [$enquiry, $quote]), ['comment' => 'Still interested'])->assertSessionHasNoErrors();
    expect($quote->fresh()->status)->toBe(QuoteStatus::Expired)
        ->and($enquiry->fresh()->status)->toBe(EnquiryStatus::UnderReview);
    $this->post(route('portal.enquiries.quotes.requote', [$enquiry, $quote]))->assertSessionHasErrors('status');

    // Sales can prepare v2 from it.
    $v2 = app(QuoteBuilder::class)->createFromEnquiry($enquiry->fresh(), $this->admin);
    expect($v2->version)->toBe(2);
});

test('customers cannot answer quotes of other customers, and staff cannot answer for them', function () {
    $enquiry = negotiationEnquiry($this->customer);
    $quote = sentQuote($enquiry, $this->admin);
    $other = app(CustomerAccounts::class)->create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password', 'type' => 'individual'], verified: true);

    $this->actingAs($other->user)
        ->post(route('portal.enquiries.quotes.approve', [$enquiry, $quote]), ['name' => 'X', 'accept_terms' => true])
        ->assertForbidden();
    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);
});
