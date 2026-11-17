<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Company details used on quotes/PDFs and for GST place-of-supply (spec §14).
 * Editable from the admin panel in M1.
 */
class CompanySettings extends Settings
{
    public string $name;

    public ?string $address_line1;

    public ?string $address_line2;

    public ?string $city;

    public ?string $pin_code;

    /** GST state code of the company's registered state, e.g. "27" for Maharashtra. */
    public ?string $state_code;

    public ?string $gstin;

    public ?string $pan;

    public ?string $email;

    public ?string $phone;

    public ?string $website;

    public ?string $bank_name;

    public ?string $bank_account_name;

    public ?string $bank_account_number;

    public ?string $bank_ifsc;

    /** cm or inch */
    public string $default_unit;

    public static function group(): string
    {
        return 'company';
    }
}
