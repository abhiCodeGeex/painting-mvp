<?php

namespace App\Filament\Resources\Lists\States;

use App\Filament\Resources\Lists\States\Pages\ManageStates;
use App\Models\State;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Indian states & UTs with GST state codes. Seeded; normally only activated/deactivated.
 */
class StateResource extends Resource
{
    protected static ?string $model = State::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_LISTS;

    protected static ?string $navigationLabel = 'States (GST)';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('gst_code')
                ->label('GST state code')
                ->required()
                ->length(2)
                ->regex('/^\d{2}$/')
                ->unique(ignoreRecord: true)
                ->helperText('Two digits, e.g. 27 for Maharashtra.'),
            Toggle::make('is_union_territory')->label('Union territory'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('gst_code')->label('GST code')->sortable()->searchable(),
                TextColumn::make('name')->sortable()->searchable(),
                IconColumn::make('is_union_territory')->label('UT')->boolean(),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->defaultSort('gst_code')
            ->filters([
                TernaryFilter::make('is_union_territory')->label('Union territory'),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->paginated([25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStates::route('/'),
        ];
    }
}
