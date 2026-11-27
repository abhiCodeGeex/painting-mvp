<?php

namespace App\Filament\Resources\Sales\Quotes;

use App\Domain\Pricing\GstCalculator;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Filament\Resources\Sales\Quotes\Pages\EditQuote;
use App\Filament\Resources\Sales\Quotes\Pages\ListQuotes;
use App\Filament\Support\MoneyInput;
use App\Models\Artist;
use App\Models\EnquiryLine;
use App\Models\Quote;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Quotations (spec §8). Created from an enquiry ("Create quote"), edited while draft, then sent.
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyRupee;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_SALES;

    protected static ?string $navigationLabel = 'Quotes';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * Sidebar badge: draft quotes not yet sent to the customer.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', QuoteStatus::Draft)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Draft quotes to send';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Quote')
                    ->formatStateUsing(fn (Quote $record) => $record->displayNumber())
                    ->weight('medium')->searchable()->sortable(),
                TextColumn::make('enquiry.reference')->label('Enquiry')
                    ->description(fn (Quote $record) => $record->enquiry->project_name),
                TextColumn::make('customer')->label('Customer')->state(fn (Quote $record) => $record->enquiry->customer->displayName()),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('grand_total')->label('Total (incl. GST)')->formatStateUsing(fn ($state) => Money::format((int) $state))->alignEnd()->sortable(),
                TextColumn::make('margin')->label('Margin')->state(fn (Quote $record) => $record->marginPercent() !== null ? $record->marginPercent().'%' : '—')->alignEnd()->toggleable(),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('sent_at')->label('Sent')->since()->placeholder('Not sent')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([SelectFilter::make('status')->options(QuoteStatus::class)->multiple()])
            ->recordActions([EditAction::make()->label(fn (Quote $record) => $record->status->isEditable() ? 'Edit' : 'Open')])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('enquiry.customer.user'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'xl' => 3])->components([
            Grid::make(1)->columnSpan(['default' => 1, 'xl' => 2])->schema([
                Section::make('Artworks')
                    ->description('All amounts are per piece. Price = cost (artwork + materials) + labour + your margin.')
                    ->compact()
                    ->schema([
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->hiddenLabel()
                            ->orderColumn('position')
                            ->reorderable(false)
                            ->addActionLabel('Add a line')
                            ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                            ->collapsible()
                            ->columns(['default' => 1, 'md' => 4])
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::priced($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::priced($data))
                            ->schema([
                                Hidden::make('enquiry_line_id'),
                                Hidden::make('breakdown'),

                                TextInput::make('title')->required()->live(onBlur: true)->columnSpan(['default' => 1, 'md' => 3]),
                                TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required()->live(onBlur: true),
                                Textarea::make('spec')->label('What the customer asked for')->rows(2)->autosize()->columnSpanFull(),
                                Select::make('artist_id')
                                    ->label('Artist')
                                    ->placeholder('Choose an artist')
                                    ->options(fn (Get $get) => self::artistOptions($get('artist_id')))
                                    ->visible(fn (Get $get) => self::isCustomCreation($get('enquiry_line_id')))
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::reprice($get, $set))
                                    ->helperText('Their fee per m² sets the artwork cost.')
                                    ->columnSpanFull(),

                                // One row: cost + labour + margin = price.
                                MoneyInput::make('unit_cost')->label('Cost')
                                    ->disabled(fn (Get $get) => (bool) $get('enquiry_line_id'))
                                    ->dehydrated()
                                    ->live(onBlur: true),
                                MoneyInput::make('labour_amount')->label('Labour')->default(0)->placeholder('0')
                                    ->dehydrateStateUsing(fn ($state): int => self::paise($state))
                                    ->live(onBlur: true),
                                MoneyInput::make('margin_amount')->label('Your margin')->placeholder('Enter amount')
                                    ->live(onBlur: true)
                                    ->helperText(fn (Get $get) => self::marginHint($get)),
                                TextEntry::make('price_live')->label('Price')
                                    ->state(fn (Get $get) => Money::format(self::linePrice($get())))
                                    ->weight('bold')
                                    ->size('lg'),

                                Section::make('Cost breakdown')
                                    ->description(fn (Get $get) => self::breakdownSummary($get))
                                    ->visible(fn (Get $get) => self::lineBreakdown($get) !== null)
                                    ->collapsible()
                                    ->collapsed()
                                    ->compact()
                                    ->columnSpanFull()
                                    ->schema([
                                        TextEntry::make('price_breakdown')->hiddenLabel()
                                            ->state(fn (Get $get) => ($b = self::lineBreakdown($get))
                                                ? new HtmlString(view('filament.quotes.line-breakdown', ['b' => $b])->render())
                                                : null)
                                            ->html(),
                                    ]),
                            ]),
                    ]),

                Section::make('Extras')
                    ->description('Packing, shipping, installation… Only “Charged” rows add to the total.')
                    ->compact()
                    ->schema([
                        Repeater::make('extras')
                            ->relationship('extras')
                            ->hiddenLabel()
                            ->orderColumn('position')
                            ->reorderable(false)
                            ->addActionLabel('Add an extra')
                            ->defaultItems(0)
                            ->compact()
                            ->table([
                                TableColumn::make('Name')->markAsRequired(),
                                TableColumn::make('Inclusion')->width('11rem'),
                                TableColumn::make('Amount')->width('10rem'),
                            ])
                            ->schema([
                                TextInput::make('name')->required(),
                                Select::make('inclusion')->options(['charged' => 'Charged', 'included' => 'Included free', 'excluded' => 'Not included'])
                                    ->default('charged')->required()->selectablePlaceholder(false)->live(),
                                MoneyInput::make('amount')->default(0)->live(onBlur: true),
                            ]),
                    ]),

                Section::make('Terms')->compact()->collapsible()->columns(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('lead_time')->placeholder('e.g. 3–4 weeks after approval'),
                    TextInput::make('payment_terms'),
                    Textarea::make('note_to_customer')->rows(2)->autosize()->helperText('Shown on the quote and PDF.'),
                    Textarea::make('terms')->label('Terms & conditions')->rows(2)->autosize(),
                    Textarea::make('internal_note')->rows(2)->autosize()->helperText('Staff only.')->columnSpanFull(),
                ]),
            ]),

            Grid::make(1)->columnSpan(1)
                ->extraAttributes(['style' => 'position: sticky; top: 5rem; align-self: start;'])
                ->schema([
                    Section::make('Summary')
                        ->compact()
                        ->schema([
                            Grid::make(['default' => 1, 'sm' => 3])->schema([
                                TextEntry::make('status')->badge(),
                                TextEntry::make('enquiry_link')->label('Enquiry')->columnSpan(['default' => 1, 'sm' => 2])
                                    ->state(fn (?Quote $record) => $record ? $record->enquiry->reference.' · '.$record->enquiry->customer->displayName() : null)
                                    ->url(fn (?Quote $record) => $record ? EnquiryResource::getUrl('view', ['record' => $record->enquiry_id]) : null)
                                    ->color('primary'),
                            ]),
                            // One discount at a time: typing a % clears the amount and vice versa; totals update as you type.
                            Grid::make(['default' => 2])->schema([
                                TextInput::make('discount_percent')->label('Discount %')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%')
                                    ->dehydrateStateUsing(fn ($state): float => (float) ($state ?: 0))
                                    ->live(debounce: 400)
                                    ->afterStateUpdated(fn ($state, Set $set) => (float) $state > 0 ? $set('discount_amount', null) : null),
                                MoneyInput::make('discount_amount')->label('or amount')
                                    // With a % in use the stored amount is just its result: show the box empty.
                                    ->formatStateUsing(fn ($state, ?Quote $record): ?string => (float) $record?->discount_percent > 0 || $state === null || $state === '' || (int) $state === 0
                                        ? null : Money::toRupees((int) $state))
                                    // Cleared (when a % is typed) saves as 0, never NULL.
                                    ->dehydrateStateUsing(fn ($state): int => $state === null || $state === '' ? 0 : Money::toPaise((string) $state))
                                    ->live(debounce: 400)
                                    ->afterStateUpdated(fn ($state, Set $set) => (float) $state > 0 ? $set('discount_percent', 0) : null),
                            ]),
                            TextEntry::make('totals_live')->hiddenLabel()
                                ->state(fn (?Quote $record, Get $get) => ($t = self::liveTotals($record, $get))
                                    ? new HtmlString(view('filament.quotes.totals', ['t' => $t, 'estimate' => self::customerEstimate($record)])->render())
                                    : null)
                                ->html(),
                            Grid::make(['default' => 2])->schema([
                                TextEntry::make('sent')->label('Sent')
                                    ->state(fn (?Quote $record) => $record?->sent_at
                                        ? $record->sent_at->format('d M Y, H:i').' via '.collect($record->sent_via)->map(fn ($c) => ucfirst($c))->implode(', ')
                                        : 'Not yet'),
                                TextEntry::make('valid_until')->date('d M Y')->placeholder('Set when sent'),
                            ]),
                        ]),

                    Section::make('Customer response')
                        ->compact()
                        ->visible(fn (?Quote $record) => $record?->responded_at !== null)
                        ->schema([
                            TextEntry::make('response_summary')->hiddenLabel()
                                ->state(fn (?Quote $record) => self::responseSummary($record)),
                            TextEntry::make('response_comment')->label('Comment')
                                ->state(fn (?Quote $record) => $record?->response['comment'] ?? null)
                                ->placeholder('—')
                                ->extraAttributes(['class' => 'whitespace-pre-line']),
                        ]),
                ]),
        ])->disabled(fn (?Quote $record) => $record && ! $record->status->isEditable());
    }

    /**
     * Totals from the form as currently typed (not yet saved), worked out the same way as QuoteBuilder:
     * line prices × quantity and charged extras, minus the discount, plus GST; and what it costs us.
     *
     * @return array<string, mixed>|null
     */
    private static function liveTotals(?Quote $quote, Get $get): ?array
    {
        if (! $quote) {
            return null;
        }
        $lines = collect(is_array($get('lines')) ? $get('lines') : [])
            ->filter(fn ($l) => is_array($l) && (empty($l['is_optional']) || ($l['is_selected'] ?? true)));
        $items = $lines->sum(fn (array $l) => self::linePrice($l) * max(1, (int) ($l['quantity'] ?? 1)));
        $cost = $lines->sum(fn (array $l) => (self::paise($l['unit_cost'] ?? 0) + self::paise($l['labour_amount'] ?? 0)) * max(1, (int) ($l['quantity'] ?? 1)));

        $extrasState = $get('extras');
        $extras = is_array($extrasState)
            ? collect($extrasState)
                ->filter(fn ($e) => is_array($e) && ($e['inclusion'] ?? 'charged') === 'charged')
                ->sum(fn (array $e) => self::paise($e['amount'] ?? 0))
            : (int) $quote->extras_total;
        $subtotal = $items + $extras;
        $percent = (float) $get('discount_percent');
        $amount = $get('discount_amount');
        $amount = $amount === null || $amount === '' ? 0 : Money::toPaise((string) $amount);
        $discount = $percent > 0 ? (int) round($subtotal * $percent / 100) : min(max(0, $amount), $subtotal);

        $gst = app(GstCalculator::class)->calculate(
            $subtotal - $discount,
            $quote->enquiry->deliveryAddress?->state?->gst_code,
            (bool) $quote->enquiry->customer?->tax_exempt,
        );

        return ['items' => $items, 'extras' => $extras, 'subtotal' => $subtotal, 'discount' => $discount, 'discount_percent' => $percent,
            'cost' => $cost, 'rate' => (float) $gst['rate'], ...$gst];
    }

    /**
     * The estimate the customer saw in the builder (incl. GST), or null if none was shown.
     *
     * @return array{low: int, high: int}|null
     */
    private static function customerEstimate(?Quote $quote): ?array
    {
        $e = $quote?->enquiry->estimate_snapshot;
        if (! $e || ($e['mode'] ?? null) === 'hidden') {
            return null;
        }

        return ['low' => (int) $e['total_low'], 'high' => (int) ($e['is_range'] ? $e['total_high'] : $e['total_low'])];
    }

    /** Rupees as typed in a money box → paise; empty → 0. */
    private static function paise(mixed $state): int
    {
        if ($state === null || $state === '' || ! is_numeric($state)) {
            return 0;
        }

        return max(0, Money::toPaise((string) $state));
    }

    /**
     * Price per piece from the repeater item state as typed (rupees): cost + labour + margin.
     *
     * @param  array<string, mixed>  $state
     */
    private static function linePrice(array $state): int
    {
        return self::paise($state['unit_cost'] ?? 0) + self::paise($state['labour_amount'] ?? 0) + self::paise($state['margin_amount'] ?? 0);
    }

    /**
     * Saved line (money already in paise): the price follows cost + labour + margin.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function priced(array $data): array
    {
        $price = (int) ($data['unit_cost'] ?? 0) + (int) ($data['labour_amount'] ?? 0) + (int) ($data['margin_amount'] ?? 0);

        return [...$data, 'unit_cost' => (int) ($data['unit_cost'] ?? 0), 'unit_price' => $price, 'calculated_unit_price' => $price, 'discount_amount' => 0];
    }

    /**
     * @param  array<string, mixed>  $state  dehydrated item state (money in paise)
     */
    private static function itemLabel(array $state): ?string
    {
        $title = $state['title'] ?? null;
        if ($title === null || $title === '') {
            return null;
        }
        $price = (int) ($state['unit_cost'] ?? 0) + (int) ($state['labour_amount'] ?? 0) + (int) ($state['margin_amount'] ?? 0);
        $qty = max(1, (int) ($state['quantity'] ?? 1));

        return $title.' · '.($qty > 1 ? $qty.' × '.Money::format($price).' = ' : '').Money::format($price * $qty);
    }

    private static function marginHint(Get $get): string
    {
        if ($get('margin_amount') === null || $get('margin_amount') === '') {
            return 'Required before sending';
        }
        $price = self::linePrice($get());

        return $price > 0 ? round(self::paise($get('margin_amount')) / $price * 100, 1).'% of the price' : '';
    }

    /**
     * Active artists with a fee, labelled with it (plus the one already chosen, even if inactive now).
     *
     * @return array<int, string>
     */
    private static function artistOptions(mixed $current): array
    {
        return Artist::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->where('fee_per_m2', '>', 0))
            ->when($current, fn (Builder $q) => $q->orWhere('id', (int) $current))
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Artist $a) => [$a->id => $a->name.' · '.Money::format($a->fee_per_m2).' / m²'])
            ->all();
    }

    private static function responseSummary(?Quote $quote): ?string
    {
        if (! $quote?->responded_at) {
            return null;
        }
        $r = $quote->response ?? [];
        $when = $quote->responded_at->format('d M Y, H:i');

        return match ($r['action'] ?? null) {
            'approved' => "Approved by {$r['name']}".(! empty($r['role']) ? ", {$r['role']}" : '')." on {$when}"
                .(! empty($r['po_number']) ? " · PO {$r['po_number']}" : '').' · '.Money::format((int) ($r['grand_total'] ?? $quote->grand_total)),
            'changes_requested' => "Changes requested on {$when}. Create a new version to answer.",
            'rejected' => "Declined on {$when}: ".($r['reason'] ?? '—'),
            default => null,
        };
    }

    private static function isCustomCreation(?int $enquiryLineId): bool
    {
        return $enquiryLineId && EnquiryLine::whereKey($enquiryLineId)->where('source_type', 'custom_creation')->exists();
    }

    /** @var array<string, array<string, mixed>|null> */
    private static array $breakdownCache = [];

    /**
     * The pricing engine figures for this line: as saved on the quote, or worked out again from the
     * enquiry line when the saved snapshot predates the detailed breakdown. Null for hand-added lines.
     *
     * @return array<string, mixed>|null
     */
    public static function lineBreakdown(Get $get): ?array
    {
        $saved = $get('breakdown');
        if (is_array($saved) && isset($saved['components']) && (($saved['components'][0]['measure'] ?? null) !== null || $saved['components'] === [])) {
            return $saved;
        }

        $lineId = $get('enquiry_line_id');
        if (! $lineId) {
            return null;
        }
        $artist = $get('artist_id') ? (int) $get('artist_id') : null;
        $key = $lineId.':'.$artist;
        if (! array_key_exists($key, self::$breakdownCache)) {
            $line = EnquiryLine::with('enquiry')->find($lineId);
            self::$breakdownCache[$key] = $line ? app(QuoteBuilder::class)->linePrice($line, $line->enquiry, $artist)['breakdown'] : null;
        }

        return self::$breakdownCache[$key];
    }

    private static function breakdownSummary(Get $get): ?string
    {
        $b = self::lineBreakdown($get);

        return $b ? 'Cost '.Money::format(QuoteBuilder::costOf($b)).' per piece — open to see what it is made of' : null;
    }

    /**
     * Re-runs the pricing engine for an enquiry line with the chosen artist: the cost follows.
     */
    private static function reprice(Get $get, Set $set): void
    {
        $line = EnquiryLine::with('enquiry')->find($get('enquiry_line_id'));
        if (! $line) {
            return;
        }
        $price = app(QuoteBuilder::class)->linePrice($line, $line->enquiry, $get('artist_id') ? (int) $get('artist_id') : null);
        $set('unit_cost', Money::toRupees($price['unit_cost']));
        $set('breakdown', $price['breakdown']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
