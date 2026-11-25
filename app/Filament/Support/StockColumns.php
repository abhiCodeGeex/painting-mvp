<?php

namespace App\Filament\Support;

use App\Enums\StockStatus;
use App\Filament\Resources\Catalog\Substrates\SubstrateResource;
use App\Filament\Resources\Framing\Frames\FrameResource;
use App\Filament\Resources\Framing\Glazings\GlazingResource;
use App\Filament\Resources\Framing\Mats\MatResource;
use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\StockItem;
use App\Models\Substrate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Stock is managed inside each item's own listing (frames, mats, glazing, substrates):
 * stock columns, a stock filter, row actions Refill / Reduce / Alert level / History and a
 * bulk "Set alert level". Every change goes through StockItem::adjust(), so it is logged as a
 * StockMovement and the low-stock alert fires as before.
 *
 * Actions accept either the catalogue item (Frame, Mat…) or its StockItem, so the dashboard
 * widget reuses them.
 */
class StockColumns
{
    /**
     * Where each stocked item type is managed.
     *
     * @var array<class-string<Model>, class-string>
     */
    public const RESOURCES = [
        Frame::class => FrameResource::class,
        Mat::class => MatResource::class,
        Glazing::class => GlazingResource::class,
        Substrate::class => SubstrateResource::class,
    ];

    /**
     * Filter value for "low or out of stock".
     */
    public const ATTENTION = 'attention';

