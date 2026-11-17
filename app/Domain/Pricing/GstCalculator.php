<?php

namespace App\Domain\Pricing;

use App\Models\Tax;
use App\Settings\CompanySettings;

/**
 * Indian GST on a taxable amount (spec §7.3).
 * Same state as the company → CGST + SGST (half each); another state → IGST.
 */
class GstCalculator
{
    public function __construct(private readonly CompanySettings $company) {}

    /**
     * @return array{rate: float, intra_state: bool, cgst: int, sgst: int, igst: int, tax: int, taxable: int, grand_total: int, tax_name: ?string}
     */
    public function calculate(int $taxable, ?string $deliveryStateCode, bool $exempt = false, ?float $rate = null): array
    {
        $tax = $rate === null ? Tax::query()->where('is_active', true)->where('is_default', true)->first() : null;
        $rate = $exempt ? 0.0 : ($rate ?? (float) ($tax?->rate_percent ?? 0));

        // Unknown delivery state: treat as same state (the common case for local customers).
        $intra = ! $deliveryStateCode || ! $this->company->state_code || $deliveryStateCode === $this->company->state_code;

        $total = (int) round($taxable * $rate / 100);
        $cgst = $intra ? intdiv($total, 2) + $total % 2 : 0;
        $sgst = $intra ? $total - $cgst : 0;

        return [
            'rate' => $rate,
            'tax_name' => $tax?->name,
            'intra_state' => $intra,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $intra ? 0 : $total,
            'tax' => $total,
            'taxable' => $taxable,
            'grand_total' => $taxable + $total,
        ];
    }
}
