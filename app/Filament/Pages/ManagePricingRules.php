<?php

namespace App\Filament\Pages;

use App\Enums\Rounding;
use App\Filament\Support\MasterForm;
use App\Providers\Filament\AdminPanelProvider;
use App\Settings\PricingSettings;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Pricing rules used by the pricing engine (spec §7). One record, edited in place.
 */
class ManagePricingRules extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = PricingSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_PRICING;

    protected static ?string $navigationLabel = 'Pricing rules';

    protected static ?string $title = 'Pricing rules';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'pricing/rules';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Margin')
                ->description('Material costs are marked up to get the selling price. Artist fees are set on each artist; labour is entered on each quote line.')
                ->columns(3)
                ->schema([
                    MasterForm::percent('default_markup_percent', 'Default markup')->maxValue(1000)->required()
                        ->helperText('Cost × (1 + markup) = selling price.'),
                ]),

            Section::make('Quantity discounts')
                ->description('Applied per line, based on that line\'s quantity. The highest matching break wins.')
                ->schema([
                    Repeater::make('quantity_breaks')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('min_qty')->label('From quantity')->integer()->minValue(2)->required(),
                            MasterForm::percent('discount_percent', 'Discount')->required(),
                        ])
                        ->columns(2)
                        ->addActionLabel('Add quantity break')
                        ->reorderable(false)
                        ->defaultItems(0),
                ]),

            Section::make('Rush orders & rounding')
                ->columns(3)
                ->schema([
                    MasterForm::percent('rush_fee_percent', 'Rush fee')->required(),
                    TextInput::make('rush_threshold_days')
                        ->label('Rush if deadline is within')
                        ->integer()->minValue(0)->suffix('days')->required(),
                    Select::make('rounding')
                        ->label('Round unit prices to')
                        ->options(Rounding::class)
                        ->native(false)
                        ->required(),
                ]),

            Section::make('Customer estimate & measurements')
                ->columns(3)
                ->schema([
                    MasterForm::percent('estimate_range_percent', 'Estimate range ±')->required()
                        ->helperText('The customer sees e.g. ₹8,500 – ₹11,500.'),
                    MasterForm::mm('mat_overlap_mm', 'Mat overlap per side')->required()
                        ->helperText('How far the mat covers the artwork edge.'),
                    MasterForm::mm('rebate_allowance_mm', 'Frame rebate allowance per side')->required()
                        ->helperText('Clearance so the artwork fits the frame.'),
                ]),
        ]);
    }

    /**
     * Form inputs arrive as strings; the settings class has typed properties.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['default_markup_percent', 'rush_fee_percent', 'estimate_range_percent'] as $key) {
            $data[$key] = (float) $data[$key];
        }

        foreach (['rush_threshold_days', 'mat_overlap_mm', 'rebate_allowance_mm'] as $key) {
            $data[$key] = (int) $data[$key];
        }

        $data['rounding'] = $data['rounding'] instanceof Rounding ? $data['rounding']->value : (string) $data['rounding'];

        $data['quantity_breaks'] = collect($data['quantity_breaks'] ?? [])
            ->map(fn (array $row): array => [
                'min_qty' => (int) $row['min_qty'],
                'discount_percent' => (float) $row['discount_percent'],
            ])
            ->sortBy('min_qty')
            ->values()
            ->all();

        return $data;
    }
}
