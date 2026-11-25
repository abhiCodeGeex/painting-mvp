<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Filament\Resources\Sales\Enquiries\Pages\ViewEnquiry;
use App\Filament\Resources\Sales\Quotes\Pages\EditQuote;
use App\Filament\Resources\Sales\Quotes\Pages\ListQuotes;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Medium;
use App\Models\State;
use App\Models\Substrate;
use App\Models\User;
use App\Models\WorkCharge;
use App\Notifications\QuoteSent;
use App\Settings\CompanySettings;
use App\Settings\PricingSettings;
use App\Support\Money;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    Pdf::fake(); // The real PDF is rendered by Gotenberg in the app; the template itself is checked below.
    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);

    $company = app(CompanySettings::class);
    $company->state_code = '27'; // Maharashtra
    $company->save();

    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole(Role::SuperAdmin->value);

    $this->customer = app(CustomerAccounts::class)->create([
        'name' => 'Quote Buyer', 'email' => 'quote@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '98765 43210',
    ], verified: true);
});

function quoteLine(array $overrides = []): array
{
    return [
        'source_type' => 'catalog',
        'artwork_id' => Artwork::where('code', 'ART-0004')->value('id'),
        'unit' => 'cm',
        'size_reference' => 'image',
        'width_mm' => 600,
        'height_mm' => 400,
        'quantity' => 2,
        'medium_id' => Medium::where('name', 'Giclée print')->value('id'),
        'substrate_id' => Substrate::where('name', 'Fine-art paper 310 gsm')->value('id'),
        'frame_id' => Frame::where('code', 'FR-OAK-25')->value('id'),
        'glazing_id' => Glazing::where('name', 'Float glass 2 mm')->value('id'),
        'wall_colour' => '#EDE8E1',
        ...$overrides,
    ];
}

function submittedEnquiry(Customer $customer, string $stateCode = '29'): Enquiry
{
    $address = $customer->addresses()->create([
        'kind' => 'delivery', 'line1' => '1 MG Road', 'city' => 'City', 'pin_code' => '560001',
        'state_id' => State::where('gst_code', $stateCode)->value('id'), 'is_default' => true,
    ]);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Office', 'delivery_address_id' => $address->id, 'installation_needed' => true]);

    test()->actingAs($customer->user)
        ->post(route('portal.enquiries.lines.store', $enquiry), quoteLine())
        ->assertSessionHasNoErrors();
    test()->post(route('portal.enquiries.submit', $enquiry))->assertRedirect();

    return $enquiry->fresh();
}

test('the customer gets a live estimate while choosing options', function () {
    $this->actingAs($this->customer->user);
    $enquiry = $this->customer->enquiries()->create(['project_name' => 'Draft']);

    $response = $this->postJson(route('portal.enquiries.estimate', $enquiry), quoteLine())->assertOk();

    expect($response->json('mode'))->toBe('range')
        ->and($response->json('available'))->toBeTrue()
        ->and($response->json('unit_low'))->toBeLessThan($response->json('unit_high'))
        ->and($response->json('line_high'))->toBe($response->json('unit_high') * 2)
        ->and($response->json())->not->toHaveKey('unit_cost');                     // costs never leave the server
    expect(collect($response->json('items'))->pluck('label')->implode(' '))->toContain('Frame');

    // Bigger frame → higher price.
    $bigger = $this->postJson(route('portal.enquiries.estimate', $enquiry), quoteLine(['width_mm' => 900, 'height_mm' => 600]))->json('unit_price');
    expect($bigger)->toBeGreaterThan($response->json('unit_price'));
});

test('the estimate follows the admin setting', function () {
    $rules = app(PricingSettings::class);
    $this->actingAs($this->customer->user);
    $enquiry = $this->customer->enquiries()->create(['project_name' => 'Draft']);

    $rules->customer_estimate = 'exact';
    $rules->save();
    $exact = $this->postJson(route('portal.enquiries.estimate', $enquiry), quoteLine())->json();
    expect($exact['is_range'])->toBeFalse()->and($exact['unit_low'])->toBe($exact['unit_high']);

    $rules->customer_estimate = 'hidden';
    $rules->save();
    expect($this->postJson(route('portal.enquiries.estimate', $enquiry), quoteLine())->json())->toBe(['mode' => 'hidden']);
});

