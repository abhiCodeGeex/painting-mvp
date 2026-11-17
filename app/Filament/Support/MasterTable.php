<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Shared table setup for masters (spec §4.1): active toggle, drag-to-reorder by sort_order,
 * Active + Trashed filters, edit / archive / restore actions. No force delete.
 */
class MasterTable
{
    /**
     * @param  array<int, mixed>  $columns
     * @param  array<int, mixed>  $filters
     */
    public static function configure(Table $table, array $columns, array $filters = [], bool $reorderable = true): Table
    {
        $table = $table
            ->columns([
                ...$columns,
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                ...$filters,
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make()->label('Archived'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Archive')->modalHeading('Archive this record?')
                    ->modalDescription('It will be hidden from new enquiries but stays on existing records. You can restore it later.'),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Archive selected'),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->striped();

        return $reorderable ? $table->reorderable('sort_order') : $table;
    }

    public static function money(string $name, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn ($state): string => Money::format((int) $state))
            ->alignEnd()
            ->sortable();
    }

    public static function mm(string $name, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->suffix(' mm')
            ->numeric()
            ->alignEnd()
            ->placeholder('—');
    }
}
