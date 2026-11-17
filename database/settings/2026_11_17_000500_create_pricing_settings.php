<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('pricing.labour_fixed_per_piece', 30000);      // ₹300
        $this->migrator->add('pricing.labour_per_perimeter_m', 5000);       // ₹50 per metre
        $this->migrator->add('pricing.default_markup_percent', 40.0);
        $this->migrator->add('pricing.quantity_breaks', [
            ['min_qty' => 10, 'discount_percent' => 5.0],
            ['min_qty' => 25, 'discount_percent' => 8.0],
            ['min_qty' => 50, 'discount_percent' => 12.0],
        ]);
        $this->migrator->add('pricing.rush_fee_percent', 15.0);
        $this->migrator->add('pricing.rush_threshold_days', 14);
        $this->migrator->add('pricing.rounding', '1');
        $this->migrator->add('pricing.estimate_range_percent', 15.0);
        $this->migrator->add('pricing.mat_overlap_mm', 3);
        $this->migrator->add('pricing.rebate_allowance_mm', 2);
    }
};