test('customers cannot price other customers\' enquiries', function () {
    $other = app(CustomerAccounts::class)->create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password', 'type' => 'individual'], verified: true);
    $enquiry = $other->enquiries()->create(['project_name' => 'Theirs']);

    $this->actingAs($this->customer->user)->postJson(route('portal.enquiries.estimate', $enquiry), quoteLine())->assertForbidden();
});

test('submitting stores the estimate the customer saw', function () {
    $enquiry = submittedEnquiry($this->customer);

    expect($enquiry->estimate_snapshot['total_low'])->toBeGreaterThan(0)
        ->and($enquiry->estimate_snapshot['intra_state'])->toBeFalse(); // Karnataka vs Maharashtra
});

test('admin creates a quote prefilled by the pricing engine with IGST across states', function () {
    $enquiry = submittedEnquiry($this->customer, '29');

    $quote = app(QuoteBuilder::class)->createFromEnquiry($enquiry, $this->admin);
    $line = $quote->lines->first();

    expect($quote->number)->toBe('Q-'.now()->year.'-0001')
        ->and($quote->status)->toBe(QuoteStatus::Draft)
        ->and($line->unit_price)->toBe($line->calculated_unit_price)->toBeGreaterThan(0)
        ->and($line->line_total)->toBe($line->unit_price * 2)
        ->and($quote->extras->pluck('name'))->toContain('Installation')
        ->and($quote->intra_state)->toBeFalse()
        ->and($quote->igst)->toBe((int) round($quote->taxable * 0.18))
        ->and($quote->cgst)->toBe(0)
        ->and($quote->grand_total)->toBe($quote->taxable + $quote->igst);

    // Same enquiry again → the same draft, not a second one.
    expect(app(QuoteBuilder::class)->createFromEnquiry($enquiry, $this->admin)->id)->toBe($quote->id);
});

test('within the company state GST is split into CGST and SGST', function () {
    $quote = app(QuoteBuilder::class)->createFromEnquiry(submittedEnquiry($this->customer, '27'), $this->admin);

    expect($quote->intra_state)->toBeTrue()
        ->and($quote->igst)->toBe(0)
        ->and($quote->cgst + $quote->sgst)->toBe($quote->tax_total)
        ->and($quote->tax_total)->toBe((int) round($quote->taxable * 0.18));
});

test('price overrides, discounts and optional lines change the totals', function () {
    $builder = app(QuoteBuilder::class);
    $quote = $builder->createFromEnquiry(submittedEnquiry($this->customer), $this->admin);
    $quote->extras()->update(['inclusion' => 'included']);
    $line = $quote->lines->first();

    $line->update(['unit_price' => 1_000_000, 'discount_amount' => 100_000, 'override_reason' => 'Loyal customer']);
    $quote->update(['discount_percent' => 10]);
    $builder->recalculate($quote);

    expect($line->fresh()->line_total)->toBe(1_900_000)
        ->and($line->fresh()->isOverridden())->toBeTrue()
        ->and($quote->items_total)->toBe(1_900_000)
        ->and($quote->extras_total)->toBe(0)
        ->and($quote->discount_amount)->toBe(190_000)
        ->and($quote->taxable)->toBe(1_710_000);

    $line->update(['is_optional' => true, 'is_selected' => false]);
    $builder->recalculate($quote);
    expect($quote->items_total)->toBe(0);
});

