<?php

namespace App\Filament\Resources\Framing\Frames\Tables;

use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Filament\Support\MasterTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FramesTable
{
    public static function configure(Table $table): Table
    {
        return MasterTable::configure($table, [
            ViewColumn::make('preview')
                ->label('Preview')
                ->view('filament.columns.frame-preview'),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('material'),
            TextColumn::make('finish'),
            MasterTable::mm('profile_width_mm', 'Width'),
            MasterTable::mm('profile_depth_mm', 'Depth'),
            MasterTable::money('price_per_m', 'Cost / m'),
        ], [
            SelectFilter::make('material')->options(FrameMaterial::class),
            SelectFilter::make('finish')->options(FrameFinish::class),
        ]);
    }
}
