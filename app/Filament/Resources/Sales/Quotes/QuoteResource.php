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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
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
        return $schema->columns(['default' => 1, 'lg' => 1, '2xl' => 3])->components([
            Grid::make(1)->columnSpan(['default' => 1, '2xl' => 2])->schema([
                Section::make('Artworks')
                    ->description('Unit price = calculated price + labour. Type a different price to override it.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->hiddenLabel()
                            ->orderColumn('position')
                            ->addActionLabel('Add a line')
                            ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                            ->collapsible()
                            ->columns(1)
                            ->schema([
                                Hidden::make('enquiry_line_id'),
                                Hidden::make('calculated_unit_price'),
                                Hidden::make('unit_cost'),
                                Hidden::make('breakdown'),

                                TextInput::make('title')->required()->live(onBlur: true),
                                Textarea::make('spec')->label('What the customer asked for')->rows(2)->autosize(),

                                Fieldset::make('Pricing')
                                    ->columns(['default' => 1, 'sm' => 2, 'md' => 3])
                                    ->schema([
                                        Select::make('artist_id')
                                            ->label('Artist')
                                            ->placeholder('Choose an artist')
                                            ->options(fn (Get $get) => self::artistOptions($get('artist_id')))
                                            ->visible(fn (Get $get) => self::isCustomCreation($get('enquiry_line_id')))
                                            ->live()
                                            ->afterStateUpdated(fn (Get $get, Set $set) => self::reprice($get, $set))
                                            ->helperText('Their fee per m² prices the artwork.'),
                                        TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required()->live(onBlur: true),
                                        MoneyInput::make('labour_amount')->label('Labour (per piece)')->default(0)
                                            ->dehydrateStateUsing(fn ($state): int => self::paise($state))
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Get $get, Set $set, $state, $old) => self::applyLabour($get, $set, $state, $old))
                                            ->helperText('Added to the unit price.'),

                                        MoneyInput::make('unit_price')->label('Unit price')->required()->live(onBlur: true)
                                            ->helperText(fn (Get $get) => self::priceHint($get)),
                                        MoneyInput::make('discount_amount')->label('Line discount')->default(0)
                                            ->dehydrateStateUsing(fn ($state): int => self::paise($state))
                                            ->live(onBlur: true)
                                            ->helperText('Off the line total.'),
                                        Toggle::make('is_optional')->label('Optional')->inline(false)->live()
                                            ->helperText('The customer may leave it out.'),

                                        TextInput::make('override_reason')
                                            ->label('Why is the price changed?')
                                            ->visible(fn (Get $get) => self::isOverridden($get))
                                            ->required(fn (Get $get) => self::isOverridden($get))
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Price breakdown')
                                    ->description(fn (Get $get) => self::breakdownSummary($get))
                                    ->visible(fn (Get $get) => self::lineBreakdown($get) !== null)
                                    ->collapsible()
                                    ->collapsed()
                                    ->compact()
                                    ->schema([
                                        TextEntry::make('price_breakdown')->hiddenLabel()
                                            ->state(fn (Get $get) => ($b = self::lineBreakdown($get))
                                                ? new HtmlString(view('filament.quotes.line-breakdown', [
                                                    'b' => $b,
                                                    'labour' => self::paise($get('labour_amount')),
                                                    'quoted' => self::quotedUnitPrice($get),
                                                ])->render())
                                                : null)
                                            ->html(),
                                    ]),
                            ]),
                    ]),

                Section::make('Extras')
                    ->description('Packing, shipping, installation… Only “Charged” rows add to the total.')
                    ->schema([
                        Repeater::make('extras')
                            ->relationship('extras')
                            ->hiddenLabel()
                            ->orderColumn('position')
                            ->addActionLabel('Add an extra')
                            ->columns(['default' => 2, 'md' => 5])
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('name')->required()->columnSpan(2),
                                Select::make('inclusion')->options(['charged' => 'Charged', 'included' => 'Included free', 'excluded' => 'Not included'])
                                    ->default('charged')->required()->native(false)->live()->columnSpan(['default' => 1, 'md' => 1]),
                                MoneyInput::make('amount')->default(0)->live(onBlur: true)->columnSpan(['default' => 1, 'md' => 2]),
                            ]),
                    ]),

                Section::make('Terms')->columns(2)->schema([
                    TextInput::make('lead_time')->placeholder('e.g. 3–4 weeks after approval'),
                    Textarea::make('payment_terms')->rows(2),
                    Textarea::make('note_to_customer')->rows(3)->helperText('Shown on the quote and PDF.'),
                    Textarea::make('terms')->label('Terms & conditions')->rows(3),
                    Textarea::make('internal_note')->rows(2)->helperText('Staff only.')->columnSpanFull(),
                ]),
            ]),

            Grid::make(1)->columnSpan(1)->schema([
                Section::make('Totals')
                    ->description('Updates as you edit. Click “Save & recalculate” to keep the changes.')
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('enquiry_link')->label('Enquiry')
                            ->state(fn (?Quote $record) => $record ? $record->enquiry->reference.' · '.$record->enquiry->customer->displayName() : null)
                            ->url(fn (?Quote $record) => $record ? EnquiryResource::getUrl('view', ['record' => $record->enquiry_id]) : null)
                            ->color('primary'),
                        TextEntry::make('items_live')->label('Artworks')
                            ->state(fn (?Quote $record, Get $get) => ($t = self::liveTotals($record, $get)) ? Money::format($t['items']) : null),
                        TextEntry::make('extras_live')->label('Extras')
                            ->state(fn (?Quote $record, Get $get) => ($t = self::liveTotals($record, $get)) ? Money::format($t['extras']) : null),
                        // One discount at a time: typing a % clears the amount and vice versa; totals update as you type.
                        Grid::make(2)->schema([
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
                                ->afterStateUpdated(fn ($state, Set $set) => (float) $state > 0 ? $set('discount_percent', 0) : null)
                                ->helperText('Use either a % or an amount, not both.'),
                        ]),
                        TextEntry::make('discount_live')->label('Discount')
                            ->state(fn (?Quote $record, Get $get) => ($t = self::liveTotals($record, $get)) ? '− '.Money::format($t['discount']) : null)
                            ->placeholder('—'),
                        TextEntry::make('taxable')->label('Taxable value')
                            ->state(fn (?Quote $record, Get $get) => ($t = self::liveTotals($record, $get)) ? Money::format($t['taxable']) : null),
                        TextEntry::make('gst')->label('GST')
                            ->state(function (?Quote $record, Get $get): ?string {
                                $t = self::liveTotals($record, $get);

                                return ! $t ? null : ($t['intra_state']
                                    ? 'CGST '.Money::format($t['cgst']).' + SGST '.Money::format($t['sgst'])
                                    : 'IGST '.Money::format($t['igst'])).' @ '.(float) $t['rate'].'%';
                            }),
                        TextEntry::make('grand_total')->label('Grand total')
                            ->state(fn (?Quote $record, Get $get) => ($t = self::liveTotals($record, $get)) ? Money::format($t['grand_total']) : null)
                            ->weight('bold')->size('lg'),
                        TextEntry::make('margin')->label('Margin (staff only)')
                            ->state(fn (?Quote $record) => $record?->marginPercent() !== null
                                ? $record->marginPercent().'% · cost '.Money::format($record->total_cost)
                                : '—'),
                        TextEntry::make('customer_estimate')->label('Customer was shown')
                            ->state(function (?Quote $record): ?string {
                                $e = $record?->enquiry->estimate_snapshot;
                                if (! $e || ($e['mode'] ?? null) === 'hidden') {
                                    return 'No estimate shown';
                                }

                                return $e['is_range']
                                    ? Money::format($e['total_low']).' – '.Money::format($e['total_high']).' incl. GST'
                                    : Money::format($e['total_low']).' incl. GST';
                            }),
                        TextEntry::make('sent')->label('Sent')
                            ->state(fn (?Quote $record) => $record?->sent_at
                                ? $record->sent_at->format('d M Y, H:i').' via '.collect($record->sent_via)->map(fn ($c) => ucfirst($c))->implode(', ')
                                : 'Not sent yet'),
                        TextEntry::make('valid_until')->date('d M Y')->placeholder('Set when sent'),
                    ]),

                Section::make('Customer response')
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
     * line totals (unit price × qty − line discount) and charged extras, minus the discount, plus GST.
     *
     * @return array{items: int, extras: int, discount: int, taxable: int, intra_state: bool, rate: float, cgst: int, sgst: int, igst: int, grand_total: int}|null
     */
    private static function liveTotals(?Quote $quote, Get $get): ?array
    {
        if (! $quote) {
            return null;
        }
        $lines = $get('lines');
        $items = is_array($lines)
            ? collect($lines)
                ->filter(fn ($l) => is_array($l) && (empty($l['is_optional']) || ($l['is_selected'] ?? true)))
                ->sum(fn (array $l) => self::lineTotal($l))
            : (int) $quote->items_total;
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

        return ['items' => $items, 'extras' => $extras, 'discount' => $discount, 'rate' => (float) $gst['rate'], ...$gst];
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
     * Line total from the repeater item state: unit price × quantity − line discount.
     *
     * @param  array<string, mixed>  $state
     */
    private static function lineTotal(array $state): int
    {
        return max(0, self::paise($state['unit_price'] ?? 0) * max(1, (int) ($state['quantity'] ?? 1)) - self::paise($state['discount_amount'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $title = $state['title'] ?? null;
        if ($title === null || $title === '') {
            return null;
        }

        // The repeater hands the item label its dehydrated state: money is already in paise here.
        $total = max(0, (int) ($state['unit_price'] ?? 0) * max(1, (int) ($state['quantity'] ?? 1)) - (int) ($state['discount_amount'] ?? 0));

        return $title.' · '.Money::format($total);
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

    private static function isOverridden(Get $get): bool
    {
        $calculated = (int) $get('calculated_unit_price');

        return $calculated > 0 && Money::toPaise((string) ($get('unit_price') ?? 0)) !== $calculated;
    }

    private static function priceHint(Get $get): ?string
    {
        $calculated = (int) $get('calculated_unit_price');

        return $calculated ? 'Calculated '.Money::format($calculated) : null;
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
        if (! $b) {
            return null;
        }
        $labour = self::paise($get('labour_amount'));

        return '₹'.number_format(round(((int) $b['unit_price'] + $labour) / 100)).' per piece — open to see what it is made of';
    }

    private static function quotedUnitPrice(Get $get): ?int
    {
        $state = $get('unit_price');
        if ($state === null || $state === '' || ! is_numeric($state)) {
            return null;
        }

        return Money::toPaise((string) $state);
    }

    /**
     * Re-runs the pricing engine for an enquiry line with the chosen artist and the labour entered.
     * The unit price follows the new calculated price unless staff had typed their own.
     */
    private static function reprice(Get $get, Set $set, string $p = ''): void
    {
        $line = EnquiryLine::with('enquiry')->find($get($p.'enquiry_line_id'));
        if (! $line) {
            return;
        }
        $typedOwn = self::paise($get($p.'unit_price')) !== (int) $get($p.'calculated_unit_price');

        $price = app(QuoteBuilder::class)->linePrice($line, $line->enquiry,
            $get($p.'artist_id') ? (int) $get($p.'artist_id') : null, self::paise($get($p.'labour_amount')));
        $set($p.'calculated_unit_price', $price['calculated_unit_price']);
        $set($p.'unit_cost', $price['unit_cost']);
        $set($p.'breakdown', $price['breakdown']);
        if (! $typedOwn) {
            $set($p.'unit_price', Money::toRupees($price['unit_price']));
        }
    }

    /**
     * Labour changed: lines from an enquiry are re-priced; on a hand-added line the difference is
     * added to the unit price.
     */
    private static function applyLabour(Get $get, Set $set, mixed $state, mixed $old): void
    {
        if ($get('enquiry_line_id')) {
            self::reprice($get, $set);

            return;
        }
        $delta = self::paise($state) - self::paise($old);
        if ($delta !== 0) {
            $set('unit_price', Money::toRupees(max(0, self::paise($get('unit_price')) + $delta)));
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
