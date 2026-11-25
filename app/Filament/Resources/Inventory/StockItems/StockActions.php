<?php

namespace App\Filament\Resources\Inventory\StockItems;

use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use App\Models\StockItem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Row actions shared by the inventory list and the dashboard widget.
 */
class StockActions
{
    public static function adjust(): Action
    {
        return Action::make('adjustStock')
            ->label('Adjust stock')
            ->icon('heroicon-m-arrows-up-down')
            ->color('primary')
            ->authorize(fn (StockItem $record): bool => (bool) auth()->user()?->can('update', $record))
            ->modalHeading(fn (StockItem $record): string => "Adjust stock — {$record->name()}")
            ->modalDescription(fn (StockItem $record): string => "{$record->typeLabel()} · now {$record->formattedQuantity()} in stock")
            ->modalWidth('md')
            ->schema([
                ToggleButtons::make('direction')
                    ->label('What happened?')
                    ->options([
                        'add' => 'Received',
                        'remove' => 'Used / removed',
                        'count' => 'Stock count',
                    ])
                    ->icons([
                        'add' => 'heroicon-m-plus',
                        'remove' => 'heroicon-m-minus',
                        'count' => 'heroicon-m-clipboard-document-check',
                    ])
                    ->colors(['add' => 'success', 'remove' => 'warning', 'count' => 'gray'])
                    ->default('add')
                    ->inline()
                    ->live()
                    ->required(),
                TextInput::make('quantity')
                    ->label(fn (Get $get): string => $get('direction') === 'count' ? 'Counted quantity' : 'Quantity')
                    ->helperText(fn (Get $get): ?string => $get('direction') === 'count' ? 'Sets stock to exactly this amount.' : null)
                    ->numeric()
                    // The browser counts valid steps from the minimum, so whole-piece units start at 1.
                    ->minValue(fn (Get $get, StockItem $record): float => match (true) {
                        $get('direction') === 'count' => 0,
                        (bool) $record->unit?->allowsFractions() => 0.01,
                        default => 1,
                    })
                    ->step(fn (StockItem $record): string => $record->unit?->allowsFractions() ? '0.01' : '1')
                    ->integer(fn (StockItem $record): bool => ! $record->unit?->allowsFractions())
                    ->suffix(fn (StockItem $record): string => $record->unit?->getLabel() ?? '')
                    ->required()
                    ->autofocus(),
                TextInput::make('note')
                    ->maxLength(255)
                    ->placeholder('e.g. Supplier delivery, used on Q-1042, damaged'),
            ])
            ->action(function (StockItem $record, array $data): void {
                $quantity = round((float) $data['quantity'], 2);
                $note = filled($data['note'] ?? null) ? $data['note'] : null;
                $user = auth()->user();

                match ($data['direction']) {
                    'add' => $record->adjust($quantity, $note ?? 'Received', $user),
                    'remove' => $record->adjust(-$quantity, $note ?? 'Used', $user),
                    default => $record->countAs($quantity, $note, $user),
                };

                $record->refresh();

                Notification::make()
                    ->title("Stock updated: {$record->formattedQuantity()}")
                    ->body($record->name())
                    ->color($record->status()->getColor())
                    ->icon($record->status()->getIcon())
                    ->iconColor($record->status()->getColor())
                    ->send();
            });
    }

    public static function threshold(): Action
    {
        return Action::make('editThreshold')
            ->label('Alert level')
            ->icon('heroicon-m-bell-alert')
            ->color('gray')
            ->authorize(fn (StockItem $record): bool => (bool) auth()->user()?->can('update', $record))
            ->modalHeading(fn (StockItem $record): string => "Low-stock alert level — {$record->name()}")
            ->modalWidth('md')
            ->fillForm(fn (StockItem $record): array => ['low_stock_threshold' => $record->low_stock_threshold])
            ->schema([
                TextInput::make('low_stock_threshold')
                    ->label('Warn when stock is under')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix(fn (StockItem $record): string => $record->unit?->getLabel() ?? '')
                    ->helperText('Admins are notified when stock drops below this level. Default '.StockItem::DEFAULT_THRESHOLD.'.')
                    ->required(),
            ])
            ->action(fn (StockItem $record, array $data) => $record->update(['low_stock_threshold' => round((float) $data['low_stock_threshold'], 2)]));
    }

    public static function history(): Action
    {
        return Action::make('history')
            ->label('History')
            ->icon('heroicon-m-clock')
            ->color('gray')
            ->modalHeading(fn (StockItem $record): string => "Stock history — {$record->name()}")
            ->modalContent(fn (StockItem $record) => view('filament.inventory.stock-history', [
                'item' => $record,
                'movements' => $record->movements()->with('user')->limit(50)->get(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(fn (StockItem $record): array => [
                Action::make('allMovements')
                    ->label('All movements')
                    ->icon('heroicon-m-arrows-right-left')
                    ->color('gray')
                    ->url(StockMovementResource::getUrl('index', ['search' => $record->name()])),
            ]);
    }

    /**
     * Set the same alert level on several items at once.
     */
    public static function bulkThreshold(): BulkAction
    {
        return BulkAction::make('bulkThreshold')
            ->label('Set alert level')
            ->icon('heroicon-m-bell-alert')
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('Update:StockItem'))
            ->schema([
                TextInput::make('low_stock_threshold')
                    ->label('Warn when stock is under')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(StockItem::DEFAULT_THRESHOLD)
                    ->helperText('In each item\'s own unit (metres, sheets, rolls…).')
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $threshold = round((float) $data['low_stock_threshold'], 2);
                // Saved one by one so items that become low because of it still alert.
                $records->each(fn (StockItem $item) => $item->update(['low_stock_threshold' => $threshold]));

                Notification::make()->title("Alert level set on {$records->count()} items")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Download the stock list (as currently filtered) as CSV, e.g. to send a re-order to a supplier.
     */
    public static function exportCsv(): Action
    {
        return Action::make('exportCsv')
            ->label('Download CSV')
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->action(function (HasTable $livewire): StreamedResponse {
                $items = $livewire->getFilteredTableQuery()->with('stockable')->get();

                return response()->streamDownload(function () use ($items): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['Type', 'Code', 'Item', 'In stock', 'Unit', 'Alert under', 'Status', 'Last change']);
                    foreach ($items as $item) {
                        fputcsv($out, [
                            $item->typeLabel(),
                            $item->code(),
                            $item->name(),
                            $item->quantity,
                            $item->unit?->getLabel(),
                            $item->low_stock_threshold,
                            $item->status()->getLabel(),
                            $item->updated_at?->format('Y-m-d H:i'),
                        ]);
                    }
                    fclose($out);
                }, 'stock-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
            });
    }
}
