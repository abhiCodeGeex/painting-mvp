<?php

namespace App\Filament\Resources\Lists\RejectionReasons;

use App\Filament\Resources\Lists\RejectionReasons\Pages\ManageRejectionReasons;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\RejectionReason;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Reasons a customer can pick when rejecting a quote (spec §9.3).
 */
class RejectionReasonResource extends MasterResource
{
    protected static ?string $model = RejectionReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_LISTS;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Price too high'),
            Toggle::make('requires_comment')
                ->label('Customer must add a comment')
                ->helperText('Use for "Other" so we learn the actual reason.'),
            ...MasterForm::status(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            IconColumn::make('requires_comment')->label('Comment required')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRejectionReasons::route('/'),
        ];
    }
}
