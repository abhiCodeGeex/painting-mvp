<?php

namespace App\Domain\Quotes;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Domain\Pricing\GstCalculator;
use App\Domain\Pricing\LineConfig;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Support\DocumentNumber;
use App\Enums\EnquiryStatus;
use App\Enums\ExtraChargeBasis;
use App\Enums\ExtraChargeType;
use App\Enums\MessageKind;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Enquiry;
use App\Models\EnquiryLine;
use App\Models\ExtraCharge;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteSent;
use App\Settings\CompanySettings;
use App\Settings\PricingSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Creates, prices, versions and sends quotations (spec §8).
 */
class QuoteBuilder
{
    /** Enquiry statuses from which a quote can be prepared. */
    public const QUOTABLE = [EnquiryStatus::Submitted, EnquiryStatus::UnderReview, EnquiryStatus::NeedsClarification,
        EnquiryStatus::Quoted, EnquiryStatus::ChangesRequested];

    public function __construct(
        private readonly PricingEngine $engine,
        private readonly GstCalculator $gst,
        private readonly PricingSettings $rules,
        private readonly EnquiryWorkflow $workflow,
    ) {}

    /**
     * A new draft quote prefilled from the enquiry lines through the pricing engine.
     * If a quote already exists, the new one is the next version of it.
     */
    public function createFromEnquiry(Enquiry $enquiry, User $by): Quote
    {
        if (! in_array($enquiry->status, self::QUOTABLE, true)) {
            throw ValidationException::withMessages(['status' => 'A quote can only be prepared for a submitted enquiry.']);
        }

        if ($draft = $enquiry->quotes()->where('status', QuoteStatus::Draft)->first()) {
            return $draft; // One draft at a time.
        }

        $previous = $enquiry->quotes()->first();
        if ($previous) {
            return $this->newVersion($previous, $by);
        }

        return DB::transaction(function () use ($enquiry, $by): Quote {
            $enquiry->loadMissing(['lines.finishings', 'lines.artwork', 'lines.medium', 'lines.substrate', 'lines.frame', 'lines.mat',
                'lines.glazing', 'deliveryAddress.state', 'customer.user']);

            $quote = $enquiry->quotes()->create([
                'number' => DocumentNumber::next('Q'),
                'version' => 1,
                'prepared_by' => $by->id,
                'lead_time' => $this->rules->default_lead_time,
                'payment_terms' => $this->rules->default_payment_terms,
                'terms' => $this->rules->default_quote_terms,
            ]);

            foreach ($enquiry->lines as $i => $line) {
                $quote->lines()->create($this->linePrice($line, $enquiry) + [
                    'enquiry_line_id' => $line->id,
                    'position' => $i + 1,
                    'title' => $line->displayTitle(),
                    'spec' => $this->specText($line),
                ]);
            }

            $this->prefillExtras($quote, $enquiry);

            return $this->recalculate($quote);
        });
    }

    /**
     * Pricing engine figures for one enquiry line, with the artist chosen for a new creation.
     * Price per piece = cost (artwork + materials at cost) + labour + the margin staff type.
     * The margin starts empty on a new quote: staff decide it for every artwork.
     *
     * @return array<string, mixed>
     */
    public function linePrice(EnquiryLine $line, Enquiry $enquiry, ?int $artistId = null, int $labourAmount = 0, ?int $marginAmount = null): array
    {
        $r = $this->engine->price(LineConfig::fromLine($line, $artistId), $enquiry->required_by);
        $cost = self::costOf($r);
        $labour = max(0, $labourAmount);
        $margin = $marginAmount === null ? null : max(0, $marginAmount);
        $price = $cost + $labour + (int) $margin;

        return [
            'quantity' => $r['quantity'],
            'unit_cost' => $cost,
            'calculated_unit_price' => $price,
            'unit_price' => $price,
            'artist_id' => $artistId,
            'labour_amount' => $labour,
            'margin_amount' => $margin,
            'breakdown' => $r,
        ];
    }

    /**
     * What one piece costs us: the artwork (artist fee or catalogue price) plus materials at cost.
     *
     * @param  array<string, mixed>  $breakdown  pricing engine result
     */
    public static function costOf(array $breakdown): int
    {
        return (int) collect($breakdown['components'] ?? [])
            ->sum(fn ($c) => ($c['key'] ?? null) === 'artwork' ? (int) ($c['sell'] ?? 0) : (int) ($c['cost'] ?? 0));
    }

