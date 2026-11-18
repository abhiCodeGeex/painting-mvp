<?php

namespace App\Filament\Resources\Sales\Quotes;

use App\Domain\Quotes\QuoteBuilder;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Filament\Resources\Sales\Quotes\Pages\EditQuote;
use App\Filament\Resources\Sales\Quotes\Pages\ListQuotes;
use App\Filament\Support\MoneyInput;
use App\Models\ComplexityLevel;
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
                    ->description('Prices come from the pricing engine. Change a unit price to override it (a reason is required).')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->hiddenLabel()
                            ->orderColumn('position')
                            ->addActionLabel('Add a line')
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->collapsible()
                            ->columns(['default' => 2, 'md' => 6])
                            ->schema([
                                Hidden::make('enquiry_line_id'),
                                Hidden::make('calculated_unit_price'),
                                Hidden::make('unit_cost'),
                                TextInput::make('title')->required()->columnSpan(['default' => 2, 'md' => 4]),
                                TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required()->columnSpan(1),
                                Toggle::make('is_optional')->label('Optional')->inline(false)->columnSpan(1),
                                Textarea::make('spec')->label('Specification')->rows(2)->columnSpanFull(),
                                Select::make('complexity_level_id')
                                    ->label('Complexity (new creation)')
                                    ->options(fn () => ComplexityLevel::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                                    ->visible(fn (Get $get) => self::isCustomCreation($get('enquiry_line_id')))
                                    ->live()
                                    ->afterStateUpdated(fn (Get $get, Set $set, $state) => self::reprice($get, $set, $state ? (int) $state : null))
                                    ->columnSpan(2),
                                MoneyInput::make('unit_price')->label('Unit price')->required()->live(onBlur: true)->columnSpan(['default' => 1, 'md' => 2])
                                    ->helperText(fn (Get $get) => self::priceHint($get)),
                                MoneyInput::make('discount_amount')->label('Line discount')->default(0)->columnSpan(['default' => 1, 'md' => 2]),
                                TextInput::make('override_reason')
                                    ->label('Why is the price changed?')
                                    ->visible(fn (Get $get) => self::isOverridden($get))
                                    ->required(fn (Get $get) => self::isOverridden($get))
                                    ->columnSpanFull(),
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
                                    ->default('charged')->required()->native(false)->columnSpan(['default' => 1, 'md' => 1]),
                                MoneyInput::make('amount')->default(0)->columnSpan(['default' => 1, 'md' => 2]),
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
                    ->description('Updated when you save.')
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('enquiry_link')->label('Enquiry')
                            ->state(fn (?Quote $record) => $record ? $record->enquiry->reference.' · '.$record->enquiry->customer->displayName() : null)
                            ->url(fn (?Quote $record) => $record ? EnquiryResource::getUrl('view', ['record' => $record->enquiry_id]) : null)
                            ->color('primary'),
                        TextEntry::make('items_total')->label('Artworks')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                        TextEntry::make('extras_total')->label('Extras')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                        Grid::make(2)->schema([
                            TextInput::make('discount_percent')->label('Discount %')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
                            MoneyInput::make('discount_amount')->label('or amount')->helperText('Used when % is 0.'),
                        ]),
                        TextEntry::make('taxable')->label('Taxable value')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                        TextEntry::make('gst')->label('GST')
                            ->state(fn (?Quote $record) => ! $record ? null : ($record->intra_state
                                ? 'CGST '.Money::format($record->cgst).' + SGST '.Money::format($record->sgst)
                                : 'IGST '.Money::format($record->igst)).' @ '.(float) $record->tax_rate.'%'),
                        TextEntry::make('grand_total')->label('Grand total')->formatStateUsing(fn ($state) => Money::format((int) $state))->weight('bold')->size('lg'),
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
            ]),
        ])->disabled(fn (?Quote $record) => $record && ! $record->status->isEditable());
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
        if (! $calculated) {
            return null;
        }

        return 'Calculated '.Money::format($calculated).' · cost '.Money::format((int) $get('unit_cost'));
    }

    /** Re-runs the pricing engine for an enquiry line at the chosen complexity. */
    private static function reprice(Get $get, Set $set, ?int $complexityId): void
    {
        $line = EnquiryLine::with('enquiry')->find($get('enquiry_line_id'));
        if (! $line) {
            return;
        }
        $price = app(QuoteBuilder::class)->linePrice($line, $line->enquiry, $complexityId);
        $set('calculated_unit_price', $price['calculated_unit_price']);
        $set('unit_cost', $price['unit_cost']);
        $set('unit_price', Money::toRupees($price['unit_price']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
