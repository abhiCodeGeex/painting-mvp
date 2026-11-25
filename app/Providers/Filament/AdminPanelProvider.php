<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Sidebar groups, in the order the work flows: daily sales work first, then the masters
     * in set-up order (what we sell -> what it is made of -> framing -> how it is priced),
     * then reference lists and system. A group only appears once it has at least one item.
     */
    public const GROUP_SALES = 'Sales — enquiries to quotes';

    public const GROUP_INVENTORY = 'Inventory';

    public const GROUP_CATALOG = 'Catalog — what customers choose';

    public const GROUP_MATERIALS = 'Print & materials';

    public const GROUP_FRAMING = 'Framing';

    public const GROUP_PRICING = 'Pricing';

    public const GROUP_PREVIEW = 'Preview';

    public const GROUP_LISTS = 'Reference lists';

    public const GROUP_SYSTEM = 'Users & access';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(config('app.name').' Admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make(self::GROUP_SALES),
                NavigationGroup::make(self::GROUP_INVENTORY),
                NavigationGroup::make(self::GROUP_CATALOG),
                NavigationGroup::make(self::GROUP_MATERIALS),
                NavigationGroup::make(self::GROUP_FRAMING),
                NavigationGroup::make(self::GROUP_PRICING),
                NavigationGroup::make(self::GROUP_PREVIEW),
                NavigationGroup::make(self::GROUP_LISTS)->collapsed(),
                NavigationGroup::make(self::GROUP_SYSTEM)->collapsed(),
            ])
            ->databaseNotifications()
            // Stock warning colours (inventory rows + dashboard stats). Filament ships pre-built CSS,
            // so these few rules are added here rather than through a custom theme.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => <<<'HTML'
                <style>
                    .fi-ta-row.stock-row-low { background-color: color-mix(in oklab, var(--warning-400) 16%, transparent); }
                    .fi-ta-row.stock-row-out { background-color: color-mix(in oklab, var(--danger-400) 16%, transparent); }
                    .fi-ta-row.stock-row-low > td:first-child { box-shadow: inset 4px 0 0 var(--warning-500); }
                    .fi-ta-row.stock-row-out > td:first-child { box-shadow: inset 4px 0 0 var(--danger-500); }
                    .fi-wi-stats-overview-stat.stock-stat-low { background-color: color-mix(in oklab, var(--warning-400) 14%, var(--color-white, #fff)); box-shadow: inset 0 0 0 1px var(--warning-400); }
                    .fi-wi-stats-overview-stat.stock-stat-out { background-color: color-mix(in oklab, var(--danger-400) 14%, var(--color-white, #fff)); box-shadow: inset 0 0 0 1px var(--danger-400); }
                    .dark .fi-wi-stats-overview-stat.stock-stat-low { background-color: color-mix(in oklab, var(--warning-500) 18%, var(--gray-900)); }
                    .dark .fi-wi-stats-overview-stat.stock-stat-out { background-color: color-mix(in oklab, var(--danger-500) 18%, var(--gray-900)); }
                    .fi-wi-stats-overview-stat.stock-stat-low .fi-wi-stats-overview-stat-value { color: var(--warning-600); }
                    .fi-wi-stats-overview-stat.stock-stat-out .fi-wi-stats-overview-stat-value { color: var(--danger-600); }
                    .dark .fi-wi-stats-overview-stat.stock-stat-low .fi-wi-stats-overview-stat-value { color: var(--warning-400); }
                    .dark .fi-wi-stats-overview-stat.stock-stat-out .fi-wi-stats-overview-stat-value { color: var(--danger-400); }
                </style>
                HTML)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup(self::GROUP_SYSTEM),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
