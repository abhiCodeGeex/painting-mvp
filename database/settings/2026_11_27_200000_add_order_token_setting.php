<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Fixed token amount the customer pays when placing an order (paise); the rest is due on delivery.
        $this->migrator->add('pricing.order_token_amount', 500_000); // ₹5,000
    }
};
