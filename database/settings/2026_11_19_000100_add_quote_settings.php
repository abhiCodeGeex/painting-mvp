<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Artist fee for a new creation, per m² of artwork (before medium / quality / complexity factors).
        $this->migrator->add('pricing.commission_price_per_m2', 6_000_000); // ₹60,000 per m²
        // What the customer sees while building an enquiry: exact | range | hidden.
        $this->migrator->add('pricing.customer_estimate', 'range');

        $this->migrator->add('pricing.quote_validity_days', 30);
        $this->migrator->add('pricing.default_lead_time', '3–4 weeks after approval');
        $this->migrator->add('pricing.default_payment_terms', '50% advance on approval, balance before dispatch.');
        $this->migrator->add('pricing.default_quote_terms', "Prices are in INR and include packing unless stated.\nColours and textures in previews are indicative.\nDelivery timelines start after the advance payment is received.");
    }
};
