<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Orders are paid as a token when placed and the balance on delivery; keep any wording staff wrote themselves.
        $this->migrator->update('pricing.default_payment_terms', fn (string $terms) => $terms === '50% advance on approval, balance before dispatch.'
            ? 'Token amount when you place the order; the full & final balance on delivery.'
            : $terms);
    }
};