    /**
     * Add the stock filter, actions, row tint and eager-loading to an item listing. Put
     * StockColumns::columns() in the listing's columns where the stock should show.
     */
    public static function apply(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('stockItem'))
            ->pushFilters([self::filter()])
            ->pushRecordActions([
                self::refill(),
                self::reduce(),
                ActionGroup::make([
                    self::alertLevel(),
                    self::history(),
                ])->label('Stock')->icon('heroicon-m-archive-box')->color('gray')->tooltip('Stock'),
            ])
            ->pushToolbarActions([self::bulkAlertLevel()])
            ->recordClasses(fn (Model $record): ?string => match (self::stockOf($record)?->status()) {
                StockStatus::Out => 'stock-row-out',
                StockStatus::Low => 'stock-row-low',
                default => null,
            });
    }

    /**
     * In stock (quantity + unit, coloured by status), status badge and alert level.
     *
     * @return array<int, TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('stock_on_hand')
                ->label('In stock')
                ->state(fn (Model $record): ?string => self::stockOf($record)?->formattedQuantity())
                ->badge()
                ->color(fn (Model $record): string => self::stockOf($record)?->status()->getColor() ?? 'gray')
                ->icon(fn (Model $record): ?string => ($status = self::stockOf($record)?->status()) && $status !== StockStatus::Ok
                    ? $status->getIcon()
                    : null)
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(self::stockSubquery($query, 'quantity'), $direction))
                ->alignEnd()
                ->placeholder('—')
                ->visible(fn (): bool => self::canView()),
            TextColumn::make('stock_status')
                ->label('Stock status')
                ->state(fn (Model $record): ?StockStatus => self::stockOf($record)?->status())
                ->badge()
                ->placeholder('—')
                ->toggleable()
                ->visible(fn (): bool => self::canView()),
            TextColumn::make('stock_alert_level')
                ->label('Alert under')
                ->state(fn (Model $record): ?string => ($stock = self::stockOf($record))
                    ? StockItem::formatQuantity($stock->low_stock_threshold, $stock->unit)
                    : null)
                ->alignEnd()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true)
                ->visible(fn (): bool => self::canView()),
        ];
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('stock')
            ->label('Stock')
            ->options([
                self::ATTENTION => 'Low or out of stock',
                StockStatus::Low->value => 'Low stock',
                StockStatus::Out->value => 'Out of stock',
                StockStatus::Ok->value => 'In stock',
            ])
            ->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;

                return match (true) {
                    $value === self::ATTENTION => $query->whereHas('stockItem', fn (Builder $q) => $q->needsAttention()),
                    filled($value) && StockStatus::tryFrom($value) !== null => $query->whereHas('stockItem', fn (Builder $q) => $q->withStatus(StockStatus::from($value))),
                    default => $query,
                };
            })
            ->visible(fn (): bool => self::canView());
    }

    public static function refill(): Action
    {
        return Action::make('refillStock')
            ->label('Refill')
            ->icon('heroicon-m-plus-circle')
            ->color('success')
            ->visible(fn (Model $record): bool => self::canChange($record))
            ->authorize(fn (Model $record): bool => self::canChange($record))
            ->modalHeading(fn (Model $record): string => 'Refill stock — '.self::stockOf($record)->name())
            ->modalDescription(fn (Model $record): string => 'Now '.self::stockOf($record)->formattedQuantity().' in stock.')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Add to stock')
            ->schema([
                self::quantityInput('Quantity to add'),
                TextInput::make('note')
                    ->label('Note')
                    ->maxLength(255)
                    ->placeholder('e.g. Supplier delivery, invoice 1234'),
            ])
            ->action(function (Model $record, array $data): void {
                $stock = self::stockOf($record);
                $stock->adjust(round((float) $data['quantity'], 2), filled($data['note'] ?? null) ? $data['note'] : 'Refilled', auth()->user());
                self::notifyUpdated($stock);
            });
    }

    public static function reduce(): Action
    {
        return Action::make('reduceStock')
            ->label('Reduce')
            ->icon('heroicon-m-minus-circle')
            ->color('warning')
            ->visible(fn (Model $record): bool => self::canChange($record))
            ->authorize(fn (Model $record): bool => self::canChange($record))
            ->modalHeading(fn (Model $record): string => 'Reduce stock — '.self::stockOf($record)->name())
            ->modalDescription(fn (Model $record): string => 'Now '.self::stockOf($record)->formattedQuantity().' in stock.')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Remove from stock')
            ->schema([
                self::quantityInput('Quantity to remove')
                    ->maxValue(fn ($record): float => (float) self::stockOf($record)?->quantity),
                TextInput::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(255)
                    ->datalist(['Used on job', 'Damaged', 'Offcut / wastage', 'Returned to supplier', 'Stock count correction'])
                    ->placeholder('e.g. Used on Q-1042, damaged'),
            ])
            ->action(function (Model $record, array $data, Action $action): void {
                $stock = self::stockOf($record);

                try {
                    $stock->adjust(-round((float) $data['quantity'], 2), $data['reason'], auth()->user());
                } catch (ValidationException $e) {
                    // Someone else used stock since the form opened.
                    Notification::make()->title('Stock not changed')->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();
                }

                self::notifyUpdated($stock);
            });
    }

    public static function alertLevel(): Action
    {
        return Action::make('stockAlertLevel')
            ->label('Set alert level')
            ->icon('heroicon-m-bell-alert')
            ->color('gray')
            ->visible(fn (Model $record): bool => self::canChange($record))
            ->authorize(fn (Model $record): bool => self::canChange($record))
            ->modalHeading(fn (Model $record): string => 'Low-stock alert level — '.self::stockOf($record)->name())
            ->modalWidth('md')
            ->fillForm(fn (Model $record): array => ['low_stock_threshold' => self::stockOf($record)->low_stock_threshold])
            ->schema([self::thresholdInput()])
            ->action(function (Model $record, array $data): void {
                self::stockOf($record)->update(['low_stock_threshold' => round((float) $data['low_stock_threshold'], 2)]);

                Notification::make()->title('Alert level saved')->success()->send();
            });
    }

    public static function history(): Action
    {
        return Action::make('stockHistory')
            ->label('Stock history')
            ->icon('heroicon-m-clock')
            ->color('gray')
            ->visible(fn (Model $record): bool => self::stockOf($record) !== null && self::canView())
            ->modalHeading(fn (Model $record): string => 'Stock history — '.self::stockOf($record)->name())
            ->modalContent(fn (Model $record) => view('filament.inventory.stock-history', [
                'item' => self::stockOf($record),
                'movements' => self::stockOf($record)->movements()->with('user')->limit(50)->get(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(fn (Model $record): array => [
                Action::make('allMovements')
                    ->label('Full history')
                    ->icon('heroicon-m-arrows-right-left')
                    ->color('gray')
                    ->url(StockMovementResource::getUrl('index', ['search' => self::stockOf($record)->name()])),
            ]);
    }

    /**
     * Same alert level on several items at once (in each item's own unit).
     */
    public static function bulkAlertLevel(): BulkAction
    {
        return BulkAction::make('bulkStockAlertLevel')
            ->label('Set alert level')
            ->icon('heroicon-m-bell-alert')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('Update:StockItem'))
            ->authorize(fn (): bool => (bool) auth()->user()?->can('Update:StockItem'))
            ->schema([
                self::thresholdInput()
                    ->default(StockItem::DEFAULT_THRESHOLD)
                    ->helperText('In each item\'s own unit (metres, sheets, rolls…). Admins are notified when stock drops below it.'),
            ])
            ->action(function (Collection $records, array $data): void {
                $threshold = round((float) $data['low_stock_threshold'], 2);
                // Saved one by one so items that become low because of it still alert.
                $items = $records->map(fn (Model $record) => self::stockOf($record))
                    ->filter(fn (?StockItem $stock): bool => $stock !== null && (bool) auth()->user()?->can('update', $stock));
                $items->each(fn (StockItem $stock) => $stock->update(['low_stock_threshold' => $threshold]));

                Notification::make()->title("Alert level set on {$items->count()} items")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The item's own listing, filtered to the item (by search) and optionally a stock status.
     */
    public static function listingUrl(StockItem $stock, ?string $status = self::ATTENTION): ?string
    {
        $resource = self::RESOURCES[$stock->stockable_type] ?? null;

        if ($resource === null) {
            return null;
        }

        return $resource::getUrl('index', array_filter([
            'search' => $stock->name(),
            'filters' => $status ? ['stock' => ['value' => $status]] : null,
        ]), panel: 'admin');
    }

    /**
     * Listing of the given type, filtered by stock status.
     *
     * @param  class-string<Model>  $type
     */
    public static function typeListingUrl(string $type, string $status = self::ATTENTION): string
    {
        return self::RESOURCES[$type]::getUrl('index', ['filters' => ['stock' => ['value' => $status]]], panel: 'admin');
    }

    /**
     * @return array{low: int, out: int}
     */
    public static function attentionCounts(): array
    {
        $row = StockItem::query()->current()
            ->selectRaw('SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_count')
            ->selectRaw('SUM(CASE WHEN quantity > 0 AND quantity < low_stock_threshold THEN 1 ELSE 0 END) AS low_count')
            ->toBase()
            ->first();

        return ['low' => (int) ($row->low_count ?? 0), 'out' => (int) ($row->out_count ?? 0)];
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('viewAny', StockItem::class);
    }

    public static function stockOf(?Model $record): ?StockItem
    {
        if ($record === null || $record instanceof StockItem) {
            return $record;
        }

        // Listings eager-load it; load explicitly otherwise (e.g. records of a bulk action).
        return $record->loadMissing('stockItem')->getRelation('stockItem');
    }

    private static function canChange(Model $record): bool
    {
        $stock = self::stockOf($record);

        return $stock !== null
            && ! (method_exists($record, 'trashed') && $record->trashed())
            && (bool) auth()->user()?->can('update', $stock);
    }

    private static function quantityInput(string $label): TextInput
    {
        return TextInput::make('quantity')
            ->label($label)
            ->numeric()
            // The browser counts valid steps from the minimum, so whole-piece units start at 1.
            ->minValue(fn ($record): float => self::stockOf($record)?->unit?->allowsFractions() ? 0.01 : 1)
            ->step(fn ($record): string => self::stockOf($record)?->unit?->allowsFractions() ? '0.01' : '1')
            ->integer(fn ($record): bool => ! self::stockOf($record)?->unit?->allowsFractions())
            ->suffix(fn ($record): string => self::stockOf($record)?->unit?->getLabel() ?? '')
            ->required()
            ->autofocus();
    }

    private static function thresholdInput(): TextInput
    {
        return TextInput::make('low_stock_threshold')
            ->label('Warn when stock is under')
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->suffix(fn ($record): string => $record ? (self::stockOf($record)?->unit?->getLabel() ?? '') : '')
            ->helperText('Admins are notified when stock drops below this level. Default '.StockItem::DEFAULT_THRESHOLD.'.')
            ->required();
    }

    private static function notifyUpdated(StockItem $stock): void
    {
        $stock->refresh();
        $status = $stock->status();

        Notification::make()
            ->title("Stock updated: {$stock->formattedQuantity()}")
            ->body($stock->name().($status !== StockStatus::Ok ? ' — '.$status->getLabel() : ''))
            ->color($status->getColor())
            ->icon($status->getIcon())
            ->iconColor($status->getColor())
            ->send();
    }

    private static function stockSubquery(Builder $query, string $column): Builder
    {
        $model = $query->getModel();

        return StockItem::query()
            ->select($column)
            ->whereColumn('stock_items.stockable_id', $model->getQualifiedKeyName())
            ->where('stock_items.stockable_type', $model->getMorphClass())
            ->limit(1);
    }
}
