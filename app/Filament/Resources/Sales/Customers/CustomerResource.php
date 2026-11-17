<?php

namespace App\Filament\Resources\Sales\Customers;

use App\Enums\CustomerType;
use App\Filament\Resources\Sales\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Filament\Resources\Sales\Customers\Pages\ListCustomers;
use App\Filament\Resources\Sales\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Sales\Customers\RelationManagers\EnquiriesRelationManager;
use App\Models\Customer;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\IndianIds;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Customers (spec §5). The account name/email live on the linked user; the form edits both.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_SALES;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'company_name';

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Customer|null $record */
        return $record?->displayName() ?? 'Customer';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Contact')
                ->columnSpan(2)
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->options(CustomerType::class)
                        ->default(CustomerType::Individual->value)
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('company_name')
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => self::isCompany($get('type')))
                        ->visible(fn (Get $get): bool => self::isCompany($get('type'))),
                    TextInput::make('name')
                        ->label('Contact person')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: 'users',
                            column: 'email',
                            ignorable: fn (?Customer $record) => $record?->user,
                            modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                        ),
                    TextInput::make('phone')
                        ->tel()
                        ->regex(IndianIds::PHONE_REGEX)
                        ->placeholder('+91 98765 43210'),
                    TextInput::make('website')->url()->maxLength(255),
                ]),

            Section::make('Account')
                ->columnSpan(1)
                ->schema([
                    Select::make('source_id')
                        ->label('How they found us')
                        ->relationship('source', 'name', fn (Builder $query) => $query->active()->ordered())
                        ->preload(),
                    Toggle::make('send_invitation')
                        ->label('Email an invitation to set a password')
                        ->default(true)
                        ->visibleOn('create')
                        ->dehydrated(false),
                    Toggle::make('is_active')
                        ->label('Can sign in')
                        ->default(true)
                        ->dehydrated(false)
                        ->visibleOn('edit'),
                ]),

            Section::make('Tax details')
                ->columnSpan(2)
                ->columns(2)
                ->collapsible()
                ->schema([
                    TextInput::make('gstin')
                        ->label('GSTIN')
                        ->maxLength(15)
                        ->regex(IndianIds::GSTIN_REGEX)
                        ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : null)
                        ->helperText('15 characters, e.g. 27AAPFU0939F1ZV'),
                    TextInput::make('pan')
                        ->label('PAN')
                        ->maxLength(10)
                        ->regex(IndianIds::PAN_REGEX)
                        ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : null),
                    Toggle::make('tax_exempt')->label('Tax exempt (SEZ / export)')->live(),
                    TextInput::make('tax_exempt_note')
                        ->label('Exemption note')
                        ->visible(fn (Get $get): bool => (bool) $get('tax_exempt')),
                ]),

            Section::make('Internal notes')
                ->columnSpan(1)
                ->schema([
                    Textarea::make('internal_notes')->hiddenLabel()->rows(5)
                        ->helperText('Only visible to staff.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Customer')
                    ->state(fn (Customer $record): string => $record->displayName())
                    ->description(fn (Customer $record): ?string => $record->type === CustomerType::Company ? $record->user?->name : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('company_name', 'ilike', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"))),
                TextColumn::make('user.email')->label('Email')->copyable(),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('enquiries_count')
                    ->counts(['enquiries' => fn (Builder $query) => $query->where('status', '!=', 'draft')])
                    ->label('Enquiries')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('created_at')->label('Customer since')->date('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(CustomerType::class),
                TrashedFilter::make()->label('Archived'),
            ])
            ->recordActions([EditAction::make()])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'));
    }

    public static function getRelations(): array
    {
        return [
            AddressesRelationManager::class,
            EnquiriesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function isCompany(mixed $type): bool
    {
        return ($type instanceof CustomerType ? $type : CustomerType::tryFrom((string) $type)) === CustomerType::Company;
    }
}
