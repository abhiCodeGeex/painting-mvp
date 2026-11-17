<?php

namespace App\Filament\Resources\Framing\Frames;

use App\Filament\Resources\Framing\Frames\Pages\CreateFrame;
use App\Filament\Resources\Framing\Frames\Pages\EditFrame;
use App\Filament\Resources\Framing\Frames\Pages\ListFrames;
use App\Filament\Resources\Framing\Frames\Schemas\FrameForm;
use App\Filament\Resources\Framing\Frames\Tables\FramesTable;
use App\Filament\Support\MasterResource;
use App\Models\Frame;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Frame mouldings. Dimensions, colour and finish also drive the 3D wall preview.
 */
class FrameResource extends MasterResource
{
    protected static ?string $model = Frame::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_FRAMING;

    protected static ?string $navigationLabel = 'Frames (mouldings)';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'supplier_code'];
    }

    public static function form(Schema $schema): Schema
    {
        return FrameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FramesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFrames::route('/'),
            'create' => CreateFrame::route('/create'),
            'edit' => EditFrame::route('/{record}/edit'),
        ];
    }
}
