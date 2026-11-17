<?php

namespace App\Domain\Pricing;

use App\Models\Enquiry;
use App\Models\EnquiryLine;
use App\Settings\PricingSettings;

/**
 * The customer-facing version of the pricing engine: selling prices only (never costs or margins),
 * shown as an exact figure or a range depending on the "customer_estimate" setting.
 */
class CustomerEstimate
{
    public function __construct(
        private readonly PricingEngine $engine,
        private readonly GstCalculator $gst,
        private readonly PricingSettings $rules,
    ) {}

    public function mode(): string
    {
        return in_array($this->rules->customer_estimate, ['exact', 'range', 'hidden'], true) ? $this->rules->customer_estimate : 'range';
    }

    /**
     * @return array<string, mixed>
     */
    public function forLine(LineConfig $config, ?Enquiry $enquiry = null): array
    {
        if ($this->mode() === 'hidden') {
            return ['mode' => 'hidden'];
        }

        $r = $this->engine->price($config, $enquiry?->required_by);
        $isRange = $this->mode() === 'range' || $r['estimate']['unit_low'] !== $r['estimate']['unit_high'] && ! $r['complete'];

        return [
            'mode' => $this->mode(),
            'is_range' => $isRange,
            'available' => $r['unit_sell'] > 0,
            'complete' => $r['complete'],
            'pending' => $r['pending'],
            'quantity' => $r['quantity'],
            'unit_price' => $r['unit_price'],
            'line_total' => $r['line_total'],
            'unit_low' => $isRange ? $r['estimate']['unit_low'] : $r['unit_price'],
            'unit_high' => $isRange ? $r['estimate']['unit_high'] : $r['unit_price'],
            'line_low' => $isRange ? $r['estimate']['line_low'] : $r['line_total'],
            'line_high' => $isRange ? $r['estimate']['line_high'] : $r['line_total'],
            'quantity_discount_percent' => $r['quantity_discount_percent'],
            'rush_fee_percent' => $r['rush_fee_percent'],
            'items' => array_map(fn ($c) => ['label' => $c['label'], 'amount' => $c['sell']], $r['components']),
            'outer_size' => ['width_mm' => $r['dimensions']['outer_w'], 'height_mm' => $r['dimensions']['outer_h']],
        ];
    }

    /**
     * Every line plus the enquiry total with GST for the delivery state.
     *
     * @return array<string, mixed>
     */
    public function forEnquiry(Enquiry $enquiry): array
    {
        if ($this->mode() === 'hidden') {
            return ['mode' => 'hidden', 'lines' => []];
        }

        $enquiry->loadMissing(['lines.finishings', 'deliveryAddress.state', 'customer']);
        $lines = $enquiry->lines->mapWithKeys(fn (EnquiryLine $l) => [$l->id => $this->forLine(LineConfig::fromLine($l), $enquiry)]);

        $low = (int) $lines->sum('line_low');
        $high = (int) $lines->sum('line_high');
        $state = $enquiry->deliveryAddress?->state?->gst_code;
        $exempt = (bool) $enquiry->customer?->tax_exempt;
        $gstLow = $this->gst->calculate($low, $state, $exempt);
        $gstHigh = $this->gst->calculate($high, $state, $exempt);

        return [
            'mode' => $this->mode(),
            'lines' => $lines->all(),
            'is_range' => $low !== $high,
            'complete' => $lines->every(fn ($l) => $l['complete']),
            'subtotal_low' => $low,
            'subtotal_high' => $high,
            'tax_rate' => $gstLow['rate'],
            'intra_state' => $gstLow['intra_state'],
            'tax_low' => $gstLow['tax'],
            'tax_high' => $gstHigh['tax'],
            'total_low' => $gstLow['grand_total'],
            'total_high' => $gstHigh['grand_total'],
        ];
    }
}