    /** Copies the latest quote into a new draft with the next version number. */
    public function newVersion(Quote $from, User $by): Quote
    {
        // An ordered enquiry is settled: a revised quotation would leave the order on an old version.
        if ($from->enquiry->orders()->where('status', '!=', OrderStatus::Cancelled)->exists()) {
            throw ValidationException::withMessages(['status' => 'This enquiry has an order. Cancel the order before revising the quotation.']);
        }

        return DB::transaction(function () use ($from, $by): Quote {
            $copy = $from->replicate(['status', 'issue_date', 'valid_until', 'sent_at', 'sent_via', 'viewed_at']);
            $copy->version = (int) $from->enquiry->quotes()->withTrashed()->max('version') + 1;
            $copy->status = QuoteStatus::Draft;
            $copy->prepared_by = $by->id;
            $copy->save();

            foreach ($from->lines as $line) {
                // Work after the artwork is no longer part of a quote (labour per piece covers it) and
                // re-pricing ignores it, so an old line's list must not travel on into the new version.
                $copy->lines()->save($line->replicate()->forceFill(['work_items' => null]));
            }
            foreach ($from->extras as $extra) {
                $copy->extras()->save($extra->replicate());
            }

            return $this->recalculate($copy);
        });
    }

    /** Line totals, extras, discount, GST and grand total (spec §7.3). */
    /**
     * @param  bool  $keepTax  keep the sent quote's tax rate and place of supply (a customer approving the
     *                         quote they saw must not get today's rate or a since-changed address)
     */
    public function recalculate(Quote $quote, bool $keepTax = false): Quote
    {
        $quote->load(['lines', 'extras', 'enquiry.deliveryAddress.state', 'enquiry.customer']);

        $items = 0;
        $cost = 0;
        foreach ($quote->lines as $line) {
            $line->discount_amount = (int) $line->discount_amount;
            $line->line_total = max(0, (int) $line->unit_price * $line->quantity - $line->discount_amount);
            $line->saveQuietly();
            if ($line->counts()) {
                $items += $line->line_total;
                // Our cost: artwork, materials and labour (labour is paid out, so it is not profit).
                $cost += ((int) $line->unit_cost + (int) $line->labour_amount) * $line->quantity;
            }
        }

        $extras = (int) $quote->extras->where('inclusion', 'charged')->sum('amount');
        $discount = (float) $quote->discount_percent > 0
            ? (int) round(($items + $extras) * (float) $quote->discount_percent / 100)
            : min((int) $quote->discount_amount, $items + $extras);

        $taxable = $items + $extras - $discount;
        if ($keepTax) {
            $state = $quote->place_of_supply;
            $gst = $this->gst->calculate($taxable, $state, false, (float) $quote->tax_rate);
        } else {
            $state = $quote->enquiry->deliveryAddress?->state?->gst_code;
            $gst = $this->gst->calculate($taxable, $state, (bool) $quote->enquiry->customer?->tax_exempt);
        }

        $quote->fill([
            'items_total' => $items,
            'extras_total' => $extras,
            'discount_amount' => $discount,
            'taxable' => $gst['taxable'],
            'place_of_supply' => $state,
            'intra_state' => $gst['intra_state'],
            'tax_rate' => $gst['rate'],
            'cgst' => $gst['cgst'],
            'sgst' => $gst['sgst'],
            'igst' => $gst['igst'],
            'tax_total' => $gst['tax'],
            'grand_total' => $gst['grand_total'],
            'total_cost' => $cost,
        ])->save();

        return $quote;
    }

    /**
     * Finalises the quote, renders the PDF and delivers it on the chosen channels.
     *
     * @param  list<string>  $channels  email | whatsapp | portal
     * @return array{quote: Quote, whatsapp_url: ?string}
     */
    public function send(Quote $quote, User $by, array $channels, ?string $message = null): array
    {
        if (! $quote->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This quote has already been sent. Create a new version to change it.']);
        }
        if ($quote->lines()->count() === 0) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before sending.']);
        }
        if ($channels === []) {
            throw ValidationException::withMessages(['channels' => 'Choose at least one way to send the quote.']);
        }
        if ($problems = $this->unfinishedLines($quote)) {
            throw ValidationException::withMessages(['lines' => $problems]);
        }

        $enquiry = $quote->enquiry;
        $customer = $enquiry->customer;

        DB::transaction(function () use ($quote, $by, $channels, $enquiry, $customer): void {
            $this->recalculate($quote);

            $enquiry->quotes()->whereKeyNot($quote->id)->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::ChangesRequested, QuoteStatus::Expired])
                ->update(['status' => QuoteStatus::Superseded]);

            $address = $enquiry->deliveryAddress;
            $quote->fill([
                'status' => QuoteStatus::Sent,
                'issue_date' => today(),
                'valid_until' => today()->addDays($this->rules->quote_validity_days),
                'sent_at' => now(),
                'sent_via' => $channels,
                'prepared_by' => $quote->prepared_by ?? $by->id,
                'customer_snapshot' => [
                    'name' => $customer->displayName(),
                    'contact' => $customer->user?->name,
                    'email' => $customer->user?->email,
                    'phone' => $customer->phone,
                    'gstin' => $customer->gstin,
                ],
                'delivery_snapshot' => $address ? ['one_line' => $address->oneLine(), 'state' => $address->state?->name, 'state_code' => $address->state?->gst_code] : null,
            ])->save();

            $quote->addMediaFromString($this->renderPdf($quote))
                ->usingFileName(str($quote->displayNumber())->slug().'.pdf')
                ->usingName($quote->displayNumber())
                ->toMediaCollection('pdf');

