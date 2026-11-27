<?php

namespace App\Filament\Resources\Sales\Payments;

use App\Domain\Orders\OrderService;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Filament\Resources\Sales\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use UnitEnum;

/**
 * Every order payment (token and full & final) in one list, with filters, so staff can check what the
 * customers sent and see what came in.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_SALES;

    protected static ?string $navigationLabel = 'Payments';

    protected static ?int $navigationSort = 40;

    public static function getNavigationBadge(): ?string
    {
        $count = Payment::where('status', PaymentStatus::Submitted)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Payments to check';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::columns(withOrder: true))
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PaymentStatus::class)->multiple(),
                SelectFilter::make('kind')->label('Part')->options(PaymentKind::class),
                SelectFilter::make('method')->options(PaymentMethod::class)->multiple(),
                Filter::make('paid_on')
                    ->schema([
                        DatePicker::make('from')->label('Paid from'),
                        DatePicker::make('until')->label('Paid until'),
                    ])
                    ->columns(2)
                    // Filament passes arguments by name: it must be $query.
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('paid_on', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('paid_on', '<=', $d)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        ($data['from'] ?? null) ? 'Paid from '.$data['from'] : null,
                        ($data['until'] ?? null) ? 'Paid until '.$data['until'] : null,
                    ]))),
            ])
            ->recordActions([self::receiveAction(), self::notReceivedAction(), self::undoReceivedAction()])
            ->recordUrl(fn (Payment $record) => OrderResource::getUrl('view', ['record' => $record->order_id]))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order.customer.user', 'order.enquiry'));
    }

    /**
     * @return list<TextColumn>
     */
    public static function columns(bool $withOrder = false): array
    {
        return array_values(array_filter([
            // Order with its customer underneath; search finds either.
            $withOrder ? TextColumn::make('order.number')->label('Order · customer')->weight('medium')
                ->description(fn (Payment $record) => $record->order->customer->displayName())
                ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('order', fn (Builder $o) => $o
                    ->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c
                        ->where('company_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))))) : null,
            TextColumn::make('kind')->label('Part')->badge()->color('gray'),
            TextColumn::make('amount')->formatStateUsing(fn ($state) => Money::format((int) $state))->alignEnd()->sortable()
                ->summarize([
                    Sum::make()->label('Received')->query(fn (QueryBuilder $query) => $query->where('status', PaymentStatus::Received->value))
                        ->formatStateUsing(fn ($state) => Money::format((int) $state)),
                    Sum::make()->label('Still to come')->query(fn (QueryBuilder $query) => $query->where('status', '!=', PaymentStatus::Received->value))
                        ->formatStateUsing(fn ($state) => Money::format((int) $state)),
                ]),
            TextColumn::make('status')->badge()->sortable(),
            // Method under the reference keeps the table narrow enough for its actions.
            TextColumn::make('method')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reference')->placeholder('—')->searchable()->copyable()->toggleable()
                ->description(fn (Payment $record) => $record->method?->getLabel()),
            TextColumn::make('paid_on')->date('d M Y')->placeholder('—')->sortable(),
            TextColumn::make('received_at')->label('Confirmed')->since()->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /** Staff confirm the money arrived, or record a payment straight away. */
    public static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(fn (Payment $record) => $record->status === PaymentStatus::Submitted ? 'Confirm received' : 'Record payment')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Payment $record) => $record->status !== PaymentStatus::Received)
            ->modalHeading(fn (Payment $record) => $record->kind->getLabel().' · '.Money::format($record->amount))
            ->modalDescription('Check the money is in the bank account before confirming. The customer is told in the enquiry thread.')
            ->modalSubmitActionLabel('Mark as received')
            ->fillForm(fn (Payment $record) => [
                'method' => $record->method?->value,
                'reference' => $record->reference,
                'paid_on' => $record->paid_on?->toDateString() ?? today()->toDateString(),
            ])
            ->schema([
                Select::make('method')->options(PaymentMethod::class)->required(),
                TextInput::make('reference')->label('Transaction / UTR / cheque no.')->maxLength(100),
                DatePicker::make('paid_on')->label('Paid on')->required()->maxDate(today()),
            ])
            ->action(function (Payment $record, array $data): void {
                app(OrderService::class)->markReceived($record, auth()->user(), $data);
                Notification::make()->success()->title('Payment marked as received')->send();
            });
    }

    /** A payment marked as received by mistake: it is due again (the order status is left to staff). */
    public static function undoReceivedAction(): Action
    {
        return Action::make('undoReceived')
            ->label('Undo received')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Payment $record) => $record->status === PaymentStatus::Received)
            ->modalHeading('Undo this payment?')
            ->modalDescription('Use this only if it was marked as received by mistake. It becomes due again; change the order status yourself if needed.')
            ->schema([Textarea::make('reason')->label('Why?')->required()->rows(2)])
            ->action(function (Payment $record, array $data): void {
                app(OrderService::class)->undoReceived($record, auth()->user(), $data['reason']);
                Notification::make()->warning()->title('Payment is due again')->send();
            });
    }

    /** The customer's payment could not be found: they are asked to check and send it again. */
    public static function notReceivedAction(): Action
    {
        return Action::make('notReceived')
            ->label('Not received')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Payment $record) => $record->status === PaymentStatus::Submitted)
            ->modalHeading('Payment not found?')
            ->modalDescription('The customer is asked to check and send the details again.')
            ->schema([
                Textarea::make('reason')->label('What should the customer check?')->required()->rows(2)
                    ->default('We could not find this amount in our bank account.'),
            ])
            ->action(function (Payment $record, array $data): void {
                app(OrderService::class)->markNotReceived($record, auth()->user(), $data['reason']);
                Notification::make()->warning()->title('Customer asked to check the payment')->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
