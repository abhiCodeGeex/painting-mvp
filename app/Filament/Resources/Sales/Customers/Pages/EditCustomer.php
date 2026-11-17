<?php

namespace App\Filament\Resources\Sales\Customers\Pages;

use App\Domain\Customers\CustomerAccounts;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendPasswordLink')
                ->label('Send password link')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('The customer gets an email with a link to set a new password.')
                ->action(function (): void {
                    Password::broker()->sendResetLink(['email' => $this->record->user->email]);
                    Notification::make()->title('Password link sent')->success()->send();
                }),
            DeleteAction::make()->label('Archive'),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Customer $customer */
        $customer = $this->record;

        return [
            ...$data,
            'name' => $customer->user?->name,
            'email' => $customer->user?->email,
            'is_active' => $customer->user?->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Customer $record */
        return app(CustomerAccounts::class)->update($record, [...$data, 'is_active' => $this->data['is_active'] ?? true]);
    }
}