test('sending a quote by email, WhatsApp and portal message', function () {
    $enquiry = submittedEnquiry($this->customer);
    $builder = app(QuoteBuilder::class);
    $quote = $builder->createFromEnquiry($enquiry, $this->admin);

    $result = $builder->send($quote, $this->admin, ['email', 'whatsapp', 'portal']);
    $quote->refresh();
    $enquiry->refresh();

    expect($quote->status)->toBe(QuoteStatus::Sent)
        ->and($quote->valid_until->toDateString())->toBe(today()->addDays(30)->toDateString())
        ->and($quote->getFirstMedia('pdf'))->not->toBeNull()
        ->and($quote->customer_snapshot['name'])->toBe('Quote Buyer')
        ->and($enquiry->status)->toBe(EnquiryStatus::Quoted);

    Notification::assertSentTo($this->customer->user, QuoteSent::class);

    expect($result['whatsapp_url'])->toStartWith('https://wa.me/919876543210?text=')
        ->and(urldecode($result['whatsapp_url']))->toContain($quote->displayNumber());

    $message = $enquiry->messages()->where('from_staff', true)->where('kind', 'message')->latest('id')->first();
    expect($message->body)->toContain($quote->displayNumber())
        ->and($message->getMedia('attachments'))->toHaveCount(1);

    // Locked once sent.
    expect(fn () => $builder->send($quote, $this->admin, ['email']))->toThrow(ValidationException::class);
});

test('the customer sees the quote and opens the PDF', function () {
    $enquiry = submittedEnquiry($this->customer);
    $builder = app(QuoteBuilder::class);
    $quote = $builder->createFromEnquiry($enquiry, $this->admin);

    // Drafts are invisible to the customer.
    $this->actingAs($this->customer->user)->get(route('portal.enquiries.quotes.pdf', [$enquiry, $quote]))->assertNotFound();

    $builder->send($quote, $this->admin, ['portal']);

    $this->get(route('portal.enquiries.show', $enquiry))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('quotes', 1)->where('quotes.0.grand_total', $quote->fresh()->grand_total)->missing('quotes.0.total_cost'));

    $this->get(route('portal.enquiries.quotes.pdf', [$enquiry, $quote]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($quote->fresh()->status)->toBe(QuoteStatus::Viewed);
});

test('a new version supersedes the previous one when sent', function () {
    $enquiry = submittedEnquiry($this->customer);
    $builder = app(QuoteBuilder::class);
    $v1 = $builder->createFromEnquiry($enquiry, $this->admin);
    $builder->send($v1, $this->admin, ['portal']);

    $v2 = $builder->createFromEnquiry($enquiry->fresh(), $this->admin);
    expect($v2->version)->toBe(2)->and($v2->number)->toBe($v1->number)->and($v2->lines)->toHaveCount(1);

    $builder->send($v2, $this->admin, ['portal']);
    expect($v1->fresh()->status)->toBe(QuoteStatus::Superseded)->and($v2->fresh()->status)->toBe(QuoteStatus::Sent);
});

test('admin screens: create quote from the enquiry, edit, preview and send', function () {
    $enquiry = submittedEnquiry($this->customer);
    $this->actingAs($this->admin);

    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getKey()])
        ->callAction('createQuote')
        ->assertHasNoActionErrors();
    $quote = $enquiry->quotes()->firstOrFail();

    Livewire::test(ListQuotes::class)->assertOk()->assertCanSeeTableRecords([$quote]);

    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->assertOk()
        ->fillForm(['discount_percent' => 5, 'lead_time' => '2 weeks'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($quote->fresh()->lead_time)->toBe('2 weeks')->and((int) $quote->fresh()->discount_amount)->toBeGreaterThan(0);

    $this->get(route('admin.quotes.pdf', $quote))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->callAction('send', data: ['channels' => ['email', 'portal'], 'message' => 'Here is your quote.'])
        ->assertHasNoActionErrors();
    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);
    Notification::assertSentTo($this->customer->user, QuoteSent::class, fn (QuoteSent $n) => $n->body === 'Here is your quote.');
});

test('the quote PDF template shows lines, GST split and the grand total', function () {
    $quote = app(QuoteBuilder::class)->createFromEnquiry(submittedEnquiry($this->customer, '27'), $this->admin);
    $quote->load('lines', 'extras', 'enquiry.customer', 'preparer');

    $html = view('pdf.quote', ['quote' => $quote, 'company' => app(CompanySettings::class)])->render();

    expect($html)->toContain($quote->number)
        ->toContain($quote->lines->first()->title)
        ->toContain('CGST 9%')
        ->toContain('SGST 9%')
        ->toContain(Money::format($quote->grand_total))
        ->not->toContain('IGST');

});

