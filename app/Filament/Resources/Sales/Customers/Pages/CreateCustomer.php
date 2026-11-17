<?php

namespace App\Filament\Resources\Sales\Customers\Pages;

use App\Domain\Customers\CustomerAccounts;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * Creates the user account and customer profile together. Staff-created customers are
     * trusted (email marked verified) and can be emailed an invitation to set a password.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CustomerAccounts::class)->create(
            $data,
            verified: true,
            sendInvitation: (bool) ($this->data['send_invitation'] ?? false),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
