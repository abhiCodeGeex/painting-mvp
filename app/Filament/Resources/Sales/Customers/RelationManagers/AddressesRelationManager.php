<?php

namespace App\Filament\Resources\Sales\Customers\RelationManagers;

use App\Enums\AddressKind;
use App\Support\IndianIds;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(self::fields());
    }

    /**
     * Address fields, shared with the enquiry screen.
     *
     * @return array<int, mixed>
     */
    public static function fields(): array
    {
        return [
            Select::make('kind')->options(AddressKind::class)->default(AddressKind::Delivery->value)->required()->native(false),
            TextInput::make('label')->placeholder('e.g. Home, Mumbai office, Hotel site')->maxLength(100),
            TextInput::make('contact_name')->maxLength(255),
            TextInput::make('phone')->tel()->regex(IndianIds::PHONE_REGEX),
            TextInput::make('line1')->label('Address line 1')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('line2')->label('Address line 2')->maxLength(255)->columnSpanFull(),
            TextInput::make('city')->required()->maxLength(100),
            TextInput::make('pin_code')->label('PIN code')->required()->regex(IndianIds::PIN_REGEX),
            Select::make('state_id')
                ->label('State')
                ->relationship('state', 'name', fn (Builder $query) => $query->active()->ordered())
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('access_notes')->placeholder('e.g. No lift, 3rd floor')->maxLength(255),
            Toggle::make('is_default')->label('Default for this type'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('kind')->badge()->color('gray'),
                TextColumn::make('label')->placeholder('—'),
                TextColumn::make('one_line')
                    ->label('Address')
                    ->state(fn ($record) => $record->oneLine())
                    ->wrap(),
                TextColumn::make('contact_name')->label('Contact')->placeholder('—'),
                IconColumn::make('is_default')->label('Default')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('state'));
    }
}