test('each quote line keeps a breakdown of how its price was worked out, shown on the edit page', function () {
    $quote = app(QuoteBuilder::class)->createFromEnquiry(submittedEnquiry($this->customer), $this->admin);
    $line = $quote->lines()->firstOrFail();
    $rows = collect($line->breakdown['components'])->keyBy('key');

    expect($rows->keys()->all())->toContain('artwork', 'substrate', 'frame', 'glazing')->not->toContain('labour')
        ->and($rows['frame']['measure'])->toContain('perimeter')->toContain('waste')
        ->and($rows['frame']['rate'])->toContain('/m (')
        ->and($rows['substrate']['measure'])->toBe('60 × 40 cm = 0.24 m²')
        ->and($line->breakdown['unit_price'])->toBe($line->calculated_unit_price)
        ->and($line->breakdown['unit_cost'])->toBe($line->unit_cost);

    $this->actingAs($this->admin);
    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->assertOk()
        ->assertSee('Price breakdown')
        ->assertSee($rows['frame']['rate'])
        ->assertDontSee('Framing work')
        ->assertSee('Calculated unit price')
        ->assertSee('What the customer asked for')
        ->assertSee('Labour (per piece)');
});

test('a new creation is pending until the admin chooses an artist, then priced by their fee', function () {
    $enquiry = submittedEnquiry($this->customer);
    $enquiryLine = $enquiry->lines()->firstOrFail();
    $enquiryLine->update(['source_type' => 'custom_creation', 'artwork_id' => null]);
    $asha = Artist::where('name', 'Asha Menon')->firstOrFail();
    expect($asha->fee_per_m2)->toBe(6_000_000); // seeded

    $open = app(QuoteBuilder::class)->linePrice($enquiryLine->fresh(), $enquiry);
    expect($open['breakdown']['pending'])->toContain('Choose an artist')
        ->and(collect($open['breakdown']['components'])->pluck('key'))->not->toContain('artwork');

    $price = app(QuoteBuilder::class)->linePrice($enquiryLine->fresh(), $enquiry, $asha->id);
    $artwork = collect($price['breakdown']['components'])->firstWhere('key', 'artwork');
    $factor = (float) Medium::where('name', 'Giclée print')->value('price_factor');

    // 60 × 40 cm = 0.24 m² × ₹60,000 × medium factor; no complexity, no artist labour.
    expect($artwork['sell'])->toBe((int) round(0.24 * 6_000_000 * $factor))
        ->and($artwork['label'])->toBe('New artwork by Asha Menon')
        ->and($artwork['rate'])->toContain('(artist fee: Asha Menon)')
        ->and($price['artist_id'])->toBe($asha->id)
        ->and($price['calculated_unit_price'])->toBe($open['calculated_unit_price'] + $artwork['sell'])
        ->and(collect($price['breakdown']['components'])->pluck('key'))->not->toContain('artist_labour')->not->toContain('labour');
});

test('choosing the artist on the quote page re-prices the line straight away', function () {
    $enquiry = submittedEnquiry($this->customer);
    $enquiry->lines()->firstOrFail()->update(['source_type' => 'custom_creation', 'artwork_id' => null]);
    $quote = app(QuoteBuilder::class)->createFromEnquiry($enquiry->fresh(), $this->admin);
    $line = $quote->lines()->firstOrFail();
    $luca = Artist::where('name', 'Luca Bianchi')->firstOrFail();
    $expected = app(QuoteBuilder::class)->linePrice($line->enquiryLine, $enquiry, $luca->id)['calculated_unit_price'];

    $this->actingAs($this->admin);
    $page = Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->assertOk()
        ->assertSee('Choose an artist');
    $key = array_key_first($page->get('data.lines'));

    $page->set("data.lines.{$key}.artist_id", $luca->id)
        ->assertSet("data.lines.{$key}.calculated_unit_price", $expected)
        ->assertSet("data.lines.{$key}.unit_price", Money::toRupees($expected))
        ->assertSee('artist fee: Luca Bianchi')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($line->fresh())->artist_id->toBe($luca->id)
        ->unit_price->toBe($expected)
        ->line_total->toBe($expected * 2);
});

