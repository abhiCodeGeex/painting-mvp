<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.name', 'Paint MVP');
        $this->migrator->add('company.address_line1', null);
        $this->migrator->add('company.address_line2', null);
        $this->migrator->add('company.city', null);
        $this->migrator->add('company.pin_code', null);
        $this->migrator->add('company.state_code', null);
        $this->migrator->add('company.gstin', null);
        $this->migrator->add('company.pan', null);
        $this->migrator->add('company.email', null);
        $this->migrator->add('company.phone', null);
        $this->migrator->add('company.website', null);
        $this->migrator->add('company.bank_name', null);
        $this->migrator->add('company.bank_account_name', null);
        $this->migrator->add('company.bank_account_number', null);
        $this->migrator->add('company.bank_ifsc', null);
        $this->migrator->add('company.default_unit', 'cm');
    }
};
