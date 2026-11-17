<?php

namespace App\Filament\Resources\Sales\Enquiries;

use App\Enums\EnquiryStatus;
use App\Enums\Priority;
use App\Enums\ProjectType;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Filament\Resources\Sales\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Sales\Enquiries\Pages\ViewEnquiry;
use App\Models\Enquiry;
use App\Models\Message;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Enquiries inbox for staff (spec §12.2). Read-only screen with workflow actions;
 * customers create and edit enquiries in the portal.
 */
class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_SALES;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    /**
     * Sidebar badge: enquiries waiting for staff (new + customer replied).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::whereIn('status', [EnquiryStatus::Submitted, EnquiryStatus::UnderReview])->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'project_name'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->placeholder('Draft')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->state(fn (Enquiry $record): string => $record->customer->displayName())
                    ->description(fn (Enquiry $record): string => $record->project_name)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('project_name', 'ilike', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $q) => $q->where('company_name', 'ilike', "%{$search}%")
                            ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")))),
                TextColumn::make('lines_count')->counts('lines')->label('Artworks')->alignCenter(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('unread_count')
                    ->label('')
                    ->formatStateUsing(fn ($state) => $state ? "{$state} new" : null)
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-m-chat-bubble-left'),
                TextColumn::make('priority')->badge()
                    ->color(fn (Priority $state): string => match ($state) {
                        Priority::High => 'danger',
                        Priority::Normal => 'gray',
                        Priority::Low => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned')->toggleable(),
                TextColumn::make('required_by')->label('Needed by')->date('d M Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('submitted_at')->label('Submitted')->since()->sortable()
                    ->tooltip(fn (Enquiry $record) => $record->submitted_at?->format('d M Y, H:i')),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(EnquiryStatus::class)->multiple(),
                SelectFilter::make('assigned_to')->label('Assigned to')->relationship('assignee', 'name'),
                SelectFilter::make('project_type')->options(ProjectType::class),
                SelectFilter::make('source_id')->label('Source')->relationship('source', 'name'),
                SelectFilter::make('priority')->options(Priority::class),
                TrashedFilter::make()->label('Archived'),
            ])
            ->recordActions([ViewAction::make()->label('Open')])
            ->recordUrl(fn (Enquiry $record) => static::getUrl('view', ['record' => $record]))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['customer.user', 'assignee'])
                ->addSelect(['unread_count' => Message::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('messages.enquiry_id', 'enquiries.id')
                    ->where('from_staff', false)
                    ->whereNull('read_at')]))
            ->poll('30s');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(2)->schema([
                Section::make('Artworks')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        ViewEntry::make('lines')->hiddenLabel()->view('filament.enquiries.lines'),
                    ]),
                Section::make('Conversation')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->description('Messages with the customer. Use “Reply” or “Ask a question” above.')
                    ->schema([
                        ViewEntry::make('messages')->hiddenLabel()->view('filament.enquiries.thread'),
                    ]),
            ]),
            Grid::make(1)->columnSpan(1)->schema([
                Section::make('Quotes')
                    ->icon('heroicon-o-document-currency-rupee')
                    ->visible(fn (Enquiry $record) => $record->quotes()->exists() || $record->estimate_snapshot)
                    ->schema([
                        ViewEntry::make('quotes')->hiddenLabel()->view('filament.enquiries.quotes'),
                    ]),
                Section::make('Summary')->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('customer')
                        ->label('Customer')
                        ->state(fn (Enquiry $record) => $record->customer->displayName())
                        ->url(fn (Enquiry $record) => CustomerResource::getUrl('edit', ['record' => $record->customer]))
                        ->color('primary'),
                    TextEntry::make('contact')
                        ->label('Contact')
                        ->state(fn (Enquiry $record) => collect([
                            $record->customer->user?->name,
                            $record->customer->user?->email,
                            $record->customer->phone,
                        ])->filter()->implode("\n"))
                        ->copyable()
                        ->extraAttributes(['style' => 'white-space:pre-line']),
                    TextEntry::make('project_name')->label('Project'),
                    TextEntry::make('project_type')->label('Type'),
                    TextEntry::make('deliveryAddress')
                        ->label('Deliver to')
                        ->state(fn (Enquiry $record) => $record->deliveryAddress?->oneLine())
                        ->placeholder('—'),
                    TextEntry::make('required_by')->label('Needed by')->date('d M Y')->placeholder('Flexible'),
                    TextEntry::make('budget_range')->label('Budget')->placeholder('Not given'),
                    TextEntry::make('installation_needed')->label('Installation')
                        ->formatStateUsing(fn (bool $state) => $state ? 'Yes, please quote installation' : 'No'),
                    TextEntry::make('preferred_contact')->label('Preferred contact'),
                    TextEntry::make('source.name')->label('Source')->placeholder('—'),
                    TextEntry::make('notes')->label('Customer notes')->placeholder('—')->columnSpanFull(),
                ]),
                Section::make('Internal')
                    ->description('Only visible to staff.')
                    ->schema([
                        TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                        TextEntry::make('priority')->badge(),
                        TextEntry::make('internal_notes')->label('Notes')->placeholder('—'),
                    ]),
                Section::make('History')
                    ->collapsible()
                    ->schema([
                        ViewEntry::make('history')->hiddenLabel()->view('filament.enquiries.history'),
                    ]),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnquiries::route('/'),
            'view' => ViewEnquiry::route('/{record}'),
        ];
    }
}