test('labour entered on a quote line is added to the unit price and the line total', function () {
    $quote = app(QuoteBuilder::class)->createFromEnquiry(submittedEnquiry($this->customer), $this->admin);
    $line = $quote->lines()->firstOrFail();
    $engine = $line->calculated_unit_price;

    $this->actingAs($this->admin);
    $page = Livewire::test(EditQuote::class, ['record' => $quote->getKey()]);
    $key = array_key_first($page->get('data.lines'));

    $page->set("data.lines.{$key}.labour_amount", '750')
        ->assertSet("data.lines.{$key}.calculated_unit_price", $engine + 75_000)
        ->assertSet("data.lines.{$key}.unit_price", Money::toRupees($engine + 75_000))
        ->assertSee('Labour (entered on quote)')
        ->assertSee(Money::format(($engine + 75_000) * 2)) // live totals
        ->call('save')
        ->assertHasNoFormErrors();

    $line->refresh();
    expect($line->labour_amount)->toBe(75_000)
        ->and($line->unit_price)->toBe($engine + 75_000)
        ->and($line->calculated_unit_price)->toBe($engine + 75_000)
        ->and($line->isOverridden())->toBeFalse()
        ->and($line->line_total)->toBe(($engine + 75_000) * 2)
        ->and($quote->fresh()->items_total)->toBe(($engine + 75_000) * 2);

    // A hand-typed price different from calculated + labour needs a reason.
    Livewire::test(EditQuote::class, ['record' => $quote->getKey()])
        ->set("data.lines.{$key}.unit_price", Money::toRupees($engine + 1_000))
        ->call('save')
        ->assertHasFormErrors(["lines.{$key}.override_reason" => 'required']);
});

test('work after the artwork takes its amount from Work charges, can be changed on the quote, and adds to the price', function () {
    $installation = WorkCharge::where('name', 'Installation')->firstOrFail();
    expect($installation->amount)->toBe(300_000); // ₹3,000 default

    $quote = app(QuoteBuilder::class)->createFromEnquiry(submittedEnquiry($this->customer), $this->admin);
    $line = $quote->lines()->firstOrFail();
    $engine = $line->calculated_unit_price;

    $this->actingAs($this->admin);
    $page = Livewire::test(EditQuote::class, ['record' => $quote->getKey()])->assertSee('Work after the artwork');
    $key = array_key_first($page->get('data.lines'));

    // Picking the work fills in the master amount and re-prices the line.
    $page->set("data.lines.{$key}.work_items", ['w1' => ['work_charge_id' => null, 'name' => null, 'amount' => '0']])
        ->set("data.lines.{$key}.work_items.w1.work_charge_id", $installation->id)
        ->assertSet("data.lines.{$key}.work_items.w1.amount", '3000')
        ->assertSet("data.lines.{$key}.calculated_unit_price", $engine + 300_000)
        ->assertSee('Work: Installation');

    // Staff lower it for this quote only.
    $page->set("data.lines.{$key}.work_items.w1.amount", '2500')
        ->assertSet("data.lines.{$key}.calculated_unit_price", $engine + 250_000)
        ->assertSet("data.lines.{$key}.unit_price", Money::toRupees($engine + 250_000))
        ->call('save')
        ->assertHasNoFormErrors();

    $line->refresh();
    expect($line->work_items)->toHaveCount(1)
        ->and($line->work_items[0])->toMatchArray(['work_charge_id' => $installation->id, 'name' => 'Installation', 'amount' => 250_000])
        ->and($line->workTotal())->toBe(250_000)
        ->and($line->unit_price)->toBe($engine + 250_000)
        ->and($line->isOverridden())->toBeFalse()
        ->and($line->line_total)->toBe(($engine + 250_000) * 2)
        ->and($installation->fresh()->amount)->toBe(300_000); // the master is unchanged
});

test('work charges are managed as a pricing master', function () {
    $this->actingAs($this->admin)->get('/admin/pricing/work-charges')->assertOk()->assertSee('Installation');
});
