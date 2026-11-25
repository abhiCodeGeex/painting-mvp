<?php

namespace App\Filament\Resources\Framing\Frames\Tables;

use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Enums\FrameProfile;
use App\Filament\Support\MasterTable;
use App\Models\Frame;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FramesTable
{
    public static function configure(Table $table): Table
    {
        return MasterTable::configure($table, [
            SpatieMediaLibraryImageColumn::make('corner')
                ->label('Photo')
                ->collection('corner')
                ->conversion('thumb')
                ->imageSize(84)
                ->extraImgAttributes(['class' => 'rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10']),
            ViewColumn::make('preview')
                ->label('On a 30 × 40 print')
                ->view('filament.columns.frame-preview')
                ->toggleable(),
            TextColumn::make('name')
                ->searchable()
                ->weight('semibold')
                ->description(fn (Frame $record) => $record->code),
            TextColumn::make('code')->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('profile')->label('Shape')->badge()->color('gray'),
            TextColumn::make('material'),
            TextColumn::make('finish'),
            MasterTable::mm('profile_width_mm', 'Width'),
            MasterTable::mm('profile_depth_mm', 'Depth')->toggleable(),
            MasterTable::money('price_per_m', 'Cost / m'),
            MasterTable::stock(),
        ], [
            SelectFilter::make('material')->options(FrameMaterial::class),
            SelectFilter::make('finish')->options(FrameFinish::class),
            SelectFilter::make('profile')->label('Shape')->options(FrameProfile::class),
        ])->modifyQueryUsing(fn ($query) => $query->with(['media', 'stockItem']));
    }
}
