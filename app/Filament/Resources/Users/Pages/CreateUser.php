<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Enums\UserType;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Users created here are always staff; their email is trusted, so it is marked verified.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = UserType::Staff;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->forceFill(['email_verified_at' => now()])->save();
        $this->record->assignRole(Role::SuperAdmin->value);
    }
}