            $this->workflow->markQuoted($enquiry, $by, "Quotation {$quote->displayNumber()} sent.");
        });

        $quote->refresh();
        $body = trim($message ?: $this->defaultMessage($quote));

        if (in_array('portal', $channels, true)) {
            $msg = $enquiry->messages()->create([
                'user_id' => $by->id,
                'kind' => MessageKind::Message,
                'body' => $body,
                'from_staff' => true,
            ]);
            $pdf = $quote->getFirstMedia('pdf');
            $pdf?->copy($msg, 'attachments', 'local');
        }

        if (in_array('email', $channels, true) && $customer->user) {
            $customer->user->notify(new QuoteSent($quote, $body));
        }

        return [
            'quote' => $quote,
            'whatsapp_url' => in_array('whatsapp', $channels, true) ? $this->whatsappUrl($quote, $body) : null,
        ];
    }

    /**
     * Lines not ready to send: the price could not be worked out (e.g. a new creation with no artist
     * yet), no margin was entered, or there is no price at all.
     * Optional lines are checked too, ticked or not: the customer can tick one when approving.
     *
     * @return list<string> one plain-language message per unfinished line
     */
    public function unfinishedLines(Quote $quote): array
    {
        $problems = [];
        foreach ($quote->lines()->orderBy('position')->get() as $line) {
            $label = 'Line '.$line->position.($line->title ? ' “'.$line->title.'”' : '');
            $pending = array_values(array_filter((array) ($line->breakdown['pending'] ?? [])));

            if ($pending !== []) {
                $problems[] = "{$label} is not finished: ".lcfirst(implode(', ', $pending)).'.';
            } elseif ($line->margin_amount === null) {
                $problems[] = "{$label} has no margin yet. Enter your margin before sending.";
            } elseif ((int) $line->unit_price <= 0) {
                $problems[] = "{$label} has no price yet.";
            }
        }

        return $problems;
    }

    public function renderPdf(Quote $quote): string
    {
        $quote->loadMissing(['lines', 'extras', 'enquiry.customer', 'preparer']);

        return Pdf::view('pdf.quote', ['quote' => $quote, 'company' => app(CompanySettings::class)])
            ->format('a4')
            ->generatePdfContent();
    }

    public function defaultMessage(Quote $quote): string
    {
        return "Hello {$quote->enquiry->customer->displayName()},\n\n"
            ."Your quotation {$quote->displayNumber()} for \"{$quote->enquiry->project_name}\" is ready: "
            .Money::format($quote->grand_total).' including GST, valid until '.$quote->valid_until?->format('d M Y').".\n\n"
            .'You can view and download it here: '.route('portal.enquiries.show', $quote->enquiry_id);
    }

    /**
     * Free click-to-chat link (no WhatsApp Business API needed): opens WhatsApp with the message ready to send.
     */
    public function whatsappUrl(Quote $quote, string $body): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($quote->enquiry->customer->phone ?? ''));
        if (! $digits) {
            return null;
        }
        if (strlen($digits) === 10) {
            $digits = '91'.$digits; // Indian mobile without country code.
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($body);
    }

    private function prefillExtras(Quote $quote, Enquiry $enquiry): void
    {
        $pieces = (int) $enquiry->lines->sum('quantity');
        $areaM2 = $enquiry->lines->sum(fn (EnquiryLine $l) => $l->width_mm * $l->height_mm / 1_000_000 * $l->quantity);

        $charges = ExtraCharge::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn (ExtraCharge $c) => $c->type !== ExtraChargeType::Installation || $enquiry->installation_needed);

        foreach ($charges->values() as $i => $charge) {
            $quote->extras()->create([
                'extra_charge_id' => $charge->id,
                'position' => $i + 1,
                'name' => $charge->name,
                'inclusion' => 'charged',
                'amount' => (int) round(match ($charge->pricing_type) {
                    ExtraChargeBasis::PerPiece => $charge->cost * $pieces,
                    ExtraChargeBasis::PerSquareMetre => $charge->cost * $areaM2,
                    default => $charge->cost,
                }),
            ]);
        }
    }

    public function specText(EnquiryLine $line): string
    {
        $parts = [
            $line->source_type->getLabel(),
            $line->printSizeLabel().($line->size_reference->value === 'outer' ? ' (outer)' : ''),
            $line->rotationLabel(),
            // Framing the customer's own piece prints nothing: no print medium on the quote.
            $line->isFrameOnly() ? null : $line->medium?->name,
            $line->substrate?->name,
            // e.g. "Frame: Blue ribbed 20 mm + Gold 30 mm around · Frame 1 split: top half / bottom half Walnut"
            $line->frame ? 'Frame: '.$line->frameLayersLabel() : ($line->frame_advice_requested ? 'Frame: to be advised' : 'No frame'),
            $line->mat ? "Mat: {$line->mat->name}" : null,
            $line->glazing?->name,
        ];

        return collect($parts)->filter()->implode(' · ');
    }
}
