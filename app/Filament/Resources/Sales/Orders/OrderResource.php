<?php

namespace App\Filament\Resources\Sales\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Filament\Resources\Sales\Orders\Pages\ListOrders;
use App\Filament\Resources\Sales\Orders\Pages\ViewOrder;
use App\Filament\Resources\Sales\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Sales\Quotes\QuoteResource;
use App\Models\Order;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Orders placed from approved quotations. Staff can change the status at any time and confirm the
 * token and full & final payments.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_SALES;

    protected static ?string $navigationLabel = 'Orders';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationBadge(): ?string
    {
        $count = Order::whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Open orders';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Order')->weight('medium')->searchable()->sortable()
                    ->description(fn (Order $record) => $record->enquiry->reference),
                TextColumn::make('customer')->label('Customer')
                    ->state(fn (Order $record) => $record->customer->displayName())
                    ->description(fn (Order $record) => $record->enquiry->project_name),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('grand_total')->label('Total')->formatStateUsing(fn ($state) => Money::format((int) $state))->alignEnd()->sortable(),
                TextColumn::make('paid')->label('Paid')->state(fn (Order $record) => Money::format($record->amountPaid()))->alignEnd(),
                TextColumn::make('balance')->label('Balance')->state(fn (Order $record) => Money::format($record->balanceDue()))->alignEnd()
                    ->color(fn (Order $record) => $record->balanceDue() > 0 ? 'warning' : 'success'),
                TextColumn::make('placed_at')->label('Placed')->date('d M Y')->sortable(),
            ])
            ->defaultSort('placed_at', 'desc')
            ->filters([SelectFilter::make('status')->options(OrderStatus::class)->multiple()])
            ->recordActions([ViewAction::make()])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer.user', 'enquiry', 'payments'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make('Order')->compact()->columnSpan(['default' => 1, 'lg' => 2])->columns(['default' => 2, 'md' => 3])->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('customer')->state(fn (Order $record) => $record->customer->displayName()),
                TextEntry::make('placed_at')->label('Placed')->dateTime('d M Y, H:i'),
                TextEntry::make('quote')->label('Quotation')
                    ->state(fn (Order $record) => $record->quote->displayNumber())
                    ->url(fn (Order $record) => QuoteResource::getUrl('edit', ['record' => $record->quote_id]))->color('primary'),
                TextEntry::make('enquiry')->label('Enquiry')
                    ->state(fn (Order $record) => $record->enquiry->reference.' · '.$record->enquiry->project_name)
                    ->url(fn (Order $record) => EnquiryResource::getUrl('view', ['record' => $record->enquiry_id]))->color('primary'),
                TextEntry::make('delivered_at')->label('Delivered')->dateTime('d M Y')->placeholder('Not yet'),
            ]),
            Section::make('Money')->compact()->columns(2)->schema([
                TextEntry::make('grand_total')->label('Order total')->formatStateUsing(fn ($state) => Money::format((int) $state))->weight('bold'),
                TextEntry::make('token_amount')->label('Token amount')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextEntry::make('paid')->label('Paid')->state(fn (Order $record) => Money::format($record->amountPaid()))->color('success'),
                TextEntry::make('balance')->label('Balance due')->state(fn (Order $record) => Money::format($record->balanceDue()))
                    ->color(fn (Order $record) => $record->balanceDue() > 0 ? 'warning' : 'success')->weight('bold'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
